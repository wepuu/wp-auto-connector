<?php
/**
 * Permission-aware draft featured-image assignment.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements the frozen Phase 1.4.4 featured-image workflow. */
final class MediaFeaturedService {
	private const ABILITY = 'wp-auto/media-set-featured';

	/**
	 * Private bounded media audit store.
	 *
	 * @var MediaMutationAuditStore
	 */
	private MediaMutationAuditStore $audit;

	/**
	 * Create the service with an optional audit dependency.
	 *
	 * @param MediaMutationAuditStore|null $audit Private audit store.
	 */
	public function __construct( ?MediaMutationAuditStore $audit = null ) {
		$this->audit = $audit ?? new MediaMutationAuditStore();
	}

	/**
	 * Set one supported image as the featured image of a draft Post/Page.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function set_featured( $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$state_may_have_changed = false;
		try {
			$actor_id = get_current_user_id();
			if ( $actor_id < 1 || ! current_user_can( 'upload_files' ) ) {
				return $this->content_not_found();
			}

			$target = $this->authorized_target( $normalized['target_id'] );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$media = $this->authorized_media( $normalized['media_id'] );
			if ( is_wp_error( $media ) ) {
				return $media;
			}

			$current_featured = (int) get_post_thumbnail_id( $target->ID );
			if ( $current_featured === $media->ID ) {
				return $this->output( $target, $media->ID, false );
			}

			// Re-fetch and re-authorize immediately before the Core relationship write.
			$current_target = $this->authorized_target( $normalized['target_id'] );
			if ( is_wp_error( $current_target ) ) {
				return $current_target;
			}
			$current_media = $this->authorized_media( $normalized['media_id'] );
			if ( is_wp_error( $current_media ) ) {
				return $current_media;
			}
			if ( get_current_user_id() !== $actor_id || ! current_user_can( 'upload_files' ) ) {
				return $this->content_not_found();
			}

			$current_featured = (int) get_post_thumbnail_id( $current_target->ID );
			if ( $current_featured === $current_media->ID ) {
				return $this->output( $current_target, $current_media->ID, false );
			}
			if ( $current_featured !== $normalized['expected_featured_media_id'] ) {
				return $this->featured_conflict();
			}

			$snapshot               = $this->protected_snapshot( $current_target );
			$state_may_have_changed = true;
			$core_result            = set_post_thumbnail( $current_target->ID, $current_media->ID );

			$final = get_post( $current_target->ID );
			if ( ! $final instanceof WP_Post || ! $this->same_protected_state( $final, $snapshot ) ) {
				return $this->uncertain();
			}

			$final_featured = (int) get_post_thumbnail_id( $final->ID );
			if ( $final_featured === $current_media->ID ) {
				$event = array(
					'version'                    => 1,
					'operation'                  => 'set_featured',
					'ability'                    => self::ABILITY,
					'actor_user_id'              => $actor_id,
					'target_object_id'           => $final->ID,
					'timestamp_gmt'              => current_time( 'mysql', true ),
					'expected_featured_media_id' => $normalized['expected_featured_media_id'],
					'result_featured_media_id'   => $final_featured,
				);
				if ( ! $this->audit->append( $final->ID, $event ) ) {
					return $this->uncertain();
				}

				return $this->output( $final, $final_featured, true );
			}

			// A false/error result with the expected relation still present is a
			// proven no-op at the Core boundary; any other relation is uncertain.
			if ( ( false === $core_result || is_wp_error( $core_result ) ) && $final_featured === $current_featured ) {
				return $this->update_failed();
			}
			return $this->uncertain();
		} catch ( \Throwable ) {
			return $state_may_have_changed ? $this->uncertain() : $this->update_failed();
		}
	}

	/**
	 * Strictly validate the public request.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, int>|WP_Error
	 */
	private function normalize_input( $input ) {
		$required = array( 'target_id', 'media_id', 'expected_featured_media_id' );
		if ( ! is_array( $input ) || count( $input ) !== count( $required ) || array_diff( array_keys( $input ), $required )
			|| array_diff( $required, array_keys( $input ) )
			|| ! is_int( $input['target_id'] ) || $input['target_id'] < 1
			|| ! is_int( $input['media_id'] ) || $input['media_id'] < 1
			|| ! is_int( $input['expected_featured_media_id'] ) || $input['expected_featured_media_id'] < 0 ) {
			return $this->invalid_request();
		}

		return array(
			'target_id'                  => $input['target_id'],
			'media_id'                   => $input['media_id'],
			'expected_featured_media_id' => $input['expected_featured_media_id'],
		);
	}

