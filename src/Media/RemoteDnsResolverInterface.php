<?php
/**
 * Narrow DNS resolver seam for remote media policy.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves all address records for one normalized DNS name. */
interface RemoteDnsResolverInterface {
	/**
	 * Resolve A and AAAA records.
	 *
	 * @param string $host Normalized DNS name.
	 * @return array<int, string>
	 */
	public function resolve( string $host ): array;
}
