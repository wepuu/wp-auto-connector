<?php
/**
 * Permission-aware built-in taxonomy mutation.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Taxonomy;

use WP_Error;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the fixed built-in taxonomy Create workflows.
 */
final class TaxonomyMutationService {
	private const CATEGORY_ABILITY = 'wp-auto/category-create';
	private const TAG_ABILITY      = 'wp-auto/tag-create';

	/**
	 * Persistent claim store.
	 *
	 * @var TaxonomyCreateIdempotencyStore
	 */
	private TaxonomyCreateIdempotencyStore $idempotency;

	/**
	 * Private bounded audit store.
	 *
	 * @var TaxonomyMutationAuditStore
	 */
	private TaxonomyMutationAuditStore $audit;

	/**
	 * Create the service with optional stores for isolated tests.
	 *
	 * @param TaxonomyCreateIdempotencyStore|null $idempotency Persistent claims.
	 * @param TaxonomyMutationAuditStore|null     $audit Private audit.
	 */
	public function __construct( ?TaxonomyCreateIdempotencyStore $idempotency = null, ?TaxonomyMutationAuditStore $audit = null ) {
		$this->idempotency = $idempotency ?? new TaxonomyCreateIdempotencyStore();
		$this->audit       = $audit ?? new TaxonomyMutationAuditStore();
	}

	/**
	 * Check the fixed Category taxonomy's actual management capability.
	 */
	public function can_create_category(): bool {
		return $this->can_manage_taxonomy( 'category' );
	}

	/**
	 * Check the fixed Tag taxonomy's actual management capability.
	 */
	public function can_create_tag(): bool {
		return $this->can_manage_taxonomy( 'post_tag' );
	}

