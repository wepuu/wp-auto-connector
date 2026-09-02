<?php
/**
 * Permission-aware Post/Page draft creation.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the shared Phase 1.3.1 Core mutation workflow.
 */
final class ContentMutationService {
	/**
	 * Persistent claim store used by Create operations.
	 *
	 * @var CreateIdempotencyStore
	 */
	private CreateIdempotencyStore $idempotency;

	/**
	 * Local bounded attribution store.
	 *
	 * @var MutationAuditStore
	 */
	private MutationAuditStore $audit;

	/**
	 * Create the service with optional stores for isolated tests.
	 *
	 * @param CreateIdempotencyStore|null $idempotency Persistent claim store.
	 * @param MutationAuditStore|null     $audit Local audit store.
	 */
	public function __construct( ?CreateIdempotencyStore $idempotency = null, ?MutationAuditStore $audit = null ) {
		$this->idempotency = $idempotency ?? new CreateIdempotencyStore();
		$this->audit       = $audit ?? new MutationAuditStore();
	}

	/**
	 * Check the fixed post type's actual create capability.
	 *
	 * @param string $post_type Fixed post type.
	 */
	public function can_create( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object
			&& isset( $post_type_object->cap->create_posts )
			&& current_user_can( $post_type_object->cap->create_posts );
	}

	/**
	 * Check the fixed post type's actual edit baseline capability.
	 *
	 * @param string $post_type Fixed post type.
	 */
	public function can_update( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object
			&& isset( $post_type_object->cap->edit_posts )
			&& current_user_can( $post_type_object->cap->edit_posts );
	}

