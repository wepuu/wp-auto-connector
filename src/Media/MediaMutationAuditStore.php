<?php
/**
 * Bounded private media mutation attribution.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WPAuto\Connector\Content\AtomicOwnershipStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maintains the separate fixed-schema media audit history. */
final class MediaMutationAuditStore {
	private const META_KEY   = '_wp_auto_connector_media_mutation_audit';
	private const MAX_EVENTS = 20;

	/**
	 * Atomic ownership primitive for per-object audit appends.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the store with an optional ownership dependency.
	 *
	 * @param AtomicOwnershipStore|null $ownership Atomic ownership store.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Append and verify one exact media event.
	 *
	 * @param int                  $post_id Target attachment ID.
	 * @param array<string, mixed> $event Attribution event.
	 */
	public function append( int $post_id, array $event ): bool {
		if ( $post_id < 1 || ! $this->is_valid_event( $event ) || $event['target_object_id'] !== $post_id ) {
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
			if ( null !== $events ) {
				$events[]    = $event;
				$events      = array_slice( $events, -self::MAX_EVENTS );
				$updated     = update_post_meta( $post_id, self::META_KEY, $events );
				$critical_ok = false !== $updated && $this->read_events( $post_id ) === $events;
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
	 * Prove one exact upload event exists for recovery.
	 *
	 * @param int    $post_id Target attachment ID.
	 * @param string $ability Ability name.
	 * @param int    $actor_id Actor user ID.
	 * @param string $fingerprint Payload fingerprint.
	 */
	public function has_upload_event( int $post_id, string $ability, int $actor_id, string $fingerprint ): bool {
		$events = $this->read_events( $post_id );
		if ( null === $events ) {
			return false;
		}
		$matches = 0;
		foreach ( $events as $event ) {
			if ( 'upload' === $event['operation'] && $ability === $event['ability'] && $actor_id === $event['actor_user_id'] && $post_id === $event['target_object_id'] && $fingerprint === $event['fingerprint'] ) {
				++$matches;
			}
		}
		return 1 === $matches;
	}

	/** Expose the fixed private meta key for validation. */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	/**
	 * Read one nonduplicated, fully valid audit container.
	 *
	 * @param int $post_id Target attachment ID.
	 * @return array<int, mixed>|null
	 */
	private function read_events( int $post_id ): ?array {
		$values = get_post_meta( $post_id, self::META_KEY, false );
		if ( ! is_array( $values ) || count( $values ) > 1 ) {
			return null;
		}
		if ( array() === $values ) {
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
			if ( ! is_array( $event ) || ! $this->is_valid_event( $event ) || $event['target_object_id'] !== $post_id ) {
				return null;
			}
		}
		return $events;
	}

	/**
	 * Validate one exact upload event.
	 *
	 * @param array<string, mixed> $event Candidate event.
	 */
	private function is_valid_event( array $event ): bool {
		$expected = array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'fingerprint' );
		$keys     = array_keys( $event );
		sort( $expected );
		sort( $keys );

		return $keys === $expected
			&& 1 === $event['version']
			&& 'upload' === $event['operation']
			&& 'wp-auto/media-upload' === $event['ability']
			&& is_int( $event['actor_user_id'] ) && $event['actor_user_id'] >= 1
			&& is_int( $event['target_object_id'] ) && $event['target_object_id'] >= 1
			&& is_string( $event['fingerprint'] ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $event['fingerprint'] )
			&& is_string( $event['timestamp_gmt'] ) && $this->valid_timestamp( $event['timestamp_gmt'] );
	}

	/**
	 * Validate one real Gregorian GMT timestamp.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return false;
		}
		return (int) substr( $value, 0, 4 ) > 0
			&& checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) )
			&& (int) substr( $value, 11, 2 ) < 24
			&& (int) substr( $value, 14, 2 ) < 60
			&& (int) substr( $value, 17, 2 ) < 60;
	}

	/**
	 * Build the shared per-site, per-object audit lock name.
	 *
	 * @param int $post_id Target attachment ID.
	 */
	private function lock_name( int $post_id ): string {
		return 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', get_current_blog_id() . "\0" . $post_id );
	}
}