	/**
	 * Create one built-in Category.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_category( $input ) {
		return $this->create_term( 'category', self::CATEGORY_ABILITY, $input, true );
	}

	/**
	 * Create one built-in Tag.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_tag( $input ) {
		return $this->create_term( 'post_tag', self::TAG_ABILITY, $input, false );
	}

	/**
	 * Run the shared fixed-taxonomy Create workflow.
	 *
	 * @param string $taxonomy Fixed Core taxonomy.
	 * @param string $ability Fixed Ability name.
	 * @param mixed  $input Raw ability input.
	 * @param bool   $hierarchical Whether the taxonomy accepts a parent.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_term( string $taxonomy, string $ability, $input, bool $hierarchical ) {
		$normalized = $this->normalize_input( $input, $hierarchical );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$actor_id = get_current_user_id();
		if ( $actor_id < 1 || ! $this->can_manage_taxonomy( $taxonomy ) ) {
			return $this->create_failed();
		}
		if ( $hierarchical && $normalized['parent_id'] > 0 && ! $this->valid_parent( $normalized['parent_id'], $taxonomy ) ) {
			return $this->term_not_found();
		}

		$fingerprint = $this->fingerprint( $normalized, $hierarchical );
		try {
			$claim = $this->idempotency->claim( $ability, $actor_id, $normalized['idempotency_key'], $fingerprint );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		if ( 'existing' === ( $claim['status'] ?? null ) ) {
			if ( ! isset( $claim['name'], $claim['record'] ) || ! is_string( $claim['name'] ) || ! is_array( $claim['record'] ) ) {
				return $this->uncertain();
			}

			try {
				return $this->handle_existing_claim( $claim['name'], $claim['record'], $actor_id, $fingerprint, $taxonomy, $ability, $hierarchical );
			} catch ( \Throwable ) {
				return $this->uncertain();
			}
		}

		if ( 'unresolved' === ( $claim['status'] ?? null ) ) {
			return $this->uncertain();
		}
		if ( 'claimed' !== ( $claim['status'] ?? null ) || ! isset( $claim['name'], $claim['record'] ) || ! is_string( $claim['name'] ) || ! is_array( $claim['record'] ) ) {
			return $this->uncertain();
		}

		$option_name = $claim['name'];
		$record      = $claim['record'];
		try {
			$actor_before = get_current_user_id();
			$can_create   = $this->can_manage_taxonomy( $taxonomy );
			$actor_after  = get_current_user_id();
			if ( 0 !== ( $actor_id - $actor_before ) || ! $can_create || 0 !== ( $actor_id - $actor_after ) ) {
				return $this->release_after_no_create( $option_name, $record );
			}

			$args = array(
				'description' => $normalized['description'],
			);
			if ( $hierarchical ) {
				$args['parent'] = $normalized['parent_id'];
			}
			if ( null !== $normalized['slug'] ) {
				$args['slug'] = $normalized['slug'];
			}

			$result = wp_insert_term( $normalized['name'], $taxonomy, $args );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		if ( is_wp_error( $result ) ) {
			if ( in_array( $result->get_error_code(), array( 'term_exists', 'term_exists_in_taxonomy' ), true ) ) {
				return $this->release_after_result( $option_name, $record, $this->term_conflict() );
			}

			return $this->release_after_no_create( $option_name, $record );
		}

		$term_id = is_array( $result ) ? (int) ( $result['term_id'] ?? 0 ) : 0;
		if ( $term_id < 1 ) {
			return $this->uncertain();
		}

		try {
			if ( ! $this->idempotency->record_target_in_progress( $option_name, $record, $term_id ) ) {
				return $this->uncertain();
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $this->valid_created_term( $term, $taxonomy, $normalized['parent_id'], $hierarchical ) ) {
				return $this->uncertain();
			}

			$output = $this->output( $term, false, $hierarchical );
			if ( is_wp_error( $output ) ) {
				return $output;
			}

			$event = array(
				'version'        => 1,
				'operation'      => 'create',
				'ability'        => $ability,
				'actor_user_id'  => $actor_id,
				'target_term_id' => $term_id,
				'taxonomy'       => $taxonomy,
				'timestamp_gmt'  => current_time( 'mysql', true ),
				'fingerprint'    => $fingerprint,
			);
			if ( $hierarchical ) {
				$event['parent_id'] = (int) $term->parent;
			}
			$record_with_target = array_merge( $record, array( 'target_id' => $term_id ) );
			if ( ! $this->audit->append_create( $term_id, $event ) || ! $this->idempotency->mark_audit_recorded( $option_name, $record_with_target ) ) {
				return $this->uncertain();
			}
			if ( ! $this->idempotency->complete( $option_name, array_merge( $record_with_target, array( 'state' => 'audit_recorded' ) ) ) ) {
				return $this->uncertain();
			}

			return $output;
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
	}

	/**
	 * Handle a previously claimed scope without creating another term.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 * @param int                  $actor_id Current actor.
	 * @param string               $fingerprint Current fingerprint.
	 * @param string               $taxonomy Taxonomy being replayed.
	 * @param string               $ability Ability being replayed.
	 * @param bool                 $hierarchical Whether the taxonomy accepts a parent.
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_existing_claim( string $option_name, array $record, int $actor_id, string $fingerprint, string $taxonomy, string $ability, bool $hierarchical ) {
		if ( $ability !== $record['ability'] || $actor_id !== $record['actor_user_id'] || $fingerprint !== $record['fingerprint'] ) {
			return $this->idempotency_conflict();
		}
		if ( 'in_progress' === $record['state'] ) {
			return $this->idempotency_in_progress();
		}
		if ( ! $this->can_manage_taxonomy( $taxonomy ) ) {
			return $this->idempotency_conflict();
		}

		$term = get_term( (int) $record['target_id'], $taxonomy );
		if ( ! $this->valid_created_term( $term, $taxonomy, null, $hierarchical ) ) {
			return $this->idempotency_conflict();
		}

		if ( 'completed' === $record['state'] ) {
			return $this->output( $term, true, $hierarchical );
		}
		if ( 'audit_recorded' !== $record['state'] || ! $this->audit->has_create_event( (int) $record['target_id'], $ability, $actor_id, $fingerprint ) ) {
			return $this->uncertain();
		}
		if ( ! $this->idempotency->complete( $option_name, $record ) ) {
			return $this->uncertain();
		}

		return $this->output( $term, true, $hierarchical );
	}

	/**
	 * Release a deterministic no-create claim or fail closed.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Initial claim record.
	 * @return WP_Error
	 */
	private function release_after_no_create( string $option_name, array $record ): WP_Error {
		try {
			$released = $this->idempotency->release( $option_name, $record );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		return 'released' === ( $released['status'] ?? null ) ? $this->create_failed() : $this->uncertain();
	}

	/**
	 * Check a built-in taxonomy's actual management capability.
	 *
	 * @param string $taxonomy Fixed Core taxonomy.
	 */
	private function can_manage_taxonomy( string $taxonomy ): bool {
		$taxonomy_object = get_taxonomy( $taxonomy );

		return is_object( $taxonomy_object )
			&& isset( $taxonomy_object->cap->manage_terms )
			&& is_string( $taxonomy_object->cap->manage_terms )
			&& '' !== $taxonomy_object->cap->manage_terms
			&& current_user_can( $taxonomy_object->cap->manage_terms );
	}

	/**
	 * Normalize and semantically validate the public input.
	 *
	 * @param mixed $input        Raw input.
	 * @param bool  $hierarchical Whether the taxonomy accepts a parent.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input, bool $hierarchical ) {
		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}

		$allowed = array( 'name', 'idempotency_key', 'slug', 'description' );
		if ( $hierarchical ) {
			$allowed[] = 'parent_id';
		}
		if ( array_diff( array_keys( $input ), $allowed )
			|| ! array_key_exists( 'name', $input )
			|| ! array_key_exists( 'idempotency_key', $input )
			|| ! is_string( $input['name'] )
			|| ! is_string( $input['idempotency_key'] )
		) {
			return $this->invalid_request();
		}

		$normalized = array(
			'name'            => $input['name'],
			'idempotency_key' => $input['idempotency_key'],
			'slug'            => array_key_exists( 'slug', $input ) ? $input['slug'] : null,
			'description'     => array_key_exists( 'description', $input ) ? $input['description'] : '',
			'parent_id'       => array_key_exists( 'parent_id', $input ) ? $input['parent_id'] : 0,
		);

		$invalid_slug_type = ! is_string( $normalized['slug'] ) && null !== $normalized['slug'];
		if (
			$invalid_slug_type
			|| ! is_string( $normalized['description'] )
			|| ! is_int( $normalized['parent_id'] )
			|| ! $this->length_between( $normalized['name'], 1, 200 )
			|| ! $this->length_between( $normalized['idempotency_key'], 8, 128 )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $normalized['idempotency_key'] )
			|| ! $this->contains_visible_character( $normalized['name'] )
			|| ( null !== $normalized['slug'] && ( ! $this->length_between( $normalized['slug'], 1, 200 ) || ! $this->contains_visible_character( $normalized['slug'] ) || '' === sanitize_title( $normalized['slug'] ) ) )
			|| ! $this->length_between( $normalized['description'], 0, 50000 )
			|| $normalized['parent_id'] < 0
			|| ( ! $hierarchical && array_key_exists( 'parent_id', $input ) )
			|| ( $hierarchical && array_key_exists( 'parent_id', $input ) && $normalized['parent_id'] < 1 )
		) {
			return $this->invalid_request();
		}

		return $normalized;
	}

	/**
	 * Validate a supplied parent after the management capability check.
	 *
	 * @param int    $parent_id Parent term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	private function valid_parent( int $parent_id, string $taxonomy ): bool {
		$parent = get_term( $parent_id, $taxonomy );

		return $parent instanceof WP_Term && $taxonomy === $parent->taxonomy;
	}

	/**
	 * Validate final term identity and optional expected parent.
	 *
	 * @param mixed    $term           Candidate term.
	 * @param string   $taxonomy       Taxonomy.
	 * @param int|null $expected_parent Expected parent, or null for replay.
	 * @param bool     $hierarchical   Whether the taxonomy accepts a parent.
	 */
	private function valid_created_term( $term, string $taxonomy, ?int $expected_parent, bool $hierarchical ): bool {
		return $term instanceof WP_Term
			&& $taxonomy === $term->taxonomy
			&& ( ! $hierarchical ? 0 === (int) $term->parent : ( null === $expected_parent || $expected_parent === (int) $term->parent ) );
	}

	/**
	 * Build the final taxonomy output.
	 *
	 * @param WP_Term $term        Final term.
	 * @param bool    $replayed    Whether this is a replay.
	 * @param bool    $hierarchical Whether the taxonomy accepts a parent.
	 * @return array<string, mixed>
	 */
	private function output( WP_Term $term, bool $replayed, bool $hierarchical ): array {
		$output = array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'count'       => max( 0, (int) $term->count ),
		);
		if ( $hierarchical ) {
			$output['parent_id'] = max( 0, (int) $term->parent );
		}
		$output['idempotency_replayed'] = $replayed;

		return $output;
	}

