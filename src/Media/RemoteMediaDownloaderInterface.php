<?php
/**
 * Narrow remote media downloader seam.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Downloads one policy-approved remote file into a temporary path. */
interface RemoteMediaDownloaderInterface {
	/**
	 * Download one remote media file.
	 *
	 * @param string $url Normalized URL.
	 * @return array{path:string,url:string}|WP_Error
	 */
	public function download( string $url );
}
