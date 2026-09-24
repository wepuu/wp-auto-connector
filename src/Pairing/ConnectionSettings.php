<?php
/**
 * Local platform connection settings.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only public connection metadata. The integration is disabled by default. */
final class ConnectionSettings {
	public const OPTION_NAME = 'wp_auto_connector_platform_connection';

	/**
	 * Read a validated enabled configuration.
	 *
	 * @return array{control_origin:string,platform_issuer:string,tenant_id:string,resource:string,status:string,site_id?:string,site_key_kid?:string,platform_signing_key_pem?:string,platform_signing_kid?:string}|null
	 */
	public function load(): ?array {
		$value = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $value ) || true !== ( $value['enabled'] ?? false ) ) {
			return null;
		}

		try {
			$control  = self::validate_control_origin( (string) ( $value['control_origin'] ?? '' ) );
			$issuer   = self::validate_issuer( (string) ( $value['platform_issuer'] ?? '' ) );
			$tenant   = self::validate_tenant( (string) ( $value['tenant_id'] ?? '' ) );
			$resource = CanonicalResource::validate( (string) ( $value['resource'] ?? '' ) );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		$status = (string) ( $value['status'] ?? 'unpaired' );
		if ( ! in_array( $status, array( 'unpaired', 'pending', 'active', 'suspended' ), true ) ) {
			return null;
		}

		$result = array(
			'control_origin'  => $control,
			'platform_issuer' => $issuer,
			'tenant_id'       => $tenant,
			'resource'        => $resource,
			'status'          => $status,
		);
		if ( isset( $value['site_id'] ) && self::is_opaque_id( $value['site_id'] ) ) {
			$result['site_id'] = $value['site_id'];
		}
		if ( isset( $value['site_key_kid'] ) && is_string( $value['site_key_kid'] ) && 1 === preg_match( '/\Asite_[A-Za-z0-9_-]{22}\z/', $value['site_key_kid'] ) ) {
			$result['site_key_kid'] = $value['site_key_kid'];
		}
		if ( isset( $value['platform_signing_key_pem'] ) && is_string( $value['platform_signing_key_pem'] ) ) {
			try {
				$result['platform_signing_key_pem'] = self::validate_platform_signing_key( $value['platform_signing_key_pem'] );
			} catch ( \InvalidArgumentException ) {
				return null;
			}
		}
		if ( isset( $value['platform_signing_kid'] ) && self::is_opaque_id( $value['platform_signing_kid'] ) ) {
			$result['platform_signing_kid'] = $value['platform_signing_kid'];
		}
		if ( 'active' === $status && ! isset( $result['site_id'], $result['site_key_kid'], $result['platform_signing_key_pem'], $result['platform_signing_kid'] ) ) {
			return null;
		}

		try {
			$current_resource = CanonicalResource::current();
		} catch ( \Throwable ) {
			$current_resource = null;
		}

		if ( null === $current_resource || ! hash_equals( $result['resource'], $current_resource ) ) {
			$result['status'] = 'suspended';
			if ( 'suspended' !== $status ) {
				$value['status'] = 'suspended';
				try {
					update_option( self::OPTION_NAME, $value, false );
				} catch ( \Throwable ) {
					return $result;
				}
			}
		}

		return $result;
	}

	/**
	 * Explicitly enable an unpaired integration.
	 *
	 * @param string $control_origin  Exact HTTPS control-plane origin.
	 * @param string $platform_issuer Exact HTTPS platform issuer.
	 * @param string $tenant_id       Tenant UUID selected by the administrator.
	 */
	public function enable( string $control_origin, string $platform_issuer, string $tenant_id ): bool {
		$value = array(
			'enabled'         => true,
			'control_origin'  => self::validate_control_origin( $control_origin ),
			'platform_issuer' => self::validate_issuer( $platform_issuer ),
			'tenant_id'       => self::validate_tenant( $tenant_id ),
			'resource'        => CanonicalResource::current(),
			'status'          => 'unpaired',
		);

		return update_option( self::OPTION_NAME, $value, false );
	}

	/**
	 * Validate a strict HTTPS origin without path, query, or fragment.
	 *
	 * @param string $origin Candidate control-plane origin.
	 * @throws \InvalidArgumentException When the origin is invalid.
	 */
	public static function validate_control_origin( string $origin ): string {
		self::validate_issuer( $origin );
		$parts = wp_parse_url( $origin );
		if ( '/' !== (string) ( $parts['path'] ?? '/' ) ) {
			throw new \InvalidArgumentException( 'invalid_control_origin' );
		}
		return rtrim( $origin, '/' );
	}

	/** Mark an explicitly started pairing attempt. */
	public function mark_pending(): bool {
		$value = $this->load();
		if ( null === $value || 'unpaired' !== $value['status'] ) {
			return false;
		}
		$value['enabled'] = true;
		$value['status']  = 'pending';
		return update_option( self::OPTION_NAME, $value, false );
	}

	/**
	 * Bind the active platform site to the current local site key.
	 *
	 * @param string $site_id      Opaque platform site identifier.
	 * @param string $site_key_kid            Current site-key identifier.
	 * @param string $platform_signing_key_pem Pinned platform RSA public key.
	 * @param string $platform_signing_kid     Pinned platform signing key ID.
	 */
	public function mark_active( string $site_id, string $site_key_kid, string $platform_signing_key_pem, string $platform_signing_kid ): bool {
		$value = $this->load();
		if ( null === $value || 'pending' !== $value['status'] || ! self::is_opaque_id( $site_id ) || ! self::is_opaque_id( $platform_signing_kid ) || 1 !== preg_match( '/\Asite_[A-Za-z0-9_-]{22}\z/', $site_key_kid ) ) {
			return false;
		}
		$value['enabled']                  = true;
		$value['status']                   = 'active';
		$value['site_id']                  = $site_id;
		$value['site_key_kid']             = $site_key_kid;
		$value['platform_signing_key_pem'] = self::validate_platform_signing_key( $platform_signing_key_pem );
		$value['platform_signing_kid']     = $platform_signing_kid;
		return update_option( self::OPTION_NAME, $value, false );
	}

	/**
	 * Validate a bounded RSA public key used only for platform consent requests.
	 *
	 * @param string $pem Candidate SubjectPublicKeyInfo PEM.
	 * @throws \InvalidArgumentException When the key is malformed or too small.
	 */
	public static function validate_platform_signing_key( string $pem ): string {
		if ( strlen( $pem ) > 8192 || 1 !== preg_match( '/\A-----BEGIN PUBLIC KEY-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END PUBLIC KEY-----\r?\n?\z/', $pem ) ) {
			throw new \InvalidArgumentException( 'invalid_platform_signing_key' );
		}
		$key     = openssl_pkey_get_public( $pem );
		$details = false === $key ? false : openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== ( $details['type'] ?? null ) || 2048 > ( $details['bits'] ?? 0 ) ) {
			throw new \InvalidArgumentException( 'invalid_platform_signing_key' );
		}
		return $pem;
	}

	/** Disable the integration locally before any remote notification. */
	public function disconnect(): bool {
		return false !== delete_option( self::OPTION_NAME );
	}

	/**
	 * Validate an HTTPS platform issuer.
	 *
	 * @param string $issuer Candidate issuer.
	 * @throws \InvalidArgumentException When the issuer is invalid.
	 */
	public static function validate_issuer( string $issuer ): string {
		if ( '' === $issuer || strlen( $issuer ) > 2048 || false === filter_var( $issuer, FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException( 'invalid_platform_issuer' );
		}
		$parts = wp_parse_url( $issuer );
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
			throw new \InvalidArgumentException( 'invalid_platform_issuer' );
		}
		return $issuer;
	}

	/**
	 * Validate a tenant UUID.
	 *
	 * @param string $tenant_id Candidate tenant UUID.
	 * @throws \InvalidArgumentException When the UUID is invalid.
	 */
	public static function validate_tenant( string $tenant_id ): string {
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $tenant_id ) ) {
			throw new \InvalidArgumentException( 'invalid_tenant' );
		}
		return strtolower( $tenant_id );
	}

	/**
	 * Check one bounded opaque identifier.
	 *
	 * @param mixed $value Candidate value.
	 */
	private static function is_opaque_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $value );
	}
}
