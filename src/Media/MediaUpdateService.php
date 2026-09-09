<?php
/**
 * Permission-aware media metadata updates.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements the frozen Phase 1.4.3 metadata update workflow. */
final class MediaUpdateService {
	private const ABILITY = 'wp-auto/media-update';

	/**
	 * Local bounded attribution store.
	 *
	 * @var MediaMutationAuditStore
	 */
	private MediaMutationAuditStore $audit;

	/**
	 * Canonical full-record reader.
	 *
	 * @var MediaReadService
	 */
	private MediaReadService $reader;

	/**
	 * Create the service with optional testable dependencies.
	 *
	 * @param MediaMutationAuditStore|null $audit Local audit store.
	 * @param MediaReadService|null        $reader Canonical media reader.
	 */
	public function __construct( ?MediaMutationAuditStore $audit = null, ?MediaReadService $reader = null ) {
		$this->audit  = $audit ?? new MediaMutationAuditStore();
		$this->reader = $reader ?? new MediaReadService();
	}

	/**
	 * Update only allowlisted attachment presentation fields.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$state_may_have_changed = false;
		try {
			$actor_id = get_current_user_id();
			if ( $actor_id < 1 || ! current_user_can( 'upload_files' ) ) {
				return $this->media_not_found();
			}

			$initial = $this->authorized_target( $normalized['id'] );
			if ( is_wp_error( $initial ) ) {
				return $initial;
			}

			// Re-fetch immediately before the first Core write. This remains a
			// best-effort second-precision token, not an atomic compare-and-swap.
			$current = $this->authorized_target( $normalized['id'] );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( get_current_user_id() !== $actor_id || ! current_user_can( 'upload_files' ) ) {
				return $this->media_not_found();
			}
			if ( $normalized['expected_modified_gmt'] !== (string) $current->post_modified_gmt ) {
				return $this->media_conflict();
			}

			$snapshot = $this->protected_snapshot( $current, $normalized );
			if ( null === $snapshot ) {
				return $this->media_not_found();
			}

			$post_fields = array_intersect( array( 'title', 'caption', 'description' ), array_keys( $normalized ) );
			if ( array() !== $post_fields ) {
				$token  = hash( 'sha256', self::ABILITY . '|' . $current->ID . '|' . microtime( true ) . '|' . wp_rand() );
				$update = $this->build_update_args( $current->ID, $normalized, $token );
				$guard  = $this->build_invariant_guard( $current->ID, $snapshot['post'], $token );

				add_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX, 4 );
				try {
					$state_may_have_changed = true;
					$result                 = wp_update_post( $update, true, true );
				} finally {
					remove_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX );
				}
				if ( is_wp_error( $result ) || ! is_int( $result ) || $current->ID !== $result ) {
					return $this->update_failed();
				}
			}

			if ( array_key_exists( 'alt_text', $normalized ) ) {
				$state_may_have_changed = true;
				$alt_result             = update_post_meta( $current->ID, '_wp_attachment_image_alt', wp_slash( $normalized['alt_text'] ) );
				$stored_alt             = get_post_meta( $current->ID, '_wp_attachment_image_alt', true );
				if ( ! is_string( $stored_alt ) || $stored_alt !== $normalized['alt_text'] ) {
					return array() !== $post_fields ? $this->uncertain() : $this->update_failed();
				}
				unset( $alt_result ); // Core returns false when the value is unchanged.
			}

			$final = get_post( $current->ID );
			if ( ! $final instanceof WP_Post || ! $this->verify_snapshot( $final, $normalized, $snapshot ) ) {
				return $this->uncertain();
			}

			$output = $this->reader->full_record( $final );
			if ( is_wp_error( $output ) ) {
				return $this->uncertain();
			}

			$event = array(
				'version'               => 1,
				'operation'             => 'update',
				'ability'               => self::ABILITY,
				'actor_user_id'         => $actor_id,
				'target_object_id'      => $final->ID,
				'timestamp_gmt'         => current_time( 'mysql', true ),
				'expected_modified_gmt' => $normalized['expected_modified_gmt'],
				'result_modified_gmt'   => (string) $final->post_modified_gmt,
			);
			if ( ! $this->audit->append( $final->ID, $event ) ) {
				return $this->uncertain();
			}

			return $output;
		} catch ( \Throwable ) {
			return $state_may_have_changed ? $this->uncertain() : $this->update_failed();
		}
	}

	/**
	 * Strictly validate the public request.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}
		$mutable = array( 'title', 'alt_text', 'caption', 'description' );
		$allowed = array_merge( array( 'id', 'expected_modified_gmt' ), $mutable );
		if ( array_diff( array_keys( $input ), $allowed )
			|| ! array_key_exists( 'id', $input )
			|| ! array_key_exists( 'expected_modified_gmt', $input )
			|| ! is_int( $input['id'] ) || $input['id'] < 1
			|| ! is_string( $input['expected_modified_gmt'] )
			|| array() === array_intersect( $mutable, array_keys( $input ) )
			|| ! $this->valid_timestamp( $input['expected_modified_gmt'] )
		) {
			return $this->invalid_request();
		}
		$limits = array(
			'title'       => 200,
			'alt_text'    => 2000,
			'caption'     => 50000,
			'description' => 100000,
		);
		foreach ( $limits as $field => $maximum ) {
			if ( array_key_exists( $field, $input ) && ( ! is_string( $input[ $field ] ) || $this->string_length( $input[ $field ] ) > $maximum ) ) {
				return $this->invalid_request();
			}
		}
		return $input;
	}

	/**
	 * Resolve a supported target without disclosing its existence.
	 *
	 * @param int $post_id Attachment ID.
	 * @return WP_Post|WP_Error
	 */
	private function authorized_target( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post
			|| 'attachment' !== $post->post_type
			|| 'inherit' !== $post->post_status
			|| ! in_array( $post->post_mime_type, MediaReadContract::MIME_TYPES, true )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return $this->media_not_found();
		}
		return $post;
	}

	/**
	 * Snapshot protected row, file, and omitted-field state.
	 *
	 * @param WP_Post              $post Authorized attachment.
	 * @param array<string, mixed> $input Normalized input.
	 * @return array<string, mixed>|null
	 */
	private function protected_snapshot( WP_Post $post, array $input ): ?array {
		$file     = get_attached_file( $post->ID );
		$original = wp_get_original_image_path( $post->ID );
		$alt      = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		if ( ! is_string( $file ) || '' === $file || ! is_string( $original ) || '' === $original || ! is_string( $alt ) ) {
			return null;
		}
		$fields  = array( 'ID', 'post_type', 'post_status', 'post_name', 'post_author', 'post_parent', 'post_date', 'post_date_gmt', 'post_password', 'comment_status', 'ping_status', 'menu_order', 'post_mime_type', 'guid', 'to_ping', 'pinged', 'post_content_filtered' );
		$mapping = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);
		foreach ( $mapping as $public => $core ) {
			if ( ! array_key_exists( $public, $input ) ) {
				$fields[] = $core;
			}
		}
		$row = array();
		foreach ( $fields as $field ) {
			$row[ $field ] = $post->{$field};
		}
		return array(
			'post'     => $row,
			'file'     => $file,
			'original' => $original,
			'alt_text' => array_key_exists( 'alt_text', $input ) ? null : $alt,
		);
	}

	/**
	 * Verify every protected value against the final state.
	 *
	 * @param WP_Post              $post Final attachment.
	 * @param array<string, mixed> $input Normalized input.
	 * @param array<string, mixed> $snapshot Protected state.
	 */
	private function verify_snapshot( WP_Post $post, array $input, array $snapshot ): bool {
		foreach ( $snapshot['post'] as $field => $value ) {
			if ( $post->{$field} !== $value ) {
				return false;
			}
		}
		if ( get_attached_file( $post->ID ) !== $snapshot['file'] || wp_get_original_image_path( $post->ID ) !== $snapshot['original'] ) {
			return false;
		}
		$alt = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		return is_string( $alt ) && ( array_key_exists( 'alt_text', $input ) ? $alt === $input['alt_text'] : $alt === $snapshot['alt_text'] );
	}

	/**
	 * Build only allowlisted Core post arguments and a private marker.
	 *
	 * @param int                  $post_id Attachment ID.
	 * @param array<string, mixed> $input Normalized input.
	 * @param string               $token Operation marker.
	 * @return array<string, mixed>
	 */
	private function build_update_args( int $post_id, array $input, string $token ): array {
		$args    = array(
			'ID'                            => $post_id,
			'wp_auto_connector_guard_token' => $token,
		);
		$mapping = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);
		foreach ( $mapping as $public => $core ) {
			if ( array_key_exists( $public, $input ) ) {
				$args[ $core ] = wp_slash( $input[ $public ] );
			}
		}
		return $args;
	}

	/**
	 * Build the operation-scoped row invariant guard.
	 *
	 * @param int                       $post_id Attachment ID.
	 * @param array<string, int|string> $snapshot Protected row fields.
	 * @param string                    $token Operation marker.
	 */
	private function build_invariant_guard( int $post_id, array $snapshot, string $token ): callable {
		return static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $post_id, $snapshot, $token ): array {
			if ( ! $update || (int) ( $unsanitized_postarr['ID'] ?? 0 ) !== $post_id || ( $unsanitized_postarr['wp_auto_connector_guard_token'] ?? null ) !== $token ) {
				return $data;
			}
			foreach ( $snapshot as $field => $value ) {
				if ( 'ID' !== $field ) {
					$data[ $field ] = is_string( $value ) ? wp_slash( $value ) : $value;
				}
			}
			return $data;
		};
	}

	/**
	 * Validate the Core sentinel or one real Gregorian GMT timestamp.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
		if ( '0000-00-00 00:00:00' === $value ) {
			return true;
		}
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return false;
		}
		$year = (int) substr( $value, 0, 4 );
		return $year > 0
			&& checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), $year )
			&& (int) substr( $value, 11, 2 ) < 24
			&& (int) substr( $value, 14, 2 ) < 60
			&& (int) substr( $value, 17, 2 ) < 60;
	}

	/**
	 * Return a character-aware string length.
	 *
	 * @param string $value Value to measure.
	 */
	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the existence-hiding media error. */
	private function media_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_media_not_found', __( 'The requested media was not found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the stale-write error. */
	private function media_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_media_conflict', __( 'The media changed after it was read.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the proven-unapplied update error. */
	private function update_failed(): WP_Error {
		return new WP_Error( 'wp_auto_media_update_failed', __( 'The media metadata could not be updated.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the possible-partial-state error. */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_media_state_uncertain', __( 'The media operation state could not be confirmed.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}
}
