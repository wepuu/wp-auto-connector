<?php
/**
 * Independent URL and destination policy for remote media imports.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates and canonicalizes the open-world URL boundary. */
final class RemoteUrlPolicy {
	/**
	 * Normalize one caller supplied HTTP(S) URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error
	 */
	public function normalize( string $url ) {
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || str_contains( $url, '\\' ) ) {
			return $this->invalid_request();
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) || ! is_string( $parts['scheme'] ) ) {
			return $this->invalid_request();
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $this->rejected();
		}
		if ( ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
			return $this->invalid_request();
		}
		if ( array_key_exists( 'user', $parts ) || array_key_exists( 'pass', $parts ) || array_key_exists( 'fragment', $parts ) ) {
			return $this->rejected();
		}
		if ( isset( $parts['port'] ) && ( ! is_int( $parts['port'] ) || ! in_array( $parts['port'], array( 80, 443 ), true ) ) ) {
			return $this->rejected();
		}

		$host = $this->normalize_host( $parts['host'] );
		if ( is_wp_error( $host ) ) {
			return $host;
		}

		$port      = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$path      = isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query     = isset( $parts['query'] ) && is_string( $parts['query'] ) ? '?' . $parts['query'] : '';
		$authority = $host . ( ( 'https' === $scheme ? 443 : 80 ) !== $port ? ':' . $port : '' );

		return $scheme . '://' . $authority . $path . $query;
	}

	/**
	 * Determine whether an address is globally routable for this policy.
	 *
	 * @param string $address IPv4 or IPv6 address.
	 */
	public function is_public_address( string $address ): bool {
		$packed = inet_pton( $address );
		if ( false === $packed ) {
			return false;
		}

		// IPv4-mapped IPv6 addresses are treated as their embedded private IPv4
		// destination, never as an independent public IPv6 address.
		if ( 16 === strlen( $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			return false;
		}

		$cidrs = 4 === strlen( $packed ) ? $this->private_ipv4_cidrs() : $this->private_ipv6_cidrs();
		foreach ( $cidrs as $cidr ) {
			if ( $this->matches_cidr( $packed, $cidr ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize a DNS name and reject ambiguous host syntax.
	 *
	 * @param string $host Candidate host.
	 * @return string|WP_Error
	 */
	private function normalize_host( string $host ) {
		if ( '' === $host || strlen( $host ) > 253 || false !== filter_var( $host, FILTER_VALIDATE_IP ) || $this->is_ambiguous_numeric_host( $host ) ) {
			return $this->rejected();
		}

		$host = rtrim( strtolower( $host ), '.' );
		if ( '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $this->rejected();
		}
		if ( preg_match( '/[^\x21-\x7e]/', $host ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return $this->rejected();
			}
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( ! is_string( $ascii ) || '' === $ascii ) {
				return $this->rejected();
			}
			$host = strtolower( rtrim( $ascii, '.' ) );
		}

		$labels = explode( '.', $host );
		foreach ( $labels as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || '-' === $label[0] || '-' === substr( $label, -1 ) || 1 !== preg_match( '/^[a-z0-9-]+$/D', $label ) ) {
				return $this->rejected();
			}
		}
		return $host;
	}

	/** Reject dotted, octal, hexadecimal, and one-part numeric address forms.
	 *
	 * @param string $host Candidate hostname.
	 */
	private function is_ambiguous_numeric_host( string $host ): bool {
		$labels = explode( '.', $host );
		foreach ( $labels as $label ) {
			if ( 1 !== preg_match( '/^(?:0x[0-9a-f]+|0[0-7]*|[0-9]+)$/iD', $label ) ) {
				return false;
			}
		}
		return array() !== $labels;
	}

	/**
	 * Match packed address bytes against a CIDR string.
	 *
	 * @param string $packed Packed candidate address.
	 * @param string $cidr Network in CIDR notation.
	 */
	private function matches_cidr( string $packed, string $cidr ): bool {
		list( $network, $bits ) = explode( '/', $cidr, 2 );
		$network_packed         = inet_pton( $network );
		$bits                   = (int) $bits;
		if ( false === $network_packed || strlen( $network_packed ) !== strlen( $packed ) ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		if ( $whole_bytes > 0 && substr( $packed, 0, $whole_bytes ) !== substr( $network_packed, 0, $whole_bytes ) ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $remaining ) ) & 0xff;
		return ( ord( $packed[ $whole_bytes ] ) & $mask ) === ( ord( $network_packed[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Return private and special-use IPv4 ranges.
	 *
	 * @return array<int, string>
	 */
	private function private_ipv4_cidrs(): array {
		return array(
			'0.0.0.0/8',
			'10.0.0.0/8',
			'100.64.0.0/10',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'172.16.0.0/12',
			'192.0.0.0/24',
			'192.0.2.0/24',
			'192.88.99.0/24',
			'192.168.0.0/16',
			'198.18.0.0/15',
			'198.51.100.0/24',
			'203.0.113.0/24',
			'224.0.0.0/4',
			'240.0.0.0/4',
		);
	}

	/**
	 * Return private and special-use IPv6 ranges.
	 *
	 * @return array<int, string>
	 */
	private function private_ipv6_cidrs(): array {
		return array(
			'::/128',
			'::1/128',
			'64:ff9b::/96',
			'100::/64',
			'2001:2::/48',
			'2001:10::/28',
			'2001:20::/28',
			'2001:db8::/32',
			'2001::/32',
			'2002::/16',
			'fc00::/7',
			'fe80::/10',
			'ff00::/8',
		);
	}

	/** Return the stable malformed URL error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the stable remote policy error. */
	private function rejected(): WP_Error {
		return new WP_Error( 'wp_auto_remote_media_rejected', __( 'The remote image request was rejected by policy.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}
}