	/**
	 * Create a Post draft.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_post_draft( $input ) {
		return $this->create_draft( 'post', 'wp-auto/post-create-draft', $input );
	}

	/**
	 * Create a root Page draft.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_page_draft( $input ) {
		return $this->create_draft( 'page', 'wp-auto/page-create-draft', $input );
	}

	/**
	 * Update a Post draft.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_post_draft( $input ) {
		return $this->update_draft( 'post', 'wp-auto/post-update', $input );
	}

	/**
	 * Update a Page draft.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_page_draft( $input ) {
		return $this->update_draft( 'page', 'wp-auto/page-update', $input );
	}

	/**
	 * Run the common, fail-closed create workflow.
	 *
	 * @param string $post_type Fixed post type.
	 * @param string $ability Ability name.
	 * @param mixed  $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_draft( string $post_type, string $ability, $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$actor_id = get_current_user_id();
		if ( $actor_id < 1 || ! $this->can_create( $post_type ) ) {
			return $this->create_failed();
		}

		$fingerprint = $this->fingerprint( $normalized );
		try {
			$claim = $this->idempotency->claim(
				$ability,
				$actor_id,
				$normalized['idempotency_key'],
				$fingerprint
			);
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
				return $this->handle_existing_claim( $claim['name'], $claim['record'], $post_type, $ability, $actor_id, $fingerprint );
			} catch ( \Throwable ) {
				return $this->uncertain();
			}
		}

		if ( 'unresolved' === $claim['status'] ) {
			return $this->uncertain();
		}
		if ( ! isset( $claim['name'], $claim['record'] ) || ! is_string( $claim['name'] ) || ! is_array( $claim['record'] ) || ! $this->valid_record( $claim['record'] ) ) {
			return $this->uncertain();
		}

		try {
			$option_name = $claim['name'];
			$record      = $claim['record'];
			$token       = hash( 'sha256', $option_name . '|' . microtime( true ) . '|' . wp_rand() );
			$insert      = $this->build_insert_args( $post_type, $actor_id, $normalized, $token );
			$guard       = $this->build_invariant_guard( $post_type, $actor_id, $token );
			$post_id     = 0;

			add_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX, 4 );
			try {
				$post_id = wp_insert_post( $insert, true, true );
			} finally {
				remove_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX );
			}
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		if ( is_wp_error( $post_id ) || ! is_int( $post_id ) || $post_id < 1 ) {
			try {
				$released = $this->idempotency->release( $option_name );
			} catch ( \Throwable ) {
				return $this->uncertain();
			}
			if ( ! $released ) {
				return $this->uncertain();
			}

			return $this->create_failed();
		}

		try {
			if ( ! $this->idempotency->record_target_in_progress( $option_name, $record, $post_id ) ) {
				return $this->uncertain();
			}
			$record['state']     = 'in_progress';
			$record['target_id'] = $post_id;

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || ! $this->verify_invariants( $post, $post_type, $actor_id ) ) {
				return $this->uncertain();
			}

			$output = $this->output( $post, $post_type, false );
			if ( is_wp_error( $output ) ) {
				return $output;
			}

			$event = array(
				'version'          => 1,
				'operation'        => 'create',
				'ability'          => $ability,
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
			return $this->uncertain();
		}
	}

	/**
	 * Run the common, fail-closed update workflow.
	 *
	 * @param string $post_type Fixed post type.
	 * @param string $ability Ability name.
	 * @param mixed  $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function update_draft( string $post_type, string $ability, $input ) {
		$normalized = $this->normalize_update_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$actor_id = get_current_user_id();
		if ( $actor_id < 1 || ! $this->can_update( $post_type ) ) {
			return $this->content_not_found();
		}

		$initial = $this->authorized_update_target( $normalized['id'], $post_type );
		if ( is_wp_error( $initial ) ) {
			return $initial;
		}
		if ( 'draft' !== $initial->post_status ) {
			return $this->content_status_conflict();
		}
		if ( $this->is_empty_content_after_update( $initial, $normalized ) ) {
			return $this->invalid_request();
		}

		// Re-fetch immediately before the write so authorization, status, content,
		// and the raw concurrency token are evaluated against the latest object.
		$current = $this->authorized_update_target( $normalized['id'], $post_type );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 'draft' !== $current->post_status ) {
			return $this->content_status_conflict();
		}
		if ( $this->is_empty_content_after_update( $current, $normalized ) ) {
			return $this->invalid_request();
		}
		if ( $normalized['expected_modified_gmt'] !== (string) $current->post_modified_gmt ) {
			return $this->content_conflict();
		}

		$snapshot = $this->protected_snapshot( $current, $normalized );
		$token    = hash( 'sha256', $post_type . '|' . $current->ID . '|' . microtime( true ) . '|' . wp_rand() );
		$update   = $this->build_update_args( $current->ID, $normalized, $token );
		$guard    = $this->build_update_invariant_guard( $current->ID, $snapshot, $token );

		add_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX, 4 );
		try {
			try {
				$result = wp_update_post( $update, true, true );
			} catch ( \Throwable ) {
				return $this->uncertain();
			}
		} finally {
			remove_filter( 'wp_insert_post_data', $guard, PHP_INT_MAX );
		}

		if ( is_wp_error( $result ) || ! is_int( $result ) || $current->ID !== $result ) {
			return $this->update_failed();
		}

		try {
			$final = get_post( $current->ID );
			if ( ! $final instanceof WP_Post || $snapshot !== $this->protected_snapshot( $final, $normalized ) ) {
				return $this->uncertain();
			}

			$output = $this->update_output( $final, $post_type );
			if ( is_wp_error( $output ) ) {
				return $output;
			}

			$event = array(
				'version'               => 1,
				'operation'             => 'update',
				'ability'               => $ability,
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
			return $this->uncertain();
		}
	}

	/**
	 * Normalize and semantically validate Update input.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_update_input( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}

		$mutable = array( 'title', 'content', 'excerpt', 'slug' );
		$allowed = array_merge( array( 'id', 'expected_modified_gmt' ), $mutable );
		if ( array_diff( array_keys( $input ), $allowed )
			|| ! array_key_exists( 'id', $input )
			|| ! array_key_exists( 'expected_modified_gmt', $input )
			|| ! is_int( $input['id'] )
			|| $input['id'] < 1
			|| ! is_string( $input['expected_modified_gmt'] )
			|| array() === array_intersect( $mutable, array_keys( $input ) )
			|| ! $this->valid_expected_modified_gmt( $input['expected_modified_gmt'] )
		) {
			return $this->invalid_request();
		}

		$limits = array(
			'title'   => array( 0, 500 ),
			'content' => array( 0, 1000000 ),
			'excerpt' => array( 0, 50000 ),
			'slug'    => array( 1, 200 ),
		);
		foreach ( $limits as $field => $bounds ) {
			if ( array_key_exists( $field, $input )
				&& ( ! is_string( $input[ $field ] ) || ! $this->length_between( $input[ $field ], $bounds[0], $bounds[1] ) )
			) {
				return $this->invalid_request();
			}
		}

		return $input;
	}

	/**
	 * Validate the frozen sentinel or one real Gregorian GMT timestamp.
	 *
	 * @param string $value Raw concurrency token.
	 */
	private function valid_expected_modified_gmt( string $value ): bool {
		if ( '0000-00-00 00:00:00' === $value ) {
			return true;
		}
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return false;
		}

