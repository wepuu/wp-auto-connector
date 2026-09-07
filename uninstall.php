<?php
/**
 * Uninstall handler.
 *
 * Removes only the private mutation state approved by ADR-004.
 *
 * @package WPAutoConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

try {
	require_once __DIR__ . '/src/Uninstall/PrivateStateCleanup.php';

	( new WPAuto\Connector\Uninstall\PrivateStateCleanup() )->run();
} catch ( Throwable $exception ) {
	// WordPress does not consume an uninstall result. Never expose private errors.
	unset( $exception );
}
