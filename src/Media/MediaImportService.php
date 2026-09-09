<?php
/**
 * Secure caller-triggered remote image import.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements the frozen Phase 1.4.5 remote import lifecycle. */
final class MediaImportService {
	private const ABILITY           = 'wp-auto/media-import-url';
	private const MAX_URL_LENGTH    = 2048;
	private const MAX_FILENAME_SIZE = 255;

	/** Durable ingestion claim store.
	 *
	 * @var MediaIngestionIdempotencyStore
	 */
	private MediaIngestionIdempotencyStore $idempotency;
	/** Private media audit store.
	 *
	 * @var MediaMutationAuditStore
	 */
	private MediaMutationAuditStore $audit;
	/** Canonical media reader.
	 *
	 * @var MediaReadService
	 */
	private MediaReadService $reader;
	/** URL policy.
	 *
	 * @var RemoteUrlPolicy
	 */
	private RemoteUrlPolicy $policy;
	/** Bounded remote downloader.
	 *
	 * @var RemoteMediaDownloaderInterface
	 */
	private RemoteMediaDownloaderInterface $downloader;

	/**
	 * Create the service with injectable dependencies for isolated tests.
	 *
	 * @param MediaIngestionIdempotencyStore|null $idempotency Durable claim store.
	 * @param MediaMutationAuditStore|null        $audit Private audit store.
	 * @param MediaReadService|null               $reader Canonical reader.
	 * @param RemoteUrlPolicy|null                $policy URL policy.
	 * @param RemoteMediaDownloaderInterface|null $downloader Bounded downloader.
	 */
	public function __construct( ?MediaIngestionIdempotencyStore $idempotency = null, ?MediaMutationAuditStore $audit = null, ?MediaReadService $reader = null, ?RemoteUrlPolicy $policy = null, ?RemoteMediaDownloaderInterface $downloader = null ) {
		$this->idempotency = $idempotency ?? new MediaIngestionIdempotencyStore();
		$this->audit       = $audit ?? new MediaMutationAuditStore();
		$this->reader      = $reader ?? new MediaReadService();
		$this->policy      = $policy ?? new RemoteUrlPolicy();
		$this->downloader  = $downloader ?? new RemoteMediaDownloader( $this->policy );
	}