	/**
	 * Build a deterministic payload fingerprint without the raw key.
	 *
	 * @param array<string, mixed> $input        Normalized input.
	 * @param bool                 $hierarchical Whether the taxonomy accepts a parent.
	 */
	private function fingerprint( array $input, bool $hierarchical ): string {
		$payload = array( $input['name'], $input['slug'] ?? '', $input['description'] );
		if ( $hierarchical ) {
			$payload[] = $input['parent_id'];
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', is_string( $json ) ? $json : implode( "\0", array_map( 'strval', $payload ) ) );
	}

	/**
	 * Check for at least one visible Unicode character.
	 *
	 * @param string $value Candidate text.
	 */
	private function contains_visible_character( string $value ): bool {
		return 1 === preg_match( '/[^\s\p{Z}\p{C}]/u', $value );
	}

	/**
	 * Character-aware length check.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Inclusive minimum.
	 * @param int    $maximum Inclusive maximum.
	 */
	private function length_between( string $value, int $minimum, int $maximum ): bool {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );

		return $length >= $minimum && $length <= $maximum;
	}

	/**
	 * Return an invalid request error.
	 */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/**
	 * Return an idempotency conflict error.
	 */
	private function idempotency_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_conflict', __( 'The idempotency request cannot be replayed safely.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return an in-progress idempotency error.
	 */
	private function idempotency_in_progress(): WP_Error {
		return new WP_Error( 'wp_auto_idempotency_in_progress', __( 'The idempotency request is still in progress.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return an existing-term conflict error.
	 */
	private function term_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_term_conflict', __( 'The taxonomy term conflicts with an existing term.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/**
	 * Return a generic create failure.
	 */
	private function create_failed(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_create_failed', __( 'The taxonomy term could not be created.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Return the existence-hiding taxonomy term error.
	 */
	private function term_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_term_not_found', __( 'The requested taxonomy term could not be found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/**
	 * Release a deterministic result and fail closed if release cannot be proved.
	 *
	 * @param string   $option_name Option name.
	 * @param array    $record Initial claim record.
	 * @param WP_Error $result Stable result after release.
	 * @return WP_Error
	 */
	private function release_after_result( string $option_name, array $record, WP_Error $result ): WP_Error {
		try {
			$released = $this->idempotency->release( $option_name, $record );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		return 'released' === ( $released['status'] ?? null ) ? $result : $this->uncertain();
	}

	/**
	 * Return a fail-closed uncertain-state error.
	 */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_taxonomy_state_uncertain', __( 'The taxonomy operation state could not be confirmed.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}
}
