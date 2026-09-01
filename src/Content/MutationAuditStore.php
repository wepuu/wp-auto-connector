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
	 * Check whether a Create event for the same logical operation is present.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $ability Ability name.
	 * @param int    $actor_id Actor user ID.
	 * @param string $fingerprint Payload fingerprint.
	 */
	public function has_create_event( int $post_id, string $ability, int $actor_id, string $fingerprint ): bool {
		$events = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $events ) ) {
			return false;
		}

		foreach ( $events as $event ) {
			if (
				is_array( $event )
				&& 'create' === ( $event['operation'] ?? null )
				&& in_array( $ability, array( $event['ability'] ?? null ), true )
				&& in_array( $actor_id, array( $event['actor_user_id'] ?? null ), true )
				&& in_array( $post_id, array( $event['target_object_id'] ?? null ), true )
				&& in_array( $fingerprint, array( $event['fingerprint'] ?? null ), true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Expose the fixed key for focused tests without making it client-facing.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}
}
