<?php
/**
 * WordPress user resolver.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses current WordPress user state instead of a platform snapshot. */
final class WordPressLocalUserResolver implements LocalUserResolverInterface {
	/**
	 * Check whether a local WordPress user still exists.
	 *
	 * @param int $user_id Local WordPress user identifier.
	 */
	public function exists( int $user_id ): bool {
		return $user_id > 0 && false !== get_userdata( $user_id );
	}
}
