<?php
/**
 * Strict verification for platform-issued consent requests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use WPAuto\Connector\Pairing\CanonicalResource;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Verifies a short-lived KMS-signed consent request against paired state. */
final class ConsentRequestVerifier {
	private const MAX_COMPACT_BYTES  = 16384;
	private const CLOCK_SKEW_SECONDS = 5;
	private const MAX_TTL_SECONDS    = 120;
	private const SCOPES             = array(
		'mcp:read',
		'mcp:content.write',
		'mcp:media.write',
		'mcp:taxonomy.write',
		'mcp:seo.write',
	);

	/**
	 * Paired connection settings.
	 *
	 * @var ConnectionSettings
	 */
	private ConnectionSettings $settings;

	/**
	 * Construct a verifier bound to local settings.
	 *
	 * @param ConnectionSettings|null $settings Paired settings.
	 */
	public function __construct( ?ConnectionSettings $settings = null ) {
		$this->settings = $settings ?? new ConnectionSettings();
	}

	/**
	 * Verify and normalize a consent request.
	 *
	 * @param string   $compact Compact signed JWT.
	 * @param int|null $now     Optional test clock.
	 * @return array{tenant_id:string,site_id:string,grant_id:string,subject_id:string,client_id:string,scopes:list<string>,challenge:string,platform_issuer:string,resource:string,expires_at:int}
	 * @throws \RuntimeException When verification fails.
	 */
	public function verify( string $compact, ?int $now = null ): array {
		$connection = $this->settings->load();
		if ( null === $connection || 'active' !== $connection['status'] || ! isset( $connection['site_id'], $connection['platform_signing_key_pem'], $connection['platform_signing_kid'] ) ) {
			throw new \RuntimeException( 'connection_not_active' );
		}
		if ( '' === $compact || self::MAX_COMPACT_BYTES < strlen( $compact ) || 2 !== substr_count( $compact, '.' ) ) {
			throw new \RuntimeException( 'invalid_consent_request' );
		}

		$segments = explode( '.', $compact );
		$header   = self::decode_object( $segments[0] );
		$claims   = self::decode_object( $segments[1] );
		self::exact_keys( $header, array( 'alg', 'kid', 'typ' ) );
		self::exact_keys(
			$claims,
			array( 'aud', 'challenge', 'client_id', 'exp', 'grant_id', 'iat', 'iss', 'kind', 'protocol_version', 'resource', 'scope', 'site_id', 'subject_id', 'tenant_id' )
		);
		if (
			'RS256' !== $header['alg']
			|| 'wepuu-consent-request+jwt' !== $header['typ']
			|| ! is_string( $header['kid'] )
			|| ! hash_equals( $connection['platform_signing_kid'], $header['kid'] )
		) {
			throw new \RuntimeException( 'invalid_consent_header' );
		}

		$config = Configuration::forAsymmetricSigner(
			new Sha256(),
			InMemory::empty(),
			InMemory::plainText( $connection['platform_signing_key_pem'] )
		);
		try {
			$token = $config->parser()->parse( $compact );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'invalid_consent_request' );
		}
		if ( ! $config->validator()->validate( $token, new SignedWith( $config->signer(), $config->verificationKey() ) ) ) {
			throw new \RuntimeException( 'invalid_consent_signature' );
		}

		$now = $now ?? time();
		if (
			'consent_request' !== $claims['kind']
			|| '1' !== $claims['protocol_version']
			|| ! is_string( $claims['iss'] )
			|| ! hash_equals( $connection['platform_issuer'], $claims['iss'] )
			|| ! is_string( $claims['aud'] )
			|| ! hash_equals( $connection['resource'], $claims['aud'] )
			|| ! is_string( $claims['resource'] )
			|| ! hash_equals( $connection['resource'], CanonicalResource::validate( $claims['resource'] ) )
			|| ! is_string( $claims['tenant_id'] )
			|| ! hash_equals( $connection['tenant_id'], strtolower( $claims['tenant_id'] ) )
			|| ! is_string( $claims['site_id'] )
			|| ! hash_equals( $connection['site_id'], $claims['site_id'] )
			|| ! is_int( $claims['iat'] )
			|| ! is_int( $claims['exp'] )
			|| $claims['iat'] > $now + self::CLOCK_SKEW_SECONDS
			|| $claims['exp'] <= $now
			|| $claims['exp'] <= $claims['iat']
			|| self::MAX_TTL_SECONDS < $claims['exp'] - $claims['iat']
		) {
			throw new \RuntimeException( 'invalid_consent_claims' );
		}