		$year   = (int) substr( $value, 0, 4 );
		$month  = (int) substr( $value, 5, 2 );
		$day    = (int) substr( $value, 8, 2 );
		$hour   = (int) substr( $value, 11, 2 );
		$minute = (int) substr( $value, 14, 2 );
		$second = (int) substr( $value, 17, 2 );

		return $year > 0 && checkdate( $month, $day, $year ) && $hour < 24 && $minute < 60 && $second < 60;
	}

	/**
	 * Resolve a target without disclosing missing, wrong-type, or denied objects.
	 *
	 * @param int    $post_id Target object ID.
	 * @param string $post_type Fixed post type.
	 * @return WP_Post|WP_Error
	 */
	private function authorized_update_target( int $post_id, string $post_type ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->content_not_found();
		}

		return $post;
	}

	/**
	 * Match Core's supported Post/Page empty-content semantics.
	 *
	 * @param WP_Post              $post Current stored object.
	 * @param array<string, mixed> $input Normalized input.
	 */
	private function is_empty_content_after_update( WP_Post $post, array $input ): bool {
		$title   = array_key_exists( 'title', $input ) ? $input['title'] : $post->post_title;
		$content = array_key_exists( 'content', $input ) ? $input['content'] : $post->post_content;
		$excerpt = array_key_exists( 'excerpt', $input ) ? $input['excerpt'] : $post->post_excerpt;

		return ! $content
			&& ! $title
			&& ! $excerpt
			&& post_type_supports( $post->post_type, 'editor' )
			&& post_type_supports( $post->post_type, 'title' )
			&& post_type_supports( $post->post_type, 'excerpt' );
	}

	/**
	 * Build only the allowlisted Core arguments plus a private guard marker.
	 *
	 * @param int                  $post_id Target ID.
	 * @param array<string, mixed> $input Normalized input.
	 * @param string               $token Operation marker.
	 * @return array<string, mixed>
	 */
	private function build_update_args( int $post_id, array $input, string $token ): array {
		$update  = array(
			'ID'                            => $post_id,
			'wp_auto_connector_guard_token' => $token,
		);
		$mapping = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);
		foreach ( $mapping as $public => $core ) {
			if ( array_key_exists( $public, $input ) ) {
				$update[ $core ] = wp_slash( $input[ $public ] );
			}
		}

		return $update;
	}

	/**
	 * Snapshot every protected post-row invariant used by the final guard.
	 *
	 * @param WP_Post              $post Current stored object.
	 * @param array<string, mixed> $input Normalized Update input.
	 * @return array<string, int|string>
	 */
	private function protected_snapshot( WP_Post $post, array $input ): array {
		$fields  = array(
			'ID',
			'post_type',
			'post_status',
			'post_author',
			'post_parent',
			'post_date',
			'post_date_gmt',
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
		$mutable = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);
		foreach ( $mutable as $public => $core ) {
			if ( ! array_key_exists( $public, $input ) ) {
				$fields[] = $core;
			}
		}
		$snapshot = array();
		foreach ( $fields as $field ) {
			$snapshot[ $field ] = isset( $post->{$field} ) ? $post->{$field} : '';
		}

		return $snapshot;
	}

	/**
	 * Build the target-scoped Update invariant guard.
	 *
	 * @param int                       $post_id Target object ID.
	 * @param array<string, int|string> $snapshot Protected values.
	 * @param string                    $token Private operation marker.
	 * @return callable
	 */
	private function build_update_invariant_guard( int $post_id, array $snapshot, string $token ): callable {
		return static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $post_id, $snapshot, $token ): array {
			if ( ! $update
				|| (int) ( $unsanitized_postarr['ID'] ?? 0 ) !== $post_id
				|| ( $unsanitized_postarr['wp_auto_connector_guard_token'] ?? null ) !== $token
			) {
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
	 * Build the exact Update output from the final stored object.
	 *
	 * @param WP_Post $post Final object.
	 * @param string  $post_type Fixed type.
	 * @return array<string, mixed>|WP_Error
	 */
	private function update_output( WP_Post $post, string $post_type ) {
		$link     = get_permalink( $post );
		$edit_url = get_edit_post_link( $post, 'raw' );
		if ( ! is_string( $link ) || ! is_string( $edit_url ) ) {
			return $this->uncertain();
		}

		return array(
			'id'           => (int) $post->ID,
			'type'         => $post_type,
			'status'       => 'draft',
			'slug'         => (string) $post->post_name,
			'link'         => $link,
			'edit_url'     => $edit_url,
			'modified_gmt' => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Normalize and semantically validate the public input.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}

		$allowed = array( 'title', 'content', 'excerpt', 'slug', 'idempotency_key' );
		if ( array_diff( array_keys( $input ), $allowed )
			|| ! array_key_exists( 'title', $input )
			|| ! array_key_exists( 'idempotency_key', $input )
			|| ! is_string( $input['title'] )
			|| ! is_string( $input['idempotency_key'] )
		) {
			return $this->invalid_request();
		}

		$normalized = array(
			'title'           => $input['title'],
			'content'         => $input['content'] ?? '',
			'excerpt'         => $input['excerpt'] ?? '',
			'slug'            => array_key_exists( 'slug', $input ) ? $input['slug'] : null,
			'idempotency_key' => $input['idempotency_key'],
		);

		if (
			! is_string( $normalized['content'] )
			|| ! is_string( $normalized['excerpt'] )
			|| ( null !== $normalized['slug'] && ! is_string( $normalized['slug'] ) )
			|| ! $this->length_between( $normalized['title'], 1, 500 )
			|| ! $this->length_between( $normalized['content'], 0, 1000000 )
			|| ! $this->length_between( $normalized['excerpt'], 0, 50000 )
			|| ( null !== $normalized['slug'] && ! $this->length_between( $normalized['slug'], 1, 200 ) )
			|| ! $this->length_between( $normalized['idempotency_key'], 8, 128 )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $normalized['idempotency_key'] )
			|| 1 !== preg_match( '/[^\s\p{Z}\p{C}]/u', $normalized['title'] )
		) {
			return $this->invalid_request();
		}

		return $normalized;
	}

	/**
	 * Build only the allowlisted Core arguments plus a non-persistent guard marker.
	 *
	 * @param string               $post_type Fixed post type.
	 * @param int                  $actor_id Current actor.
	 * @param array<string, mixed> $input Normalized input.
	 * @param string               $token Operation marker.
	 * @return array<string, mixed>
	 */
	private function build_insert_args( string $post_type, int $actor_id, array $input, string $token ): array {
		$insert = array(
			'post_title'                    => wp_slash( $input['title'] ),
			'post_content'                  => wp_slash( $input['content'] ),
			'post_excerpt'                  => wp_slash( $input['excerpt'] ),
			'post_type'                     => $post_type,
			'post_status'                   => 'draft',
			'post_author'                   => $actor_id,
			'wp_auto_connector_guard_token' => $token,
		);

		if ( null !== $input['slug'] ) {
			$insert['post_name'] = wp_slash( $input['slug'] );
		}

		if ( 'page' === $post_type ) {
			$insert['post_parent'] = 0;
		}

		return $insert;
	}

	/**
	 * Build an operation-scoped final data guard.
	 *
	 * @param string $post_type Fixed type.
	 * @param int    $actor_id Fixed author.
	 * @param string $token Private operation marker.
	 * @return callable
	 */
	private function build_invariant_guard( string $post_type, int $actor_id, string $token ): callable {
		return static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $post_type, $actor_id, $token ): array {
			if ( $update || ( $unsanitized_postarr['wp_auto_connector_guard_token'] ?? null ) !== $token ) {
				return $data;
			}

			$data['post_type']   = $post_type;
			$data['post_status'] = 'draft';
			$data['post_author'] = $actor_id;
			if ( 'page' === $post_type ) {
				// Pages require a root parent by contract. Post parent behavior
				// remains under Core/plugin control and is not client-exposed.
				$data['post_parent'] = 0;
			}

			return $data;
		};
	}

	/**
	 * Verify fixed values after Core returns.
	 *
	 * @param WP_Post $post Created object.
	 * @param string  $post_type Fixed type.
	 * @param int     $actor_id Fixed author.
	 */
	private function verify_invariants( WP_Post $post, string $post_type, int $actor_id ): bool {
		if ( $post_type !== $post->post_type || 'draft' !== $post->post_status || $actor_id !== (int) $post->post_author ) {
			return false;
		}

		return 'page' !== $post_type || 0 === (int) $post->post_parent;
	}

	/**
	 * Handle an existing scope without creating a second object.
	 *
	 * @param string $option_name Persistent claim option name.
	 * @param mixed  $record Existing persistent record.
	 * @param string $post_type Fixed type.
	 * @param string $ability Expected Ability name.
	 * @param int    $actor_id Expected actor.
	 * @param string $fingerprint Current fingerprint.
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_existing_claim( string $option_name, $record, string $post_type, string $ability, int $actor_id, string $fingerprint ) {
		if ( ! is_array( $record ) || ! $this->valid_record( $record ) ) {
			return $this->uncertain();
		}

		if ( $ability !== $record['ability'] || $actor_id !== $record['actor_user_id'] || $fingerprint !== $record['fingerprint'] ) {
			return $this->idempotency_conflict();
		}

		if ( 'in_progress' === $record['state'] ) {
			return $this->idempotency_in_progress();
		}

		$post = get_post( (int) $record['target_id'] );
		if ( 'completed' === $record['state'] ) {
			if ( ! $post instanceof WP_Post || ! $this->verify_invariants( $post, $post_type, (int) $record['actor_user_id'] ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				return $this->idempotency_conflict();
			}

			return $this->output( $post, $post_type, true );
		}

		if ( 'audit_recorded' !== $record['state'] ) {
			return $this->uncertain();
		}

		if ( ! $post instanceof WP_Post || ! $this->verify_invariants( $post, $post_type, (int) $record['actor_user_id'] ) || ! current_user_can( 'edit_post', $post->ID ) || ! $this->audit->has_create_event( $post->ID, $ability, $actor_id, $fingerprint ) ) {
			return $this->uncertain();
		}

		if ( ! $this->idempotency->complete( $option_name, $record ) ) {
			return $this->uncertain();
		}

		return $this->output( $post, $post_type, true );
	}

	/**
	 * Validate the fixed persisted claim shape.
	 *
	 * @param array<string, mixed> $record Stored record.
	 */
	private function valid_record( array $record ): bool {
		$required = array( 'version', 'actor_user_id', 'ability', 'fingerprint', 'state', 'target_id', 'created_gmt', 'updated_gmt' );
		return array_diff( $required, array_keys( $record ) ) === array()
			&& array_diff( array_keys( $record ), $required ) === array()
			&& is_int( $record['version'] )
			&& 1 === $record['version']
			&& is_int( $record['actor_user_id'] )
			&& is_string( $record['ability'] )
			&& is_string( $record['fingerprint'] )
			&& in_array( $record['state'], array( 'in_progress', 'audit_recorded', 'completed' ), true )
			&& is_int( $record['target_id'] )
			&& $record['target_id'] >= 0
			&& ( 'in_progress' === $record['state'] || $record['target_id'] > 0 )
			&& is_string( $record['created_gmt'] )
			&& is_string( $record['updated_gmt'] );
	}

	/**
	 * Construct the canonical fingerprint, excluding the raw key.
	 *
	 * @param array<string, mixed> $input Normalized input.
	 */
	private function fingerprint( array $input ): string {
		$payload = array( $input['title'], $input['content'], $input['excerpt'], $input['slug'] ?? '' );
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', is_string( $json ) ? $json : implode( "\0", $payload ) );
	}

	/**
	 * Build the exact Create output from the final stored object.
	 *
	 * @param WP_Post $post Final object.
	 * @param string  $post_type Fixed type.
	 * @param bool    $replayed Whether this is a replay.
	 * @return array<string, mixed>|WP_Error
	 */
	private function output( WP_Post $post, string $post_type, bool $replayed ) {
		$link     = get_permalink( $post );
		$edit_url = get_edit_post_link( $post, 'raw' );
		if ( ! is_string( $link ) || ! is_string( $edit_url ) ) {
			return $this->uncertain();
		}

		return array(
			'id'                   => (int) $post->ID,
			'type'                 => $post_type,
			'status'               => 'draft',
			'slug'                 => (string) $post->post_name,
			'link'                 => $link,
			'edit_url'             => $edit_url,
			'modified_gmt'         => (string) $post->post_modified_gmt,
			'idempotency_replayed' => $replayed,
		);
	}

	/**
	 * Character-aware length check.
	 *
	 * @param string $value Value to measure.
	 * @param int    $minimum Inclusive minimum.
	 * @param int    $maximum Inclusive maximum.
	 */
	private function length_between( string $value, int $minimum, int $maximum ): bool {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		return $length >= $minimum && $length <= $maximum;
	}

	/**
	 * Return the stable invalid-input error.
	 */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}

	/**
	 * Return the stable idempotency conflict error.
	 */
	private function idempotency_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_conflict', __( 'The idempotency request cannot be replayed safely.', 'wp-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return the stable in-progress error.
	 */
	private function idempotency_in_progress(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_in_progress', __( 'The idempotency request is still in progress.', 'wp-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return the stable confirmed-create-failure error.
	 */
	private function create_failed(): WP_Error {
		return new WP_Error( 'wp_auto_content_create_failed', __( 'The draft could not be created.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Return the existence-hiding target error.
	 */
	private function content_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_content_not_found', __( 'The requested content could not be found.', 'wp-auto-connector' ), array( 'status' => 404 ) );
	}

	/**
	 * Return the authorized non-draft status conflict.
	 */
	private function content_status_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_content_status_conflict', __( 'Only draft content can be updated.', 'wp-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return the stale-write conflict.
	 */
	private function content_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_content_conflict', __( 'The draft changed since it was read.', 'wp-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return the stable confirmed-update-failure error.
	 */
	private function update_failed(): WP_Error {
		return new WP_Error( 'wp_auto_content_update_failed', __( 'The draft could not be updated.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Return the stable uncertain-state error.
	 */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_mutation_state_uncertain', __( 'The draft operation state could not be confirmed.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}
}
