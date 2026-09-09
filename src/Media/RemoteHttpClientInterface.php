<?php
/**
 * Narrow HTTP seam for remote media policy.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Performs one fixed, pinned WordPress HTTP request. */
interface RemoteHttpClientInterface {
	/**
	 * Fetch one URL into a caller-owned temporary file.
	 *
	 * @param string $url       Normalized URL.
	 * @param string $address   One previously validated public address.
	 * @param string $filename  Temporary destination.
	 * @param int    $byte_cap  Effective decoded byte cap.
	 * @param int    $timeout   Remaining whole-request timeout in seconds.
	 * @return array{status:int,headers:array<string,string>}|WP_Error
	 */
	public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout );
}
