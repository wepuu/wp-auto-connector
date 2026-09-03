<?php
/**
 * Bounded local WP-Auto mutation attribution.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintains the private per-object mutation audit history.
 */
final class MutationAuditStore {
	private const META_KEY   = '_wp_auto_connector_mutation_audit';
	private const MAX_EVENTS = 20;

	/**
	 * Atomic ownership primitive for per-object audit appends.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the audit store with an optional ownership dependency for tests.
	 *
	 * @param AtomicOwnershipStore|null $ownership Atomic ownership store.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Append one fixed-field event and verify the persisted value.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $event Attribution event.
	 */
	public function append( int $post_id, array $event ): bool {
		try {
			$token   = wp_generate_uuid4();
			$lock    = $this->lock_name( $post_id );
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
			$events = $this->read_events( $post_id );
			if ( null === $events ) {
				return false;
			}

			$events[] = $event;

			if ( self::MAX_EVENTS < count( $events ) ) {
				$events = array_slice( $events, -self::MAX_EVENTS );
			}

			$updated = update_post_meta( $post_id, self::META_KEY, $events );
			if ( false === $updated ) {
				return false;
			}

			$critical_ok = $this->read_events( $post_id ) === $events;
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
	 * Check for one exact logical Create event without mutating metadata.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $ability Ability name.
	 * @param int    $actor_id Actor user ID.
	 * @param string $fingerprint Payload fingerprint.
	 */
	public function has_create_event( int $post_id, string $ability, int $actor_id, string $fingerprint ): bool {
		$events = $this->read_events( $post_id );
		if ( null === $events ) {
			return false;
		}

		foreach ( $events as $event ) {
			if (
				is_array( $event )
				&& 'create' === ( $event['operation'] ?? null )
				&& is_string( $event['ability'] ?? null )
				&& $ability === $event['ability']
				&& is_int( $event['actor_user_id'] ?? null )
				&& $actor_id === $event['actor_user_id']
				&& is_int( $event['target_object_id'] ?? null )
				&& $post_id === $event['target_object_id']
				&& is_string( $event['fingerprint'] ?? null )
				&& $fingerprint === $event['fingerprint']
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the single private audit container and reject physical duplicates.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<int, mixed>|null
	 */
	private function read_events( int $post_id ): ?array {
		$values = get_post_meta( $post_id, self::META_KEY, false );
		if ( ! is_array( $values ) || count( $values ) > 1 ) {
			return null;
		}

		if ( 0 === count( $values ) ) {
			return array();
		}

		return is_array( $values[0] ) ? array_values( $values[0] ) : null;
	}

	/**
	 * Expose the fixed key for focused tests without making it client-facing.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	/**
	 * Build the per-site, per-object audit ownership option name.
	 *
	 * @param int $post_id Target post ID.
	 */
	private function lock_name( int $post_id ): string {
		$scope = (string) get_current_blog_id() . "\0" . (string) $post_id;

		return 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', $scope );
	}
}
