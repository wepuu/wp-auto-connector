<?php
/**
 * Site-local signing identity value object.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Holds one validated Ed25519 keypair in memory. */
final class SiteIdentity {
	/**
	 * Store validated key material in memory.
	 *
	 * @param string $kid        Public key identifier.
	 * @param string $secret_key Ed25519 secret key bytes.
	 * @param string $public_key Ed25519 public key bytes.
	 */
	public function __construct(
		private string $kid,
		private string $secret_key,
		private string $public_key
	) {}

	/** Return the public key identifier. */
	public function kid(): string {
		return $this->kid;
	}

	/** Return secret key bytes for the local signer only. */
	public function secret_key(): string {
		return $this->secret_key;
	}

	/** Return public key bytes. */
	public function public_key(): string {
		return $this->public_key;
	}

	/**
	 * Return the public-only JWK.
	 *
	 * @return array{kty:string,crv:string,x:string,kid:string,alg:string,use:string}
	 */
	public function public_jwk(): array {
		return array(
			'kty' => 'OKP',
			'crv' => 'Ed25519',
			'x'   => self::base64url_encode( $this->public_key ),
			'kid' => $this->kid,
			'alg' => 'EdDSA',
			'use' => 'sig',
		);
	}

	/**
	 * Encode JOSE Base64url without padding.
	 *
	 * @param string $value Raw bytes.
	 */
	public static function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7515 encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode strict JOSE Base64url.
	 *
	 * @param string $value Encoded value.
	 * @throws \InvalidArgumentException When the value is malformed.
	 */
	public static function base64url_decode( string $value ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ) {
			throw new \InvalidArgumentException( 'invalid_base64url' );
		}
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 7515 decoding, not obfuscation.
		$decoded = base64_decode( strtr( $value . str_repeat( '=', $padding ), '-_', '+/' ), true );
		if ( false === $decoded ) {
			throw new \InvalidArgumentException( 'invalid_base64url' );
		}
		return $decoded;
	}
}
