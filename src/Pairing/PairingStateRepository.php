<?php
/**
 * One-time pairing state storage.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only the verifier digest and consumes every terminal attempt. */
final class PairingStateRepository {
	public const OPTION_NAME  = 'wp_auto_connector_pairing_state';
	private const TTL_SECONDS = 600;

	/**
	 * Start an administrator-approved local attempt.
	 *
	 * @param string   $verifier         One-time verifier.
	 * @param string   $platform_issuer  Exact platform issuer.
	 * @param string   $tenant_id        Tenant UUID.
	 * @param string   $resource_url     Exact MCP resource.
	 * @param int|null $now              Optional clock for tests.
	 * @throws \InvalidArgumentException When an input is invalid.
	 */
	public function begin( string $verifier, string $platform_issuer, string $tenant_id, string $resource_url, ?int $now = null ): bool {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{43,128}\z/', $verifier ) ) {
			throw new \InvalidArgumentException( 'invalid_verifier' );
		}
		$issued_at = $now ?? time();
		$value     = array(
			'version'         => '1',
			'verifier_hash'   => hash( 'sha256', $verifier ),
			'platform_issuer' => ConnectionSettings::validate_issuer( $platform_issuer ),
			'tenant_id'       => ConnectionSettings::validate_tenant( $tenant_id ),
			'resource'        => CanonicalResource::validate( $resource_url ),
			'issued_at'       => $issued_at,
			'expires_at'      => $issued_at + self::TTL_SECONDS,
		);

		return update_option( self::OPTION_NAME, $value, false );
	}

	/**
	 * Consume and return an exact valid platform proof request.
	 *
	 * @param array<string,mixed> $request Decoded JSON request.
	 * @param int|null            $now     Optional clock for tests.
	 * @return array{tenant_id:string,pairing_attempt_id:string,site_id:string,challenge:string,platform_issuer:string,platform_signing_key_pem:string,platform_signing_kid:string,resource:string}
	 * @throws \RuntimeException When the request is invalid, expired, or consumed.
	 */
	public function consume( array $request, ?int $now = null ): array {
		$stored = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $stored ) ) {
			throw new \RuntimeException( 'pairing_denied' );
		}

		$valid = $this->validate_request( $stored, $request, $now ?? time() );
		if ( false === delete_option( self::OPTION_NAME ) ) {
			throw new \RuntimeException( 'pairing_denied' );
		}
		if ( null === $valid ) {
			throw new \RuntimeException( 'pairing_denied' );
		}

		return $valid;
	}

	/** Delete a pending attempt locally. */
	public function cancel(): bool {
		return false !== delete_option( self::OPTION_NAME );
	}

	/**
	 * Validate without exposing a mismatch reason.
	 *
	 * @param array<string,mixed> $stored Stored state.
	 * @param array<string,mixed> $request Incoming request.
	 * @param int                 $now Current Unix timestamp.
	 * @return array{tenant_id:string,pairing_attempt_id:string,site_id:string,challenge:string,platform_issuer:string,platform_signing_key_pem:string,platform_signing_kid:string,resource:string}|null
	 */
	private function validate_request( array $stored, array $request, int $now ): ?array {
		$expected_keys = array( 'challenge', 'pairing_attempt_id', 'platform_issuer', 'platform_signing_key_pem', 'platform_signing_kid', 'protocol_version', 'resource', 'site_id', 'tenant_id', 'verifier' );
		$actual_keys   = array_keys( $request );
		sort( $actual_keys );
		if ( $expected_keys !== $actual_keys ) {
			return null;
		}
		if (
			'1' !== ( $stored['version'] ?? null )
			|| '1' !== $request['protocol_version']
			|| ! is_int( $stored['expires_at'] ?? null )
			|| $stored['expires_at'] <= $now
			|| ! is_string( $request['verifier'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{43,128}\z/', $request['verifier'] )
			|| ! is_string( $stored['verifier_hash'] ?? null )
			|| ! hash_equals( $stored['verifier_hash'], hash( 'sha256', $request['verifier'] ) )
			|| ! is_string( $request['pairing_attempt_id'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $request['pairing_attempt_id'] )
			|| ! is_string( $request['site_id'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $request['site_id'] )
			|| ! is_string( $request['challenge'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{32,128}\z/', $request['challenge'] )
			|| ! is_string( $request['platform_signing_kid'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $request['platform_signing_kid'] )
		) {
			return null;
		}

		try {
			$tenant       = ConnectionSettings::validate_tenant( (string) $request['tenant_id'] );
			$issuer       = ConnectionSettings::validate_issuer( (string) $request['platform_issuer'] );
			$platform_key = ConnectionSettings::validate_platform_signing_key( (string) $request['platform_signing_key_pem'] );
			$resource     = CanonicalResource::validate( (string) $request['resource'] );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		if (
			! hash_equals( (string) ( $stored['tenant_id'] ?? '' ), $tenant )
			|| ! hash_equals( (string) ( $stored['platform_issuer'] ?? '' ), $issuer )
			|| ! hash_equals( (string) ( $stored['resource'] ?? '' ), $resource )
		) {
			return null;
		}

		return array(
			'tenant_id'                => $tenant,
			'pairing_attempt_id'       => $request['pairing_attempt_id'],
			'site_id'                  => $request['site_id'],
			'challenge'                => $request['challenge'],
			'platform_issuer'          => $issuer,
			'platform_signing_key_pem' => $platform_key,
			'platform_signing_kid'     => $request['platform_signing_kid'],
			'resource'                 => $resource,
		);
	}
}
