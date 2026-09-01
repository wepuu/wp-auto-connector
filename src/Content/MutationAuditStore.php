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
	 * Re-entrant logical Create writes currently being finalized in this process.
	 *
	 * @var array<string, bool>
	 */
	private static array $active_create_events = array();

	/**
	 * Append one fixed-field event and verify the persisted value.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $event Attribution event.
	 */
	public function append( int $post_id, array $event ): bool {
		$events   = get_post_meta( $post_id, self::META_KEY, true );
		$events   = is_array( $events ) ? array_values( $events ) : array();
		$events[] = $event;

		if ( self::MAX_EVENTS < count( $events ) ) {
			$events = array_slice( $events, -self::MAX_EVENTS );
		}

		update_post_meta( $post_id, self::META_KEY, $events );

		return get_post_meta( $post_id, self::META_KEY, true ) === $events;
	}

	/**
	 * Append one Create event at most once for its logical operation identity.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $event Create attribution event.
	 */
	public function append_create_once( int $post_id, array $event ): bool {
		$identity = $this->create_identity( $post_id, $event );
		if ( null === $identity || isset( self::$active_create_events[ $identity ] ) ) {
			return false;
		}
		self::$active_create_events[ $identity ] = true;

		try {
			$events = get_post_meta( $post_id, self::META_KEY, true );
			$events = is_array( $events ) ? array_values( $events ) : array();
			foreach ( $events as $existing ) {
				if ( is_array( $existing ) && $this->same_create_identity( $existing, $event, $post_id ) ) {
					return true;
				}
			}

			$events[] = $event;
			if ( self::MAX_EVENTS < count( $events ) ) {
				$events = array_slice( $events, -self::MAX_EVENTS );
			}
			update_post_meta( $post_id, self::META_KEY, $events );

			return get_post_meta( $post_id, self::META_KEY, true ) === $events;
		} finally {
			unset( self::$active_create_events[ $identity ] );
		}
	}

	/**
	 * Build a strict logical identity that excludes the event timestamp.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $event Create attribution event.
	 */
	private function create_identity( int $post_id, array $event ): ?string {
		if (
			'create' !== ( $event['operation'] ?? null )
			|| ! is_string( $event['ability'] ?? null )
			|| ! is_int( $event['actor_user_id'] ?? null )
			|| ! is_int( $event['target_object_id'] ?? null )
			|| $post_id !== $event['target_object_id']
			|| ! is_string( $event['fingerprint'] ?? null )
		) {
			return null;
		}

		return hash( 'sha256', $event['operation'] . "\0" . $event['ability'] . "\0" . $event['actor_user_id'] . "\0" . $event['target_object_id'] . "\0" . $event['fingerprint'] );
	}

	/**
	 * Compare only the logical Create identity fields.
	 *
	 * @param array<string, mixed> $existing Existing event.
	 * @param array<string, mixed> $incoming Incoming event.
	 * @param int                  $post_id Target post ID.
	 */
	private function same_create_identity( array $existing, array $incoming, int $post_id ): bool {
		return 'create' === ( $existing['operation'] ?? null )
			&& 'create' === ( $incoming['operation'] ?? null )
			&& is_string( $existing['ability'] ?? null )
			&& is_string( $incoming['ability'] ?? null )
			&& $existing['ability'] === $incoming['ability']
			&& is_int( $existing['actor_user_id'] ?? null )
			&& is_int( $incoming['actor_user_id'] ?? null )
			&& $existing['actor_user_id'] === $incoming['actor_user_id']
			&& is_int( $existing['target_object_id'] ?? null )
			&& $post_id === $existing['target_object_id']
			&& $existing['target_object_id'] === $incoming['target_object_id']
			&& is_string( $existing['fingerprint'] ?? null )
			&& is_string( $incoming['fingerprint'] ?? null )
			&& $existing['fingerprint'] === $incoming['fingerprint'];
	}

	/**
	 * Expose the fixed key for focused tests without making it client-facing.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}
}
