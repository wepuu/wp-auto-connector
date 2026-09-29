<?php
/**
 * Strict WePuu access-token verification.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use WPAuto\Connector\Grants\LocalGrantRepository;
use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates signed access tokens and resolves their current local grant. */
final class AccessTokenVerifier {
	private const CLOCK_SKEW_SECONDS = 60;
	private const MAX_LIFETIME       = 300;
	private const SCOPES             = array(
		'mcp:read',
		'mcp:content.write',
		'mcp:media.write',
		'mcp:taxonomy.write',
		'mcp:seo.write',
	);

	/**
	 * Build the verifier from the accepted local trust components.
	 *
	 * @param ConnectionSettings|null        $settings    Current pairing settings.
	 * @param JwksCache|null                 $jwks        Bounded issuer JWKS cache.
	 * @param LocalGrantRepository|null      $grants      Local grant mapping.
	 * @param RevocationStateRepository|null $revocations Local deny state.
	 */
	public function __construct(
		private ?ConnectionSettings $settings = null,
		private ?JwksCache $jwks = null,
		private ?LocalGrantRepository $grants = null,
		private ?RevocationStateRepository $revocations = null
	) {
		$this->settings    = $this->settings ?? new ConnectionSettings();
		$this->jwks        = $this->jwks ?? new JwksCache();
		$this->revocations = $this->revocations ?? new RevocationStateRepository();
		$this->grants      = $this->grants ?? new LocalGrantRepository( null, $this->revocations );
	}

	/**
	 * Verify a token and return only the request-local authorization identity.
	 *
	 * @param string   $token Compact access-token JWT.
	 * @param int|null $now   Optional verification clock.
	 * @return array{user_id:int,grant_id:string,client_id:string,subject_id:string,jti:string,scopes:list<string>}
	 * @throws \RuntimeException When any invariant fails.
	 */
	public function verify( string $token, ?int $now = null ): array {
		$now        = $now ?? time();
		$connection = $this->settings->load();
		if ( null === $connection || 'active' !== $connection['status'] || ! isset( $connection['site_id'] ) ) {
			throw new \RuntimeException( 'integration_inactive' );
		}

		$header            = $this->header( $token );
		$kid               = $this->header_string( $header, 'kid', '/\A[A-Za-z0-9_-]{8,128}\z/' );
		$forbidden_headers = array( 'crit', 'jku', 'jwk', 'x5u' );
		$has_forbidden     = array_intersect( $forbidden_headers, array_keys( $header ) );
		if ( 'at+jwt' !== ( $header['typ'] ?? null ) || 'RS256' !== ( $header['alg'] ?? null ) || array() !== $has_forbidden ) {
			throw new \RuntimeException( 'invalid_token_header' );
		}
		if ( $this->revocations->denies_key( $kid ) ) {
			throw new \RuntimeException( 'revoked_signing_key' );
		}

		$jwks = $this->jwks->keys( $connection['platform_issuer'], $kid, $now );
		try {
			$keys = JWK::parseKeySet( array( 'keys' => array_values( $jwks ) ) );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'invalid_signing_key' );
		}

