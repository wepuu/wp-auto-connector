<?php
/**
 * Strict platform revocation-event verifier.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Verifies signatures and every paired-site event binding. */
final class RevocationEventVerifier {
	private const MAX_BYTES = 16384;

	/**
	 * Construct injectable state and JWKS services.
	 *
	 * @param ConnectionSettings|null $settings Current pairing settings.
	 * @param JwksCache|null          $jwks     Public key cache.
	 */
	public function __construct( private ?ConnectionSettings $settings = null, private ?JwksCache $jwks = null ) {
		$this->settings = $this->settings ?? new ConnectionSettings();
		$this->jwks     = $this->jwks ?? new JwksCache();
	}

	/**
	 * Verify one compact signed event.
	 *
	 * @param string   $compact Compact JWS body.
	 * @param int|null $now     Optional test clock.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When any signature or binding check fails.
	 */
	public function verify( string $compact, ?int $now = null ): array {
		$connection = $this->settings->load();
		if ( null === $connection || 'active' !== $connection['status'] || ! isset( $connection['site_id'] ) || '' === $compact || self::MAX_BYTES < strlen( $compact ) || 2 !== substr_count( $compact, '.' ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
		$segments = explode( '.', $compact );
		$header   = $this->decode_object( $segments[0] );
		if ( array_keys( $header ) !== array( 'alg', 'kid', 'typ' ) || 'RS256' !== $header['alg'] || 'wepuu-revocation+jwt' !== $header['typ'] || ! is_string( $header['kid'] ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
		$keys           = JWK::parseKeySet( array( 'keys' => array_values( $this->jwks->keys( $connection['platform_issuer'], $header['kid'], $now ) ) ), 'RS256' );
		$old_timestamp  = JWT::$timestamp;
		$old_leeway     = JWT::$leeway;
		JWT::$timestamp = $now ?? time();
		JWT::$leeway    = 5;
		try {
			$claims = (array) JWT::decode( $compact, $keys );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'revocation_denied' );
		} finally {
			JWT::$timestamp = $old_timestamp;
			JWT::$leeway    = $old_leeway;
		}
		$this->validate_claims( $claims, $connection, $now ?? time() );
		return $claims;
	}

	/**
	 * Validate the exact event shape and current pairing.
	 *
	 * @param array<string,mixed> $claims     Verified JWT claims.
	 * @param array<string,mixed> $connection Current pairing.
	 * @param int                 $now        Current epoch second.
	 * @throws \RuntimeException When any claim is invalid.
	 */
	private function validate_claims( array $claims, array $connection, int $now ): void {
		$type   = $claims['event_type'] ?? null;
		$base   = array( 'aud', 'event_type', 'exp', 'iat', 'iss', 'kind', 'nbf', 'protocol_version', 'reason', 'sequence', 'site_id', 'tenant_id' );
		$extras = array(
			'grant' => 'grant_id',
			'token' => 'token_jti_hash',
			'key'   => 'key_id',
		);
		$extra  = $extras[ is_string( $type ) ? $type : '' ] ?? null;
		if ( null !== $extra ) {
			$base[] = $extra;
		}
		sort( $base );
		$actual = array_keys( $claims );
		sort( $actual );
		if ( $actual !== $base || ! in_array( $type, array( 'grant', 'site', 'subject', 'token', 'key' ), true ) || 'revocation' !== ( $claims['kind'] ?? null ) || '1' !== ( $claims['protocol_version'] ?? null ) || ! is_string( $claims['iss'] ?? null ) || ! hash_equals( $connection['platform_issuer'], $claims['iss'] ) || ! is_string( $claims['aud'] ?? null ) || ! hash_equals( $connection['resource'], $claims['aud'] ) || ! is_string( $claims['tenant_id'] ?? null ) || ! hash_equals( $connection['tenant_id'], strtolower( $claims['tenant_id'] ) ) || ! is_string( $claims['site_id'] ?? null ) || ! hash_equals( $connection['site_id'], $claims['site_id'] ) || ! is_int( $claims['sequence'] ?? null ) || 1 > $claims['sequence'] || ! is_int( $claims['iat'] ?? null ) || ! is_int( $claims['nbf'] ?? null ) || ! is_int( $claims['exp'] ?? null ) || $claims['iat'] > $now + 5 || $claims['nbf'] > $now + 5 || $claims['exp'] <= $now || $claims['exp'] <= $claims['iat'] || 60 < $claims['exp'] - $claims['iat'] || ! is_string( $claims['reason'] ?? null ) || 1 !== preg_match( '/\A[a-z][a-z0-9_.-]{2,63}\z/', $claims['reason'] ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
		if ( null !== $extra && ( ! is_string( $claims[ $extra ] ?? null ) || 1 !== preg_match( 'token_jti_hash' === $extra ? '/\A[A-Za-z0-9_-]{43}\z/' : '/\A[A-Za-z0-9_-]{8,128}\z/', $claims[ $extra ] ) ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
	}

	/**
	 * Decode an untrusted protected header for key selection only.
	 *
	 * @param string $encoded Base64url object.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When encoding is malformed.
	 */
	private function decode_object( string $encoded ): array {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $encoded ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 7515 decoding.
		$json = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 ), true );
		$data = false === $json ? null : json_decode( $json, true, 8 );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new \RuntimeException( 'revocation_denied' );
		}
		ksort( $data );
		return $data;
	}
}
