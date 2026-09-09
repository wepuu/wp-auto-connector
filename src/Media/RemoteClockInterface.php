<?php
/**
 * Narrow monotonic-clock seam for the remote downloader.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Supplies a monotonic-enough timestamp for a single download lifecycle. */
interface RemoteClockInterface {
	/** Return seconds on a monotonic timeline. */
	public function now(): float;
}
