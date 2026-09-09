<?php
/**
 * Production clock for remote download deadlines.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses the process monotonic clock when available. */
final class SystemRemoteClock implements RemoteClockInterface {
	/** Return seconds on a monotonic timeline. */
	public function now(): float {
		return hrtime( true ) / 1000000000;
	}
}
