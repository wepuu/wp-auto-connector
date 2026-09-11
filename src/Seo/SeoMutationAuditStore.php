<?php
/**
 * Bounded private SEO mutation attribution.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

use WPAuto\Connector\Content\AtomicOwnershipStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maintains the fixed, value-free SEO audit history. */
final class SeoMutationAuditStore {
	private const META_KEY   = '_wp_auto_connector_seo_mutation_audit';
	private const MAX_EVENTS = 20;

	/**
	 * Atomic ownership primitive for per-object audit appends.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the audit store.
	 *
	 * @param AtomicOwnershipStore|null $ownership Optional ownership dependency.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Append one exact SEO event and verify the persisted value.
	 *
	 * @param int                 $post_id Audited object ID.
	 * @param array<string,mixed> $event Attribution event.
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

	/** Expose the exact private key for focused tests and uninstall checks. */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	/**
	 * Read one valid private audit container.
	 *
	 * @param int $post_id Audited object ID.
	 * @return array<int,mixed>|null
	 */
	private function read_events( int $post_id ): ?array {
		$values = get_post_meta( $post_id, self::META_KEY, false );
		if ( ! is_array( $values ) || count( $values ) > 1 ) {
			return null;
		}
		if ( array() === $values ) {
			return array();
		}
		if ( ! is_array( $values[0] ) || count( $values[0] ) > self::MAX_EVENTS || ( ! empty( $values[0] ) && array_keys( $values[0] ) !== range( 0, count( $values[0] ) - 1 ) ) ) {
			return null;
		}
		$events = array_values( $values[0] );
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! $this->is_valid_event( $event ) || ( $event['target_object_id'] ?? null ) !== $post_id ) {
				return null;
			}
		}
		return $events;
	}

	/**
	 * Validate the exact value-free event shape.
	 *
	 * @param array<string,mixed> $event Candidate event.
	 */
	private function is_valid_event( array $event ): bool {
		$expected = array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'expected_state_token', 'result_state_token', 'changed_fields' );
		$keys     = array_keys( $event );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected || 1 !== ( $event['version'] ?? null ) || 'update' !== ( $event['operation'] ?? null ) || 'wp-auto/seo-update' !== ( $event['ability'] ?? null ) ) {
			return false;
		}
		if ( ! is_int( $event['actor_user_id'] ?? null ) || $event['actor_user_id'] < 1 || ! is_int( $event['target_object_id'] ?? null ) || $event['target_object_id'] < 1 || ! is_string( $event['timestamp_gmt'] ?? null ) || ! $this->valid_timestamp( $event['timestamp_gmt'] ) ) {
			return false;
		}
		if ( ! is_string( $event['expected_state_token'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $event['expected_state_token'] ) || ! is_string( $event['result_state_token'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $event['result_state_token'] ) ) {
			return false;
		}
		$allowed = array( 'title', 'description', 'canonical_url', 'focus_keywords', 'robots' );
		if ( ! is_array( $event['changed_fields'] ) || count( $event['changed_fields'] ) < 1 || count( $event['changed_fields'] ) > count( $allowed ) || array_values( $event['changed_fields'] ) !== $event['changed_fields'] || count( array_unique( $event['changed_fields'] ) ) !== count( $event['changed_fields'] ) || array_diff( $event['changed_fields'], $allowed ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate one real GMT timestamp.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return false;
		}
		return (int) substr( $value, 0, 4 ) > 0 && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) && (int) substr( $value, 11, 2 ) < 24 && (int) substr( $value, 14, 2 ) < 60 && (int) substr( $value, 17, 2 ) < 60;
	}

	/**
	 * Scope the lock independently from other audit families.
	 *
	 * @param int $post_id Audited object ID.
	 */
	private function lock_name( int $post_id ): string {
		$scope = "seo\0" . (string) get_current_blog_id() . "\0" . (string) $post_id;
		return 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', $scope );
	}
}
