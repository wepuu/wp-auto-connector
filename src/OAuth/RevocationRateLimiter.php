<?php
/**
 * Fixed-window revocation endpoint limiter.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Applies a conservative site-global 60 request per minute ceiling. */
final class RevocationRateLimiter {
	public const OPTION_NAME = 'wp_auto_connector_revocation_rate';
	public const LOCK_NAME   = 'wp_auto_connector_revocation_rate_lock';
	private const LIMIT      = 60;

	/**
	 * Consume one request from the current minute.
	 *
	 * @param int|null $now Optional test clock.
	 */
	public function allow( ?int $now = null ): bool {
		if ( ! add_option( self::LOCK_NAME, array( 'acquired_at' => time() ), '', false ) ) {
			return false;
		}
		try {
			$window  = intdiv( $now ?? time(), 60 );
			$current = get_option( self::OPTION_NAME, null );
			if ( ! is_array( $current ) || array_keys( $current ) !== array( 'window', 'count' ) || $current['window'] !== $window || ! is_int( $current['count'] ) ) {
				$current = array(
					'window' => $window,
					'count'  => 0,
				);
			}
			if ( $current['count'] >= self::LIMIT ) {
				return false;
			}
			++$current['count'];
			$existing = get_option( self::OPTION_NAME, null );
			$saved    = null === $existing
				? add_option( self::OPTION_NAME, $current, '', false )
				: update_option( self::OPTION_NAME, $current, false );
			return false !== $saved || get_option( self::OPTION_NAME, null ) === $current;
		} finally {
			delete_option( self::LOCK_NAME );
		}
	}
}
