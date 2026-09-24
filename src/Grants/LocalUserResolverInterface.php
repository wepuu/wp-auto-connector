<?php
/**
 * Local WordPress user existence boundary.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves only whether a local user still exists. */
interface LocalUserResolverInterface {
	/**
	 * Check that a local user remains usable as an identity anchor.
	 *
	 * @param int $user_id Local WordPress user ID.
	 */
	public function exists( int $user_id ): bool;
}