	/**
	 * Import one validated image from a caller-selected public URL.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function import( $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$actor_id = get_current_user_id();
		if ( $actor_id < 1 || ! current_user_can( 'upload_files' ) ) {
			return $this->create_failed();
		}
		if ( is_wp_error( $this->authorized_parent( $normalized['parent_id'] ) ) ) {
			return $this->content_not_found();
		}

		$fingerprint = $this->fingerprint( $normalized['url'], $normalized['filename'], $normalized['parent_id'] );
		try {
			$claim = $this->idempotency->claim( self::ABILITY, $actor_id, $normalized['idempotency_key'], $fingerprint );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
		if ( ! isset( $claim['status'] ) || ! in_array( $claim['status'], array( 'claimed', 'existing', 'unresolved' ), true ) ) {
			return $this->uncertain();
		}
		if ( 'existing' === $claim['status'] ) {
			if ( ! isset( $claim['name'], $claim['record'] ) || ! is_string( $claim['name'] ) ) {
				return $this->uncertain();
			}
			try {
				return $this->handle_existing_claim( $claim['name'], $claim['record'], $actor_id, $fingerprint, $normalized['parent_id'] );
			} catch ( \Throwable ) {
				return $this->uncertain();
			}
		}
		if ( 'unresolved' === $claim['status'] || ! isset( $claim['name'], $claim['record'] ) || ! is_string( $claim['name'] ) || ! is_array( $claim['record'] ) ) {
			return $this->uncertain();
		}

		return $this->execute_claimed_import( $normalized, $actor_id, $fingerprint, $claim['name'], $claim['record'] );
	}

	/**
	 * Execute the network and Core work for the sole acquired owner.
	 *
	 * @param array<string, mixed> $normalized Normalized input.
	 * @param int                  $actor_id Actor user ID.
	 * @param string               $fingerprint Canonical request fingerprint.
	 * @param string               $option_name Claim option name.
	 * @param array<string, mixed> $record Initial claim record.
	 * @return array<string, mixed>|WP_Error
	 */
	private function execute_claimed_import( array $normalized, int $actor_id, string $fingerprint, string $option_name, array $record ) {
		$temp_file = null;
		try {
			$download = $this->downloader->download( $normalized['url'] );
			if ( is_wp_error( $download ) || ! is_array( $download ) || ! isset( $download['path'] ) || ! is_string( $download['path'] ) || '' === $download['path'] ) {
				return $this->release_deterministic_claim( $option_name, $record, null, $this->remote_rejected() );
			}
			$temp_file = $download['path'];

			if ( ! $this->is_valid_image_file( $temp_file, $normalized['filename'], $actor_id ) ) {
				return $this->release_deterministic_claim( $option_name, $record, $temp_file, $this->invalid_request() );
			}
			if ( get_current_user_id() !== $actor_id || ! current_user_can( 'upload_files' ) ) {
				return $this->release_deterministic_claim( $option_name, $record, $temp_file, $this->create_failed() );
			}
			if ( is_wp_error( $this->authorized_parent( $normalized['parent_id'] ) ) ) {
				return $this->release_deterministic_claim( $option_name, $record, $temp_file, $this->content_not_found() );
			}

			$this->load_media_functions();
			$post_id = media_handle_sideload(
				array(
					'name'     => $normalized['filename'],
					'tmp_name' => $temp_file,
				),
				$normalized['parent_id']
			);
			if ( is_wp_error( $post_id ) || ! is_int( $post_id ) || $post_id < 1 ) {
				$this->cleanup_temp( $temp_file );
				return $this->uncertain();
			}
			if ( ! $this->cleanup_temp( $temp_file ) ) {
				return $this->uncertain();
			}
			$temp_file = null;

			if ( ! $this->idempotency->record_target_in_progress( $option_name, $record, $post_id ) ) {
				return $this->uncertain();
			}
			$record['target_id'] = $post_id;

			$output = $this->verified_output( $post_id, $actor_id, $normalized['parent_id'], false );
			if ( is_wp_error( $output ) ) {
				return $output;
			}
			$event = array(
				'version'          => 1,
				'operation'        => 'import_url',
				'ability'          => self::ABILITY,
				'actor_user_id'    => $actor_id,
				'target_object_id' => $post_id,
				'timestamp_gmt'    => current_time( 'mysql', true ),
				'fingerprint'      => $fingerprint,
			);
			if ( ! $this->audit->append( $post_id, $event ) ) {
				return $this->uncertain();
			}
			if ( ! $this->idempotency->mark_audit_recorded( $option_name, $record ) ) {
				return $this->uncertain();
			}
			$record['state'] = 'audit_recorded';
			if ( ! $this->idempotency->complete( $option_name, $record ) ) {
				return $this->uncertain();
			}

			return $output;
		} catch ( \Throwable ) {
			if ( null !== $temp_file ) {
				$this->cleanup_temp( $temp_file );
			}
			return $this->uncertain();
		}
	}

