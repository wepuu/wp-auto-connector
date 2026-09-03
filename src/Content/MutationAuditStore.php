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
		if ( $post_id < 1 || ! $this->is_valid_event( $event ) || ( $event['target_object_id'] ?? null ) !== $post_id ) {
			return false;
		}

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

		$matches = 0;
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
				++$matches;
			}
		}

		return 1 === $matches;
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

		if ( ! is_array( $values[0] ) || count( $values[0] ) > self::MAX_EVENTS ) {
			return null;
		}
		if ( array_keys( $values[0] ) !== range( 0, count( $values[0] ) - 1 ) && ! empty( $values[0] ) ) {
			return null;
		}

		$events = array_values( $values[0] );
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ( $event['target_object_id'] ?? null ) !== $post_id || ! $this->is_valid_event( $event ) ) {
				return null;
			}
		}

		return $events;
	}

	/**
	 * Expose the fixed key for focused tests without making it client-facing.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	/**
	 * Validate one exact Create or Update event family.
	 *
	 * @param array<string, mixed> $event Candidate event.
	 */
	private function is_valid_event( array $event ): bool {
		$operation = $event['operation'] ?? null;
		if ( ! in_array( $operation, array( 'create', 'update' ), true ) ) {
			return false;
		}
		$required = 'create' === $operation
			? array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'fingerprint' )
			: ( 'update' === $operation
				? array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'expected_modified_gmt', 'result_modified_gmt' )
				: array() );
		$keys     = array_keys( $event );
		$expected = $required;
		sort( $keys );
		sort( $expected );

		if ( $keys !== $expected
			|| 1 !== $event['version']
			|| ! is_string( $event['ability'] )
			|| ! is_int( $event['actor_user_id'] )
			|| $event['actor_user_id'] < 1
			|| ! is_int( $event['target_object_id'] )
			|| $event['target_object_id'] < 1
			|| ! is_string( $event['timestamp_gmt'] )
			|| ! $this->valid_timestamp( $event['timestamp_gmt'] )
		) {
			return false;
		}

		if ( 'create' === $operation ) {
			return in_array( $event['ability'], array( 'wp-auto/post-create-draft', 'wp-auto/page-create-draft' ), true )
				&& is_string( $event['fingerprint'] )
				&& 1 === preg_match( '/^[0-9a-f]{64}$/D', $event['fingerprint'] );
		}

		return in_array( $event['ability'], array( 'wp-auto/post-update', 'wp-auto/page-update' ), true )
			&& is_string( $event['expected_modified_gmt'] )
			&& $this->valid_timestamp_or_zero( $event['expected_modified_gmt'] )
			&& is_string( $event['result_modified_gmt'] )
			&& $this->valid_timestamp_or_zero( $event['result_modified_gmt'] );
	}

	/**
	 * Validate a real Gregorian GMT timestamp (zero is not accepted).
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
	 * Validate the Update modified timestamp or Core's exact zero sentinel.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp_or_zero( string $value ): bool {
		return '0000-00-00 00:00:00' === $value || $this->valid_timestamp( $value );
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
