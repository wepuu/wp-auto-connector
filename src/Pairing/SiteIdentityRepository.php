<?php
/**
 * Site-local Ed25519 identity storage.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists the site key only in this WordPress installation. */
final class SiteIdentityRepository {
	public const OPTION_NAME = 'wp_auto_connector_site_identity';

	/**
	 * Load or atomically create the local site identity.
	 *
	 * @throws \RuntimeException When secure key storage is unavailable.
	 */
	public function get_or_create(): SiteIdentity {
		$existing = $this->load();
		if ( $existing instanceof SiteIdentity ) {
			return $existing;
		}

		$keypair    = sodium_crypto_sign_keypair();
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
		$public_key = sodium_crypto_sign_publickey( $keypair );
		$kid        = 'site_' . substr( SiteIdentity::base64url_encode( hash( 'sha256', $public_key, true ) ), 0, 22 );
		$stored     = array(
			'version'    => '1',
			'kid'        => $kid,
			'secret_key' => SiteIdentity::base64url_encode( $secret_key ),
			'public_key' => SiteIdentity::base64url_encode( $public_key ),
			'created_at' => time(),
		);

		if ( ! add_option( self::OPTION_NAME, $stored, '', false ) ) {
			$winner = $this->load();
			if ( $winner instanceof SiteIdentity ) {
				sodium_memzero( $secret_key );
				return $winner;
			}
			throw new \RuntimeException( 'site_identity_unavailable' );
		}

		return new SiteIdentity( $kid, $secret_key, $public_key );
	}

	/** Load and validate stored key material. */
	public function load(): ?SiteIdentity {
		$value = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $value ) || '1' !== ( $value['version'] ?? null ) ) {
			return null;
		}

		try {
			$kid        = (string) ( $value['kid'] ?? '' );
			$secret_key = SiteIdentity::base64url_decode( (string) ( $value['secret_key'] ?? '' ) );
			$public_key = SiteIdentity::base64url_decode( (string) ( $value['public_key'] ?? '' ) );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		if (
			1 !== preg_match( '/\Asite_[A-Za-z0-9_-]{22}\z/', $kid )
			|| SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret_key )
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key )
			|| ! hash_equals( sodium_crypto_sign_publickey_from_secretkey( $secret_key ), $public_key )
		) {
			return null;
		}

		return new SiteIdentity( $kid, $secret_key, $public_key );
	}

	/** Delete the site identity during explicit disconnect/uninstall. */
	public function delete(): bool {
		return false !== delete_option( self::OPTION_NAME );
	}
}
