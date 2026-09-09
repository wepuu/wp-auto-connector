<?php
/**
 * Permission-aware draft Post taxonomy assignment.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Taxonomy;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the fixed Phase 1.5.3 exact taxonomy replacement workflow.
 */
final class TaxonomyAssignmentService {
	private const ABILITY        = 'wp-auto/taxonomy-assign';
	private const POST_TYPE      = 'post';
	private const TAXONOMIES     = array( 'category', 'post_tag' );
	private const MAX_TERMS      = 50;
	private const PROBE_LIMIT    = 51;
	private const AUDIT_META_KEY = '_wp_auto_connector_taxonomy_mutation_audit';

	/**
	 * Private bounded audit store.
	 *
	 * @var TaxonomyMutationAuditStore
	 */
	private TaxonomyMutationAuditStore $audit;

	/**
	 * Create the service with an optional audit dependency.
	 *
	 * @param TaxonomyMutationAuditStore|null $audit Private audit store.
	 */
	public function __construct( ?TaxonomyMutationAuditStore $audit = null ) {
		$this->audit = $audit ?? new TaxonomyMutationAuditStore();
	}

	/**
	 * Check the complete fixed permission baseline for an input.
	 *
	 * The Ability layer uses this as a second defense. It returns only a boolean
	 * so target existence and capability details never become a permission error.
	 *
	 * @param mixed $input Raw Ability input.
	 */
	public function can_assign( $input = array() ): bool {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return false;
		}

