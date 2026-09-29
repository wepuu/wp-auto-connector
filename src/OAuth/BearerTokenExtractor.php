<?php
/**
 * Strict Authorization-header Bearer extraction.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Accepts one bounded compact JWT and never reads tokens from query/body data. */
final class BearerTokenExtractor {
	private const MAX_HEADER_BYTES = 8192;

	/**
	 * Extract one Bearer credential or return null for another authentication scheme.
	 *
	 * @param string $authorization Complete Authorization header value.
	 * @throws \RuntimeException When a Bearer credential is malformed or ambiguous.
	 */
	public function extract( string $authorization ): ?string {
		if ( '' === $authorization ) {
			return null;
		}
		if ( self::MAX_HEADER_BYTES < strlen( $authorization ) || str_contains( $authorization, "\r" ) || str_contains( $authorization, "\n" ) ) {
			throw new \RuntimeException( 'invalid_authorization_header' );
		}
		if ( 1 !== preg_match( '/\ABearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\z/iD', $authorization, $matches ) ) {
			if ( 1 === preg_match( '/(?:\A|[\s,])Bearer(?:[\s,]|\z)/i', $authorization ) ) {
				throw new \RuntimeException( 'invalid_authorization_header' );
			}
			return null;
		}
		return $matches[1];
	}
}
