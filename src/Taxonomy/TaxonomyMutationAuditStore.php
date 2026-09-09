<?php
/**
 * Bounded private taxonomy mutation attribution.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Taxonomy;

use WPAuto\Connector\Content\AtomicOwnershipStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintains the private per-term taxonomy audit history.
 */
final class TaxonomyMutationAuditStore {
	private const META_KEY   = '_wp_auto_connector_taxonomy_mutation_audit';
	private const MAX_EVENTS = 20;

	/**
	 * Atomic ownership primitive for per-term audit appends.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the audit store with an optional ownership dependency.
	 *
	 * @param AtomicOwnershipStore|null $ownership Atomic ownership store.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Append and verify one exact built-in taxonomy Create event.
	 *
	 * @param int                  $term_id Audited term ID.
	 * @param array<string, mixed> $event Attribution event.
	 */
	public function append_create( int $term_id, array $event ): bool {
		if ( $term_id < 1 || ! $this->is_valid_create_event( $event ) || $event['target_term_id'] !== $term_id ) {
			return false;
		}

		try {
			$token   = wp_generate_uuid4();
			$lock    = $this->lock_name( $term_id );
			$acquire = $this->ownership->acquire( $lock, $token );
		} catch ( \Throwable ) {
			return false;
		}
		if ( 'acquired' !== ( $acquire['status'] ?? null ) ) {
			return false;
		}

		$critical_ok = false;
		$release     = null;
		try {
			$events = $this->read_events( $term_id );
			if ( null !== $events ) {
				$events[]    = $event;
				$events      = array_slice( $events, -self::MAX_EVENTS );
				$updated     = update_term_meta( $term_id, self::META_KEY, $events );
				$critical_ok = false !== $updated && $this->read_events( $term_id ) === $events;
			}
		} catch ( \Throwable ) {
			$critical_ok = false;
		} finally {
			try {
				$release = $this->ownership->release( $lock, $token );
			} catch ( \Throwable ) {
				$release = null;
			}
		}

		return $critical_ok && 'released' === ( $release['status'] ?? null );
	}

	/**
	 * Prove one exact taxonomy Create event exists without changing metadata.
	 *
	 * @param int    $term_id Target term ID.
	 * @param string $ability Ability name.
	 * @param int    $actor_id Actor user ID.
	 * @param string $fingerprint Payload fingerprint.
	 */
	public function has_create_event( int $term_id, string $ability, int $actor_id, string $fingerprint ): bool {
		$events = $this->read_events( $term_id );
		if ( null === $events ) {
			return false;
		}

		$matches = 0;
		foreach ( $events as $event ) {
			if (
				is_array( $event )
				&& 'create' === ( $event['operation'] ?? null )
				&& ( $event['ability'] ?? null ) === $ability
				&& ( $event['actor_user_id'] ?? null ) === $actor_id
				&& ( $event['target_term_id'] ?? null ) === $term_id
				&& ( $event['fingerprint'] ?? null ) === $fingerprint
			) {
				++$matches;
			}
		}

		return 1 === $matches;
	}

	/**
	 * Expose the fixed key for focused tests without making it client-facing.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	/**
	 * Read one private term audit container and reject physical duplicates.
	 *
	 * @param int $term_id Target term ID.
	 * @return array<int, mixed>|null
	 */
	private function read_events( int $term_id ): ?array {
		$values = get_term_meta( $term_id, self::META_KEY, false );
		if ( ! is_array( $values ) || count( $values ) > 1 ) {
			return null;
		}
		if ( 0 === count( $values ) ) {
			return array();
		}
		if ( ! is_array( $values[0] ) || count( $values[0] ) > self::MAX_EVENTS ) {
			return null;
		}
		if ( ! empty( $values[0] ) && array_keys( $values[0] ) !== range( 0, count( $values[0] ) - 1 ) ) {
			return null;
		}

		$events = array_values( $values[0] );
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ( $event['target_term_id'] ?? null ) !== $term_id || ! $this->is_valid_create_event( $event ) ) {
				return null;
			}
		}

		return $events;
	}

	/**
	 * Validate the exact built-in taxonomy Create event shape.
	 *
	 * @param array<string, mixed> $event Candidate event.
	 */
	private function is_valid_create_event( array $event ): bool {
		$base_required = array( 'version', 'operation', 'ability', 'actor_user_id', 'target_term_id', 'taxonomy', 'timestamp_gmt', 'fingerprint' );
		$is_category   = 'category' === ( $event['taxonomy'] ?? null ) && 'wp-auto/category-create' === ( $event['ability'] ?? null );
		$is_tag        = 'post_tag' === ( $event['taxonomy'] ?? null ) && 'wp-auto/tag-create' === ( $event['ability'] ?? null );
		$required      = $base_required;
		if ( $is_category ) {
			$required[] = 'parent_id';
		}
		$keys     = array_keys( $event );
		$expected = $required;
		sort( $keys );
		sort( $expected );

		$valid = ( $is_category || $is_tag )
			&& $keys === $expected
			&& 1 === $event['version']
			&& 'create' === $event['operation']
			&& is_int( $event['actor_user_id'] )
			&& $event['actor_user_id'] > 0
			&& is_int( $event['target_term_id'] )
			&& $event['target_term_id'] > 0
			&& is_string( $event['timestamp_gmt'] )
			&& $this->valid_timestamp( $event['timestamp_gmt'] )
			&& is_string( $event['fingerprint'] )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/D', $event['fingerprint'] );
		if ( $is_category ) {
			$valid = $valid && is_int( $event['parent_id'] ) && $event['parent_id'] >= 0;
		}

		return $valid;
	}

	/**
	 * Validate a real Gregorian GMT timestamp.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
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
	 * Build a domain-discriminated per-site, per-term audit lock.
	 *
	 * @param int $term_id Target term ID.
	 */
	private function lock_name( int $term_id ): string {
		$scope = 'taxonomy-term' . "\0" . (string) get_current_blog_id() . "\0" . (string) $term_id;

		return 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', $scope );
	}
}
