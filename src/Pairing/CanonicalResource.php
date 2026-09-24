<?php
/**
 * Canonical MCP resource validation.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the exact RFC 8707 resource for this WordPress site. */
final class CanonicalResource {
	private const ROUTE_SUFFIX = '/wp-json/wp-auto/mcp';

	/** Return the current site's exact MCP resource. */
	public static function current(): string {
		return self::validate( rest_url( 'wp-auto/mcp' ) );
	}

	/**
	 * Validate and normalize an exact HTTPS MCP resource.
	 *
	 * @param string $resource_url Candidate resource URL.
	 * @throws \InvalidArgumentException When the resource is not canonical.
	 */
	public static function validate( string $resource_url ): string {
		if ( '' === $resource_url || false === filter_var( $resource_url, FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException( 'invalid_resource' );
		}

		$parts = wp_parse_url( $resource_url );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			throw new \InvalidArgumentException( 'invalid_resource' );
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( ! str_ends_with( $path, self::ROUTE_SUFFIX ) || str_ends_with( $path, '/' ) ) {
			throw new \InvalidArgumentException( 'invalid_resource' );
		}

		return 'https://' . strtolower( (string) $parts['host'] ) . $path;
	}

	/**
	 * Return the hostname bound into a site proof.
	 *
	 * @param string $resource_url Canonical resource URL.
	 */
	public static function hostname( string $resource_url ): string {
		$parts = wp_parse_url( self::validate( $resource_url ) );
		return strtolower( (string) $parts['host'] );
	}
}
