<?php
/**
 * WordPress/PHP DNS resolver for remote media imports.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves every A and AAAA answer and fails closed on ambiguity. */
final class WordPressRemoteDnsResolver implements RemoteDnsResolverInterface {
	/**
	 * Resolve all address records for a host.
	 *
	 * @param string $host Normalized DNS name.
	 * @return array<int, string>
	 */
	public function resolve( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) || ! defined( 'DNS_A' ) || ! defined( 'DNS_AAAA' ) ) {
			return array();
		}

		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( ! is_array( $records ) || array() === $records ) {
			return array();
		}

		$addresses = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['type'] ) || ! is_string( $record['type'] ) ) {
				return array();
			}
			$key = 'A' === $record['type'] ? 'ip' : ( 'AAAA' === $record['type'] ? 'ipv6' : '' );
			if ( '' === $key || ! isset( $record[ $key ] ) || ! is_string( $record[ $key ] ) ) {
				return array();
			}
			$addresses[] = $record[ $key ];
		}

		return array_values( array_unique( $addresses ) );
	}
}