		return array(
			'tenant_id'       => self::tenant_id( $claims['tenant_id'] ),
			'site_id'         => self::opaque_id( $claims['site_id'] ),
			'grant_id'        => self::opaque_id( $claims['grant_id'] ),
			'subject_id'      => self::opaque_id( $claims['subject_id'] ),
			'client_id'       => self::client_id( $claims['client_id'] ),
			'scopes'          => self::scopes( $claims['scope'] ),
			'challenge'       => self::challenge( $claims['challenge'] ),
			'platform_issuer' => $connection['platform_issuer'],
			'resource'        => $connection['resource'],
			'expires_at'      => $claims['exp'],
		);
	}

	/**
	 * Recheck the current site binding immediately before a consent decision.
	 *
	 * @param array{tenant_id:string,site_id:string,grant_id:string,subject_id:string,client_id:string,scopes:list<string>,challenge:string,platform_issuer:string,resource:string,expires_at:int} $claims   Previously verified pending consent.
	 * @param SiteIdentity                                                                                                                                                                         $identity Current local signing identity.
	 * @return array{control_origin:string,platform_issuer:string,tenant_id:string,resource:string,status:string,site_id:string,site_key_kid:string,platform_signing_key_pem:string,platform_signing_kid:string}
	 * @throws \RuntimeException When pairing changed after preview.
	 */
	public function require_current_binding( array $claims, SiteIdentity $identity ): array {
		$connection = $this->settings->load();
		if (
			null === $connection
			|| 'active' !== $connection['status']
			|| ! isset( $connection['site_id'], $connection['site_key_kid'], $connection['platform_signing_key_pem'], $connection['platform_signing_kid'] )
			|| ! hash_equals( $connection['tenant_id'], $claims['tenant_id'] )
			|| ! hash_equals( $connection['site_id'], $claims['site_id'] )
			|| ! hash_equals( $connection['resource'], $claims['resource'] )
			|| ! hash_equals( $connection['platform_issuer'], $claims['platform_issuer'] )
			|| ! hash_equals( $connection['site_key_kid'], $identity->kid() )
		) {
			throw new \RuntimeException( 'consent_binding_changed' );
		}

		return $connection;
	}

	/**
	 * Decode a strict base64url JSON object.
	 *
	 * @param string $value Encoded object.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When encoding is invalid.
	 */
	private static function decode_object( string $value ): array {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_consent_encoding' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 7515 decoding, not obfuscation.
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
		$data    = false === $decoded ? null : json_decode( $decoded, true, 16, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new \RuntimeException( 'invalid_consent_encoding' );
		}
		return $data;
	}

	/**
	 * Require an exact object member set.
	 *
	 * @param array<string,mixed> $value    Candidate object.
	 * @param array<string>       $expected Expected member names.
	 * @throws \RuntimeException When the set differs.
	 */
	private static function exact_keys( array $value, array $expected ): void {
		$keys = array_keys( $value );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new \RuntimeException( 'unexpected_consent_field' );
		}
	}

	/**
	 * Validate an opaque identifier.
	 *
	 * @param mixed $value Candidate identifier.
	 * @throws \RuntimeException When invalid.
	 */
	private static function opaque_id( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_identifier' );
		}
		return $value;
	}

	/**
	 * Validate a tenant UUID.
	 *
	 * @param mixed $value Candidate tenant UUID.
	 * @throws \RuntimeException When invalid.
	 */
	private static function tenant_id( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value ) ) {
			throw new \RuntimeException( 'invalid_tenant' );
		}
		return strtolower( $value );
	}

	/**
	 * Validate an OAuth client identifier.
	 *
	 * @param mixed $value Candidate client identifier.
	 * @throws \RuntimeException When invalid.
	 */
	private static function client_id( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9._~-]{8,256}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_client' );
		}
		return $value;
	}

	/**
	 * Validate the canonical exact scope set.
	 *
	 * @param mixed $value Candidate scope list.
	 * @return list<string>
	 * @throws \RuntimeException When a scope is unknown, duplicated, or out of order.
	 */
	private static function scopes( mixed $value ): array {
		if ( ! is_array( $value ) || array_values( array_intersect( self::SCOPES, $value ) ) !== $value || count( array_unique( $value ) ) !== count( $value ) || array() === $value ) {
			throw new \RuntimeException( 'invalid_scope' );
		}
		return $value;
	}

	/**
	 * Validate a 256-bit base64url challenge.
	 *
	 * @param mixed $value Candidate challenge.
	 * @throws \RuntimeException When invalid.
	 */
	private static function challenge( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_challenge' );
		}
		return $value;
	}
}