		$old_timestamp = JWT::$timestamp;
		$old_leeway    = JWT::$leeway;
		try {
			JWT::$timestamp = $now;
			JWT::$leeway    = self::CLOCK_SKEW_SECONDS;
			$decoded        = JWT::decode( $token, $keys );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'invalid_access_token' );
		} finally {
			JWT::$timestamp = $old_timestamp;
			JWT::$leeway    = $old_leeway;
		}

		$claims = get_object_vars( $decoded );
		$this->exact_string( $claims, 'iss', $connection['platform_issuer'] );
		$this->exact_string( $claims, 'aud', $connection['resource'] );
		$this->exact_string( $claims, 'tenant_id', $connection['tenant_id'] );
		$this->exact_string( $claims, 'site_id', $connection['site_id'] );
		$grant_id  = $this->opaque_claim( $claims, 'grant_id' );
		$subject   = $this->opaque_claim( $claims, 'sub' );
		$jti       = $this->opaque_claim( $claims, 'jti' );
		$client_id = $this->client_claim( $claims );
		$scopes    = $this->scopes( $claims['scope'] ?? null );
		$this->times( $claims, $now );

		if ( $this->revocations->denies_token_hash( hash( 'sha256', $jti ) ) ) {
			throw new \RuntimeException( 'revoked_access_token' );
		}
		$grant = $this->grants->find_active( $grant_id, $this->settings );
		if ( null === $grant || ! hash_equals( $grant['client_id'], $client_id ) ) {
			throw new \RuntimeException( 'invalid_grant_binding' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! in_array( $scope, $grant['scopes'], true ) ) {
				throw new \RuntimeException( 'scope_exceeds_local_grant' );
			}
		}

		return array(
			'user_id'    => $grant['user_id'],
			'grant_id'   => $grant_id,
			'client_id'  => $client_id,
			'subject_id' => $subject,
			'jti'        => $jti,
			'scopes'     => $scopes,
		);
	}

	/**
	 * Decode only the bounded JOSE header through the reviewed JWT library.
	 *
	 * @param string $token Compact access-token JWT.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When the token structure is invalid.
	 */
	private function header( string $token ): array {
		if ( strlen( $token ) > 8192 ) {
			throw new \RuntimeException( 'invalid_token_structure' );
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] || '' === $parts[2] ) {
			throw new \RuntimeException( 'invalid_token_structure' );
		}
		try {
			$value = JWT::jsonDecode( JWT::urlsafeB64Decode( $parts[0] ) );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'invalid_token_header' );
		}
		if ( ! is_object( $value ) ) {
			throw new \RuntimeException( 'invalid_token_header' );
		}
		return get_object_vars( $value );
	}

	/**
	 * Validate a required JOSE string.
	 *
	 * @param array<string,mixed> $values  JOSE header.
	 * @param string              $name    Header member name.
	 * @param string              $pattern Allow-list expression.
	 * @throws \RuntimeException When the member is invalid.
	 */
	private function header_string( array $values, string $name, string $pattern ): string {
		$value = $values[ $name ] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( $pattern, $value ) ) {
			throw new \RuntimeException( 'invalid_token_header' );
		}
		return $value;
	}

	/**
	 * Enforce one exact string claim.
	 *
	 * @param array<string,mixed> $claims   Verified claims.
	 * @param string              $name     Claim name.
	 * @param string              $expected Exact local value.
	 * @throws \RuntimeException When the claim differs.
	 */
	private function exact_string( array $claims, string $name, string $expected ): void {
		$value = $claims[ $name ] ?? null;
		if ( ! is_string( $value ) || ! hash_equals( $expected, $value ) ) {
			throw new \RuntimeException( 'invalid_exact_claim' );
		}
	}

	/**
	 * Read one bounded opaque identifier claim.
	 *
	 * @param array<string,mixed> $claims Verified claims.
	 * @param string              $name   Claim name.
	 * @throws \RuntimeException When the claim is malformed.
	 */
	private function opaque_claim( array $claims, string $name ): string {
		$value = $claims[ $name ] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_opaque_claim' );
		}
		return $value;
	}

	/**
	 * Read the bounded OAuth client claim.
	 *
	 * @param array<string,mixed> $claims Verified claims.
	 * @throws \RuntimeException When the client identifier is malformed.
	 */
	private function client_claim( array $claims ): string {
		$value = $claims['client_id'] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9._~-]{8,256}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_client_id' );
		}
		return $value;
	}

	/**
	 * Parse the canonical space-delimited scope ceiling.
	 *
	 * @param mixed $value Untrusted scope claim.
	 * @return list<string>
	 * @throws \RuntimeException When scope is unknown, duplicated, or unordered.
	 */
	private function scopes( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 512 || 1 !== preg_match( '/\Amcp:[a-z][a-z0-9_.-]{0,63}(?: mcp:[a-z][a-z0-9_.-]{0,63}){0,4}\z/', $value ) ) {
			throw new \RuntimeException( 'invalid_scope' );
		}
		$scopes = explode( ' ', $value );
		if ( count( $scopes ) !== count( array_unique( $scopes ) ) ) {
			throw new \RuntimeException( 'invalid_scope' );
		}
		$canonical = array_values( array_intersect( self::SCOPES, $scopes ) );
		if ( $canonical !== $scopes ) {
			throw new \RuntimeException( 'invalid_scope' );
		}
		return $scopes;
	}

	/**
	 * Validate required integer NumericDate claims and five-minute lifetime.
	 *
	 * @param array<string,mixed> $claims Verified claims.
	 * @param int                 $now    Verification clock.
	 * @throws \RuntimeException When a time invariant fails.
	 */
	private function times( array $claims, int $now ): void {
		foreach ( array( 'iat', 'nbf', 'exp' ) as $name ) {
			if ( ! isset( $claims[ $name ] ) || ! is_int( $claims[ $name ] ) ) {
				throw new \RuntimeException( 'invalid_token_time' );
			}
		}
		$iat = $claims['iat'];
		$nbf = $claims['nbf'];
		$exp = $claims['exp'];
		if ( $iat > $now + self::CLOCK_SKEW_SECONDS || $nbf > $now + self::CLOCK_SKEW_SECONDS || $exp <= $iat || $exp <= $nbf || $exp - $iat > self::MAX_LIFETIME || $nbf < $iat - self::CLOCK_SKEW_SECONDS ) {
			throw new \RuntimeException( 'invalid_token_time' );
		}
	}
}