	/**
	 * Normalize strict import input and canonicalize the URL.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		$allowed = array( 'url', 'filename', 'idempotency_key', 'parent_id' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $allowed ) || ! array_key_exists( 'url', $input ) || ! array_key_exists( 'filename', $input ) || ! array_key_exists( 'idempotency_key', $input ) || ! is_string( $input['url'] ) || ! is_string( $input['filename'] ) || ! is_string( $input['idempotency_key'] ) || ( isset( $input['parent_id'] ) && ( ! is_int( $input['parent_id'] ) || $input['parent_id'] < 1 ) ) ) {
			return $this->invalid_request();
		}
		if ( ! $this->length_between( $input['url'], 1, self::MAX_URL_LENGTH ) || ! $this->length_between( $input['filename'], 1, self::MAX_FILENAME_SIZE ) || ! $this->length_between( $input['idempotency_key'], 16, 128 ) || str_contains( $input['filename'], '/' ) || str_contains( $input['filename'], '\\' ) || str_contains( $input['filename'], "\0" ) ) {
			return $this->invalid_request();
		}

		$filename  = sanitize_file_name( $input['filename'] );
		$canonical = $this->policy->normalize( $input['url'] );
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( '' === $filename || '.' === $filename || '..' === $filename || wp_basename( $filename ) !== $filename || is_wp_error( $canonical ) || ! in_array( $extension, array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif' ), true ) ) {
			return is_wp_error( $canonical ) ? $canonical : $this->invalid_request();
		}

		return array(
			'url'             => $canonical,
			'filename'        => $filename,
			'idempotency_key' => $input['idempotency_key'],
			'parent_id'       => $input['parent_id'] ?? 0,
		);
	}

	/**
	 * Return an editable Post/Page draft parent or zero.
	 *
	 * @param int $parent_id Optional parent ID.
	 * @return int|WP_Post|WP_Error
	 */
	private function authorized_parent( int $parent_id ) {
		if ( 0 === $parent_id ) {
			return 0;
		}
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post || ! in_array( $parent->post_type, array( 'post', 'page' ), true ) || 'draft' !== $parent->post_status || ! current_user_can( 'edit_post', $parent_id ) ) {
			return $this->content_not_found();
		}
		return $parent;
	}

	/**
	 * Validate the real downloaded file against Core and the fixed MIME set.
	 *
	 * @param string $path Temporary file path.
	 * @param string $filename Proposed sanitized basename.
	 * @param int    $actor_id Actor user ID.
	 */
	private function is_valid_image_file( string $path, string $filename, int $actor_id ): bool {
		$allowed = $this->effective_mimes( $actor_id );
		if ( array() === $allowed ) {
			return false;
		}
		$checked = wp_check_filetype_and_ext( $path, $filename, $allowed );
		if ( ! is_array( $checked ) || ! isset( $checked['ext'], $checked['type'] ) || ! is_string( $checked['ext'] ) || '' === $checked['ext'] || ! is_string( $checked['type'] ) || ! in_array( $checked['type'], MediaReadContract::MIME_TYPES, true ) ) {
			return false;
		}
		if ( isset( $checked['proper_filename'] ) && is_string( $checked['proper_filename'] ) && '' !== $checked['proper_filename'] && $checked['proper_filename'] !== $filename ) {
			return false;
		}
		return wp_get_image_mime( $path ) === $checked['type'];
	}

	/**
	 * Intersect Core's actor policy with the fixed image MIME types.
	 *
	 * @param int $actor_id Actor user ID.
	 * @return array<string, string>
	 */
	private function effective_mimes( int $actor_id ): array {
		$core  = get_allowed_mime_types( $actor_id );
		$fixed = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'avif'         => 'image/avif',
		);
		return array_filter( $fixed, static fn( string $mime ): bool => is_array( $core ) && in_array( $mime, $core, true ) );
	}

	/**
	 * Verify the final attachment and return the exact public record.
	 *
	 * @param int  $post_id Attachment ID.
	 * @param int  $actor_id Actor user ID.
	 * @param int  $parent_id Expected parent ID.
	 * @param bool $replayed Whether this is a replay.
	 * @return array<string, mixed>|WP_Error
	 */
	private function verified_output( int $post_id, int $actor_id, int $parent_id, bool $replayed ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status || (int) $post->post_author !== $actor_id || (int) $post->post_parent !== $parent_id || ! in_array( $post->post_mime_type, MediaReadContract::MIME_TYPES, true ) ) {
			return $this->uncertain();
		}
		$file          = get_attached_file( $post_id );
		$original_file = wp_get_original_image_path( $post_id );
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_string( $original_file ) || '' === $original_file || ! is_file( $original_file ) ) {
			return $this->uncertain();
		}
		$output = $this->reader->get( array( 'id' => $post_id ) );
		if ( is_wp_error( $output ) ) {
			return $this->uncertain();
		}
		return $output + array( 'idempotency_replayed' => $replayed );
	}

	/**
	 * Resolve an existing claim without another network request.
	 *
	 * @param string $option_name Claim option name.
	 * @param mixed  $record Persisted claim.
	 * @param int    $actor_id Actor user ID.
	 * @param string $fingerprint Canonical fingerprint.
	 * @param int    $parent_id Expected parent ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_existing_claim( string $option_name, $record, int $actor_id, string $fingerprint, int $parent_id ) {
		if ( ! is_array( $record ) || self::ABILITY !== ( $record['ability'] ?? null ) || ( $record['actor_user_id'] ?? null ) !== $actor_id || ( $record['fingerprint'] ?? null ) !== $fingerprint ) {
			return $this->idempotency_conflict();
		}
		if ( 'in_progress' === ( $record['state'] ?? null ) ) {
			return $this->idempotency_in_progress();
		}
		$output = $this->verified_output( (int) ( $record['target_id'] ?? 0 ), $actor_id, $parent_id, true );
		if ( is_wp_error( $output ) ) {
			return $this->idempotency_conflict();
		}
		if ( ! $this->audit->has_ingestion_event( (int) $record['target_id'], self::ABILITY, $actor_id, $fingerprint ) ) {
			return 'completed' === $record['state'] ? $this->idempotency_conflict() : $this->uncertain();
		}
		if ( 'audit_recorded' === $record['state'] && ! $this->idempotency->complete( $option_name, $record ) ) {
			return $this->uncertain();
		}
		if ( ! in_array( $record['state'], array( 'audit_recorded', 'completed' ), true ) ) {
			return $this->uncertain();
		}
		return $output;
	}

	/**
	 * Delete the temporary file and release only a deterministic pre-write claim.
	 *
	 * @param string               $option_name Claim option name.
	 * @param array<string, mixed> $record Initial claim record.
	 * @param string|null          $temp_file Owned temporary file.
	 * @param WP_Error             $error Deterministic error.
	 */
	private function release_deterministic_claim( string $option_name, array $record, ?string $temp_file, WP_Error $error ): WP_Error {
		if ( null !== $temp_file && ! $this->cleanup_temp( $temp_file ) ) {
			return $this->uncertain();
		}
		try {
			$released = $this->idempotency->release( $option_name, $record );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
		return is_array( $released ) && 'released' === ( $released['status'] ?? null ) ? $error : $this->uncertain();
	}

	/**
	 * Delete one service-owned temporary file and verify absence.
	 *
	 * @param string $path Temporary file path.
	 */
	private function cleanup_temp( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/** Load Core media functions only when an owner is ready to write. */
	private function load_media_functions(): void {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Build an ambiguity-safe fingerprint without the raw URL or key.
	 *
	 * @param string $url Canonical URL.
	 * @param string $filename Sanitized filename.
	 * @param int    $parent_id Effective parent ID.
	 */
	private function fingerprint( string $url, string $filename, int $parent_id ): string {
		$json = wp_json_encode( array( $url, $filename, $parent_id ), JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', is_string( $json ) ? $json : $url . "\0" . $filename . "\0" . $parent_id );
	}

	/**
	 * Check a character-aware inclusive string bound.
	 *
	 * @param string $value Value to measure.
	 * @param int    $minimum Inclusive minimum.
	 * @param int    $maximum Inclusive maximum.
	 */
	private function length_between( string $value, int $minimum, int $maximum ): bool {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		return $length >= $minimum && $length <= $maximum;
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the stable remote policy error. */
	private function remote_rejected(): WP_Error {
		return new WP_Error( 'wp_auto_remote_media_rejected', __( 'The remote image request was rejected by policy.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the existence-hiding parent error. */
	private function content_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_content_not_found', __( 'The requested content could not be found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the idempotency conflict error. */
	private function idempotency_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_conflict', __( 'The idempotency key conflicts with another request.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the live or unresolved claim error. */
	private function idempotency_in_progress(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_in_progress', __( 'The idempotent request is still in progress.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the proven no-object creation failure. */
	private function create_failed(): WP_Error {
		return new WP_Error( 'wp_auto_media_create_failed', __( 'The image could not be created.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the fail-closed possible-write error. */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_media_state_uncertain', __( 'The media operation may have changed state and requires review.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}
}
