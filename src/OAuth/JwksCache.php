<?php
/**
 * Bounded non-autoloaded platform JWKS cache.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements fresh, safe-stale, and one-refresh unknown-kid behavior. */
final class JwksCache {
	public const OPTION_NAME    = 'wp_auto_connector_platform_jwks';
	private const FRESH_SECONDS = 300;
	private const STALE_SECONDS = 1200;

	/**
	 * Construct the cache.
	 *
	 * @param JwksFetcherInterface|null $fetcher Injectable transport.
	 */
	public function __construct( private ?JwksFetcherInterface $fetcher = null ) {
		$this->fetcher = $this->fetcher ?? new WordPressJwksFetcher();
	}

	/**
	 * Resolve a key set containing the requested key ID.
	 *
	 * @param string   $issuer       Exact platform issuer.
	 * @param string   $required_kid Untrusted bounded key identifier.
	 * @param int|null $now          Optional test clock.
	 * @return array<string,array<string,mixed>>
	 * @throws \RuntimeException When no safely usable key set exists.
	 */
	public function keys( string $issuer, string $required_kid, ?int $now = null ): array {
		$now    = $now ?? time();
		$cached = $this->load( $issuer );
		if ( null !== $cached && $cached['fetched_at'] + self::FRESH_SECONDS > $now && isset( $cached['keys'][ $required_kid ] ) ) {
			return $cached['keys'];
		}
		try {
			$fresh = $this->normalize( $this->fetcher->fetch( $issuer ) );
		} catch ( \Throwable ) {
			if ( null !== $cached && $cached['fetched_at'] + self::STALE_SECONDS > $now && isset( $cached['keys'][ $required_kid ] ) ) {
				return $cached['keys'];
			}
			throw new \RuntimeException( 'jwks_unavailable' );
		}
		if ( ! isset( $fresh[ $required_kid ] ) ) {
			// A successful authoritative refresh that omits the key is an
			// emergency removal, never a reason to reuse stale key material.
			throw new \RuntimeException( 'unknown_signing_key' );
		}
		try {
			$record   = array(
				'version'    => '1',
				'issuer'     => $issuer,
				'fetched_at' => $now,
				'keys'       => $fresh,
			);
			$existing = get_option( self::OPTION_NAME, null );
			$saved    = null === $existing
				? add_option( self::OPTION_NAME, $record, '', false )
				: update_option( self::OPTION_NAME, $record, false );
			if ( false === $saved && get_option( self::OPTION_NAME, null ) !== $record ) {
				throw new \RuntimeException( 'jwks_cache_write_failed' );
			}
			return $fresh;
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'jwks_unavailable' );
		}
	}

	/**
	 * Load validated cache state for one issuer.
	 *
	 * @param string $issuer Exact platform issuer.
	 * @return array{fetched_at:int,keys:array<string,array<string,mixed>>}|null
	 */
	private function load( string $issuer ): ?array {
		$value = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $value ) || '1' !== ( $value['version'] ?? null ) || ! is_int( $value['fetched_at'] ?? null ) || ! is_array( $value['keys'] ?? null ) || ! is_string( $value['issuer'] ?? null ) || ! hash_equals( $issuer, $value['issuer'] ) ) {
			return null;
		}
		try {
			return array(
				'fetched_at' => $value['fetched_at'],
				'keys'       => $this->normalize( array( 'keys' => array_values( $value['keys'] ) ) ),
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Admit only a small public RS256 key set.
	 *
	 * @param array<string,mixed> $jwks Candidate JWKS.
	 * @return array<string,array<string,mixed>>
	 * @throws \RuntimeException When the key set is unsafe.
	 */
	private function normalize( array $jwks ): array {
		$values = $jwks['keys'] ?? null;
		if ( ! is_array( $values ) || count( $values ) < 1 || count( $values ) > 5 ) {
			throw new \RuntimeException( 'jwks_invalid' );
		}
		$keys = array();
		foreach ( $values as $key ) {
			if ( ! is_array( $key ) || 'RSA' !== ( $key['kty'] ?? null ) || 'RS256' !== ( $key['alg'] ?? null ) || 'sig' !== ( $key['use'] ?? null ) || ! is_string( $key['kid'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $key['kid'] ) || ! is_string( $key['n'] ?? null ) || ! is_string( $key['e'] ?? null ) || isset( $key['d'] ) || isset( $keys[ $key['kid'] ] ) ) {
				throw new \RuntimeException( 'jwks_invalid' );
			}
			$keys[ $key['kid'] ] = array_intersect_key( $key, array_flip( array( 'kty', 'kid', 'use', 'alg', 'n', 'e' ) ) );
		}
		return $keys;
	}
}