	/**
	 * Resolve an authorized draft Post/Page without disclosing existence.
	 *
	 * @param int $post_id Target ID.
	 * @return WP_Post|WP_Error
	 */
	private function authorized_target( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'draft' !== $post->post_status ) {
			return $this->content_not_found();
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! isset( $post_type->cap->edit_posts ) || ! is_string( $post_type->cap->edit_posts )
			|| ! current_user_can( $post_type->cap->edit_posts )
			|| ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->content_not_found();
		}

		return $post;
	}

	/**
	 * Resolve one supported image attachment with read authorization.
	 *
	 * @param int $media_id Attachment ID.
	 * @return WP_Post|WP_Error
	 */
	private function authorized_media( int $media_id ) {
		$media = get_post( $media_id );
		if ( ! $media instanceof WP_Post
			|| 'attachment' !== $media->post_type
			|| 'inherit' !== $media->post_status
			|| ! in_array( $media->post_mime_type, MediaReadContract::MIME_TYPES, true )
			|| ! current_user_can( 'read_post', $media->ID ) ) {
			return $this->media_not_found();
		}

		return $media;
	}

	/**
	 * Snapshot every target row field that the relationship operation must preserve.
	 *
	 * @param WP_Post $post Authorized draft target.
	 * @return array<string, int|string>
	 */
	private function protected_snapshot( WP_Post $post ): array {
		$fields   = array(
			'ID',
			'post_type',
			'post_status',
			'post_name',
			'post_title',
			'post_excerpt',
			'post_content',
			'post_author',
			'post_parent',
			'post_date',
			'post_date_gmt',
			'post_modified_gmt',
			'post_password',
			'comment_status',
			'ping_status',
			'menu_order',
			'post_mime_type',
			'guid',
			'to_ping',
			'pinged',
			'post_content_filtered',
		);
		$snapshot = array();
		foreach ( $fields as $field ) {
			$snapshot[ $field ] = $post->{$field};
		}

		return $snapshot;
	}

	/**
	 * Verify all protected target row fields are unchanged.
	 *
	 * @param WP_Post                   $post Final target.
	 * @param array<string, int|string> $snapshot Protected state.
	 */
	private function same_protected_state( WP_Post $post, array $snapshot ): bool {
		foreach ( $snapshot as $field => $value ) {
			if ( ! isset( $post->{$field} ) || $post->{$field} !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the exact assignment result.
	 *
	 * @param WP_Post $target Target draft.
	 * @param int     $media_id Featured image ID.
	 * @param bool    $changed Whether the relation changed.
	 * @return array<string, int|string|bool>
	 */
	private function output( WP_Post $target, int $media_id, bool $changed ): array {
		return array(
			'target_id'         => (int) $target->ID,
			'target_type'       => (string) $target->post_type,
			'status'            => (string) $target->post_status,
			'featured_media_id' => $media_id,
			'changed'           => $changed,
		);
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the existence-hiding target error. */
	private function content_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_content_not_found', __( 'The requested content was not found.', 'wp-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the existence-hiding media error. */
	private function media_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_media_not_found', __( 'The requested media was not found.', 'wp-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the stale featured relationship error. */
	private function featured_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_featured_media_conflict', __( 'The featured image changed after it was read.', 'wp-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the proven-unapplied assignment error. */
	private function update_failed(): WP_Error {
		return new WP_Error( 'wp_auto_featured_media_update_failed', __( 'The featured image could not be assigned.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the possible-partial-state error. */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_media_state_uncertain', __( 'The media operation state could not be confirmed.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}
}
