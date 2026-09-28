<?php
/**
 * Platform JWKS retrieval boundary.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fetches the bounded public platform key set. */
interface JwksFetcherInterface {
	/**
	 * Fetch a standard JWKS from the fixed issuer.
	 *
	 * @param string $issuer Exact platform issuer.
	 * @return array{keys:list<array<string,mixed>>}
	 */
	public function fetch( string $issuer ): array;
}