		try {
			return $this->authorized_context( $normalized ) !== null;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Replace one exact built-in taxonomy set on a draft Post.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function assign( $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$actor_id               = get_current_user_id();
		$state_may_have_changed = false;
		try {
			if ( $actor_id < 1 ) {
				return $this->content_not_found();
			}

			$context = $this->authorized_context( $normalized );
			if ( null === $context ) {
				return $this->content_not_found();
			}

			$term_check = $this->validate_term_ids( $normalized['term_ids'], $normalized['taxonomy'] );
			if ( is_wp_error( $term_check ) ) {
				return $term_check;
			}

			$current        = $this->read_object_term_ids( $normalized['target_id'], $normalized['taxonomy'] );
			$other_taxonomy = $this->other_taxonomy( $normalized['taxonomy'] );
			$other_current  = $this->read_object_term_ids( $normalized['target_id'], $other_taxonomy );
			if ( is_wp_error( $current ) || is_wp_error( $other_current ) ) {
				return $this->query_failed();
			}
			if ( count( $current ) > self::MAX_TERMS || count( $other_current ) > self::MAX_TERMS ) {
				return $this->set_too_large();
			}

			if ( $current === $normalized['term_ids'] ) {
				return $this->output( $context, $normalized['taxonomy'], $current, false );
			}
			if ( $current !== $normalized['expected_term_ids'] ) {
				return $this->taxonomy_conflict();
			}

			// Re-authorize and re-read immediately before the Core write.
			$final_context = $this->authorized_context( $normalized );
			if ( null === $final_context || get_current_user_id() !== $actor_id ) {
				return $this->content_not_found();
			}
			$term_check = $this->validate_term_ids( $normalized['term_ids'], $normalized['taxonomy'] );
			if ( is_wp_error( $term_check ) ) {
				return $term_check;
			}

			$latest_current       = $this->read_object_term_ids( $normalized['target_id'], $normalized['taxonomy'] );
			$latest_other_current = $this->read_object_term_ids( $normalized['target_id'], $other_taxonomy );
			if ( is_wp_error( $latest_current ) || is_wp_error( $latest_other_current ) ) {
				return $this->query_failed();
			}
			if ( count( $latest_current ) > self::MAX_TERMS || count( $latest_other_current ) > self::MAX_TERMS ) {
				return $this->set_too_large();
			}
			if ( $latest_current === $normalized['term_ids'] ) {
				return $this->output( $final_context, $normalized['taxonomy'], $latest_current, false );
			}
			if ( $latest_current !== $normalized['expected_term_ids'] ) {
				return $this->taxonomy_conflict();
			}

			$snapshot    = $this->protected_snapshot( $final_context['post'] );
			$meta_before = get_post_meta( $final_context['post']->ID );
			if ( ! is_array( $meta_before ) ) {
				return $this->uncertain();
			}

			$guard                 = $this->build_invariant_guard( $final_context['post']->ID, $snapshot );
			$meta_guard            = $this->build_metadata_guard( $final_context['post']->ID );
			$guard_installed       = false;
			$metadata_add_guard    = false;
			$metadata_update_guard = false;
			$metadata_delete_guard = false;
			try {
				add_filter( 'wp_insert_post_data', $guard, 10, 4 );
				$guard_installed = true;
				add_filter( 'add_post_metadata', $meta_guard, 10, 5 );
				$metadata_add_guard = true;
				add_filter( 'update_post_metadata', $meta_guard, 10, 5 );
				$metadata_update_guard = true;
				add_filter( 'delete_post_metadata', $meta_guard, 10, 5 );
				$metadata_delete_guard  = true;
				$state_may_have_changed = true;
				$core_result            = wp_set_object_terms( $final_context['post']->ID, $normalized['term_ids'], $normalized['taxonomy'], false );
			} finally {
				$this->remove_guard_filter( 'delete_post_metadata', $meta_guard, $metadata_delete_guard );
				$this->remove_guard_filter( 'update_post_metadata', $meta_guard, $metadata_update_guard );
				$this->remove_guard_filter( 'add_post_metadata', $meta_guard, $metadata_add_guard );
				$this->remove_guard_filter( 'wp_insert_post_data', $guard, $guard_installed );
			}

			$final_state = $this->read_final_state( $final_context['post']->ID, $normalized['taxonomy'], $other_taxonomy );
			if ( is_wp_error( $final_state ) ) {
				return $this->uncertain();
			}
			if ( ! $this->same_post_meta_except_audit( $meta_before, $final_state['meta'] )
				|| ! $this->same_protected_state( $final_state['post'], $snapshot )
				|| $final_state['other_terms'] !== $latest_other_current ) {
				return $this->uncertain();
			}

			if ( is_wp_error( $core_result ) || ! is_array( $core_result ) ) {
				if ( $final_state['selected_terms'] === $latest_current ) {
					return $this->assign_failed();
				}
				return $this->uncertain();
			}
			if ( $final_state['selected_terms'] !== $normalized['term_ids'] ) {
				return $final_state['selected_terms'] === $latest_current ? $this->assign_failed() : $this->uncertain();
			}

			$event = array(
				'version'           => 1,
				'operation'         => 'assign',
				'ability'           => self::ABILITY,
				'actor_user_id'     => $actor_id,
				'target_object_id'  => $final_context['post']->ID,
				'taxonomy'          => $normalized['taxonomy'],
				'timestamp_gmt'     => current_time( 'mysql', true ),
				'expected_term_ids' => $normalized['expected_term_ids'],
				'previous_term_ids' => $latest_current,
				'result_term_ids'   => $normalized['term_ids'],
			);
			if ( ! $this->audit->append_assignment( $final_context['post']->ID, $event ) ) {
				return $this->uncertain();
			}

			$after_audit = $this->read_final_state( $final_context['post']->ID, $normalized['taxonomy'], $other_taxonomy );
			if ( is_wp_error( $after_audit )
				|| ! $this->same_protected_state( $after_audit['post'], $snapshot )
				|| $after_audit['selected_terms'] !== $normalized['term_ids']
				|| $after_audit['other_terms'] !== $latest_other_current
				|| ! $this->same_post_meta_except_audit( $meta_before, $after_audit['meta'] ) ) {
				return $this->uncertain();
			}

			return $this->output( $after_audit['post'], $normalized['taxonomy'], $after_audit['selected_terms'], true );
		} catch ( \Throwable ) {
			return $state_may_have_changed ? $this->uncertain() : $this->assign_failed();
		}
	}

	/**
	 * Normalize and semantically validate the strict request.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		$required = array( 'target_id', 'taxonomy', 'term_ids', 'expected_term_ids' );
		if ( ! is_array( $input ) || count( $input ) !== count( $required )
			|| array_diff( array_keys( $input ), $required ) || array_diff( $required, array_keys( $input ) )
			|| ! is_int( $input['target_id'] ) || $input['target_id'] < 1
			|| ! is_string( $input['taxonomy'] ) || ! in_array( $input['taxonomy'], self::TAXONOMIES, true )
			|| ! is_array( $input['term_ids'] ) || ! is_array( $input['expected_term_ids'] ) ) {
			return $this->invalid_request();
		}

		$term_ids          = $this->normalize_ids( $input['term_ids'], 1, self::MAX_TERMS );
		$expected_term_ids = $this->normalize_ids( $input['expected_term_ids'], 0, self::MAX_TERMS );
		if ( is_wp_error( $term_ids ) || is_wp_error( $expected_term_ids ) ) {
			return $this->invalid_request();
		}

		return array(
			'target_id'         => $input['target_id'],
			'taxonomy'          => $input['taxonomy'],
			'term_ids'          => $term_ids,
			'expected_term_ids' => $expected_term_ids,
		);
	}

	/**
	 * Normalize a bounded unique positive integer list.
	 *
	 * @param mixed $ids Candidate list.
	 * @param int   $minimum_count Minimum list length.
	 * @param int   $maximum_count Maximum list length.
	 * @return array<int,int>|WP_Error
	 */
	private function normalize_ids( $ids, int $minimum_count, int $maximum_count ) {
		if ( count( $ids ) < $minimum_count || count( $ids ) > $maximum_count ) {
			return $this->invalid_request();
		}
		if ( ! empty( $ids ) && array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) ) {
			return $this->invalid_request();
		}

		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return $this->invalid_request();
			}
			$normalized[] = $id;
		}

		if ( count( array_unique( $normalized, SORT_NUMERIC ) ) !== count( $normalized ) ) {
			return $this->invalid_request();
		}

		sort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	/**
	 * Resolve and authorize the fixed target and taxonomy.
	 *
	 * @param array<string,mixed> $input Normalized input.
	 * @return array{post:WP_Post,taxonomy:object}|null
	 */
	private function authorized_context( array $input ): ?array {
		$taxonomy = get_taxonomy( $input['taxonomy'] );
		if ( ! is_object( $taxonomy ) || ! isset( $taxonomy->cap->assign_terms )
			|| ! is_string( $taxonomy->cap->assign_terms ) || '' === $taxonomy->cap->assign_terms
			|| ! function_exists( 'is_object_in_taxonomy' ) || ! is_object_in_taxonomy( self::POST_TYPE, $input['taxonomy'] )
			|| ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return null;
		}

		$post_type = get_post_type_object( self::POST_TYPE );
		if ( ! is_object( $post_type ) || ! isset( $post_type->cap->edit_posts )
			|| ! is_string( $post_type->cap->edit_posts ) || '' === $post_type->cap->edit_posts
			|| ! current_user_can( $post_type->cap->edit_posts ) ) {
			return null;
		}

		$post = get_post( $input['target_id'] );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status
			|| ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}

		return array(
			'post'     => $post,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Confirm every requested ID belongs to the selected taxonomy.
	 *
	 * @param array<int,int> $term_ids Requested IDs.
	 * @param string         $taxonomy Fixed taxonomy.
	 * @return true|WP_Error
	 */
	private function validate_term_ids( array $term_ids, string $taxonomy ) {
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy ) {
				return $this->term_not_found();
			}
		}

		return true;
	}

	/**
	 * Read one object's canonical term IDs with a fixed overflow probe.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $taxonomy Fixed taxonomy.
	 * @return array<int,int>|WP_Error
	 */
	private function read_object_term_ids( int $post_id, string $taxonomy ) {
		$terms = wp_get_object_terms(
			$post_id,
			$taxonomy,
			array(
				'fields'                 => 'ids',
				'number'                 => self::PROBE_LIMIT,
				'orderby'                => 'term_id',
				'order'                  => 'ASC',
				'update_term_meta_cache' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $this->query_failed();
		}

		$ids = array();
		foreach ( $terms as $term_id ) {
			if ( is_int( $term_id ) ) {
				$ids[] = $term_id;
			} elseif ( is_string( $term_id ) && ctype_digit( $term_id ) ) {
				$ids[] = (int) $term_id;
			} else {
				return $this->query_failed();
			}
		}

		if ( count( array_unique( $ids, SORT_NUMERIC ) ) !== count( $ids ) ) {
			return $this->query_failed();
		}

		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Read the final object, both relationship sets, and all Post metadata.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $taxonomy Selected taxonomy.
	 * @param string $other_taxonomy Protected taxonomy.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_final_state( int $post_id, string $taxonomy, string $other_taxonomy ) {
		$post     = get_post( $post_id );
		$selected = $this->read_object_term_ids( $post_id, $taxonomy );
		$other    = $this->read_object_term_ids( $post_id, $other_taxonomy );
		$metadata = get_post_meta( $post_id );
		if ( ! $post instanceof WP_Post || is_wp_error( $selected ) || is_wp_error( $other ) || ! is_array( $metadata ) ) {
			return $this->query_failed();
		}

		return array(
			'post'           => $post,
			'selected_terms' => $selected,
			'other_terms'    => $other,
			'meta'           => $metadata,
		);
	}

	/**
	 * Snapshot all Post row fields assignment must preserve.
	 *
	 * @param WP_Post $post Authorized target.
	 * @return array<string,mixed>
	 */
	private function protected_snapshot( WP_Post $post ): array {
		$fields = array(
			'ID',
			'post_type',
			'post_status',
			'post_author',
			'post_parent',
			'post_date',
			'post_date_gmt',
			'post_modified',
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
			'post_name',
			'post_title',
			'post_excerpt',
			'post_content',
		);

		$snapshot = array();
		foreach ( $fields as $field ) {
			$snapshot[ $field ] = isset( $post->{$field} ) ? $post->{$field} : '';
		}

		return $snapshot;
	}

	/**
	 * Build a target-scoped Post row guard for re-entrant hooks.
	 *
	 * @param int                 $post_id Target ID.
	 * @param array<string,mixed> $snapshot Protected values.
	 * @return callable
	 */
	private function build_invariant_guard( int $post_id, array $snapshot ): callable {
		return static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $post_id, $snapshot ): array {
			unset( $postarr );
			if ( ! $update || (int) ( $unsanitized_postarr['ID'] ?? 0 ) !== $post_id ) {
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
	 * Build a target-scoped metadata mutation guard.
	 *
	 * @param int $post_id Target ID.
	 * @return callable
	 */
	private function build_metadata_guard( int $post_id ): callable {
		return static function ( $check, int $object_id ) use ( $post_id ) {
			return $object_id === $post_id ? false : $check;
		};
	}

	/**
	 * Remove one operation-scoped guard without masking the Core result.
	 *
	 * @param string   $hook      Filter hook.
	 * @param callable $callback  Guard callback.
	 * @param bool     $installed Whether registration was proven.
	 */
	private function remove_guard_filter( string $hook, callable $callback, bool $installed ): void {
		if ( ! $installed ) {
			return;
		}

		try {
			remove_filter( $hook, $callback, 10 );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Compare metadata while allowing the single taxonomy audit key to append.
	 *
	 * @param array<string,mixed> $before Snapshot before relationship write.
	 * @param array<string,mixed> $after  Snapshot after relationship/audit.
	 */
	private function same_post_meta_except_audit( array $before, array $after ): bool {
		unset( $before[ self::AUDIT_META_KEY ], $after[ self::AUDIT_META_KEY ] );
		return $before === $after;
	}

	/**
	 * Verify the protected Post fields are unchanged.
	 *
	 * @param WP_Post             $post     Final target.
	 * @param array<string,mixed> $snapshot Protected values.
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
	 * Build the exact assignment output.
	 *
	 * @param array<string,mixed>|WP_Post $context Final target context or Post.
	 * @param string                      $taxonomy Selected taxonomy.
	 * @param array<int,int>              $term_ids Final IDs.
	 * @param bool                        $changed Whether relationships changed.
	 * @return array<string,mixed>
	 */
	private function output( $context, string $taxonomy, array $term_ids, bool $changed ): array {
		$post = $context instanceof WP_Post ? $context : ( $context['post'] ?? null );
		return array(
			'target_id'   => (int) $post->ID,
			'target_type' => self::POST_TYPE,
			'status'      => 'draft',
			'taxonomy'    => $taxonomy,
			'term_ids'    => $term_ids,
			'changed'     => $changed,
		);
	}

	/**
	 * Return the other approved taxonomy.
	 *
	 * @param string $taxonomy Selected taxonomy.
	 */
	private function other_taxonomy( string $taxonomy ): string {
		return 'category' === $taxonomy ? 'post_tag' : 'category';
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the existence-hiding target error. */
	private function content_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_content_not_found', __( 'The requested content was not found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the existence-hiding term error. */
	private function term_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_term_not_found', __( 'The requested taxonomy term could not be found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return a bounded relationship query failure. */
	private function query_failed(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_query_failed', __( 'The taxonomy relationships could not be retrieved.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the bounded set overflow error. */
	private function set_too_large(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_set_too_large', __( 'The taxonomy relationship set exceeds the supported safety bound.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the stale expected-set error. */
	private function taxonomy_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_conflict', __( 'The taxonomy relationships changed after they were read.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the proven-unapplied Core error. */
	private function assign_failed(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_assign_failed', __( 'The taxonomy relationships could not be assigned.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the fail-closed uncertain-state error. */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_state_uncertain', __( 'The taxonomy operation state could not be confirmed.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}
}
