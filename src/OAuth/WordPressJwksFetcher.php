<?php
/**
 * Bounded WordPress HTTP JWKS fetcher.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses the WordPress safe HTTP client with no redirects. */
final class WordPressJwksFetcher implements JwksFetcherInterface {
	private const MAX_BYTES = 16384;

	/**
	 * Fetch and parse the issuer's fixed JWKS endpoint.
	 *
	 * @param string $issuer Exact platform issuer.
	 * @return array{keys:list<array<string,mixed>>}
	 * @throws \RuntimeException When retrieval or parsing fails.
	 */
	public function fetch( string $issuer ): array {
		$endpoint = rtrim( ConnectionSettings::validate_issuer( $issuer ), '/' ) . '/jwks';
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'jwks_unavailable' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || self::MAX_BYTES < strlen( $body ) ) {
			throw new \RuntimeException( 'jwks_invalid' );
		}
		$data = json_decode( $body, true, 16 );
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'keys' ) || ! is_array( $data['keys'] ) ) {
			throw new \RuntimeException( 'jwks_invalid' );
		}
		return array( 'keys' => array_values( $data['keys'] ) );
	}
}
