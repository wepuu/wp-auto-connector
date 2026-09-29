<?php
/**
 * Public test-only RSA fixture shared by OAuth tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

/** Never used by production code or release credentials. */
final class TestRsaFixture {
	/** Return the stable test-only private key. */
	public static function private_key(): string {
		return "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDbpfaikq81SwQS\nX2e0seQ4Tgv0BsXeaqDsF8814ofAR5VTfe46dEG3+SB7u78p/LkHTiMadk/pbzw8\ns8Ztbd7/nUSm0ngsOI2oAU+YZQB1VN4u12WJMqhzD5/EUdRTU7vJr9Nx73a3Ppvi\noIVTH4rnXjU0c1nmZUMYH/k5Ztc/t2ZdBCwxbiavqolAOT9P8TsF8m/J7tMg72Eu\nYfOVzgw5C3IiGPAuAgAj1UIE/20opalb/O98XVMEXykACEPVMEpPRp/oeRk0GG+8\npQcy9CLoTjMhfKNTnihSgqA38EFuC6IJ/u18lr6luNy+y4GJREnIrOiGX5psLuHM\napSNDKdFAgMBAAECggEABgYE37v4kJRUUgGqSScIvG+Nfd1yrzEK5TaY8OAbu27r\nHi1Jq3I1PCuZk7MYILlkxJnEtizQ77SkeQCwHB+jeiyQrad/cq0BW35ftaztaIpR\nhoTTLMJGItOmnL5mvXtCHtuSx6Dax1cw9LPUvC0VBNfNSzkvmbUktCRqVAPpOr7K\njlYk9t//8hn615w8+gYJ03vp7dm3Jyrr8QtiQ1rrUNyFcmVEkRzyty4fNM/Jhvvh\nUls+HX7o+QfLbQ6FrD82dQKHJGP6zcfe+wO4yQ1Die9gwMUohvz2yyF+CfqhPXQ5\nXOvSJeFCGvvEwEsN/jC0ODP/O0G1ylCNB3QtcIaMqQKBgQDyLU9HYVLf7reK/imd\nGXqrA6SPnquOuJbyhk/UXgVF9LuXyjI+rKgUM+1Oo6r5/EBpWXZZxRwivYfBBjww\nk8XjY3Kv6ObWwhqOTSjl/SHUBUOPLyquAQtoyINxDn5n2t9l5V4j0lxCUmxPIZiT\ngEdxK2dibBAyKIUE30VdnNKOTQKBgQDoL3bjveo7PL1xnxkzSTo5nbUfDd0sR9vI\nJjDGH5RC03y2kTPdkl7lVL2yppeY/Pnp6CpJsLLlSmZi55uc68EebFirdfUDjn2K\na8nJtpSDEBB9QMgR3GKQPEpwgp+mEim7kggC3I8GmZxtsuChS97pOMj4MlHScx5d\njIGRAK4o2QKBgQDAzfHgEku4nITj05WtzSssG6pX7SsIZU1HqEbF/FSWbVEsd32p\nCCyIaQ71HLhybbGaLe9baOINhncd5ajlw8A4WGRmSDX/pGkgAa4d7HmSIt62kAaa\noZpDwd9jkvZwGIDizsk0G7X310cDeOvQAsDeCIA2i3IZfMjqKBdBgCjhwQKBgBiE\nKoGRpBHtL/O3YOnRaZx70owc4qWyULqpjazd2MHVou2EF33l3q9Ia19Zx9gXnivc\nn9p4FeuwF2+KFRxUqGeV+SbhpaVifk8HYp8x8CyGnbccCAQayS2BsDqBEGpwsIdl\nvALRVyjTP3k10hI1+KuXm2DZr1oRXbtzAptU/w7BAoGBANFOfMsaa8/5PIVqtbY+\nCQmhniHqtAocNRFRYsDLqX1Mq89lTnHRST5uYU4VbT/gRcdD4S636gniAvn4tb+n\n5cOpxQE+e7VTMGhdKCVb5PR52YG0c9Ll9ge183C6zZJyxW28i6nM43/MWbBYrEID\nz5CKzYCmskD71ZU8wGrK+zJp\n-----END PRIVATE KEY-----\n";
	}

	/**
	 * Derive a public JWK for one fixture key ID.
	 *
	 * @param string $kid Public fixture key ID.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When OpenSSL cannot load the fixture.
	 */
	public static function jwk( string $kid ): array {
		$key     = openssl_pkey_get_private( self::private_key() );
		$details = false === $key ? false : openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) ) {
			throw new \RuntimeException( 'test_key_unavailable' );
		}
		return array(
			'kty' => 'RSA',
			'kid' => $kid,
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => self::b64( $details['rsa']['n'] ),
			'e'   => self::b64( $details['rsa']['e'] ),
		);
	}

	/**
	 * Return the fixture SubjectPublicKeyInfo PEM.
	 *
	 * @throws \RuntimeException When OpenSSL cannot load the fixture.
	 */
	public static function public_key(): string {
		$key     = openssl_pkey_get_private( self::private_key() );
		$details = false === $key ? false : openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) ) {
			throw new \RuntimeException( 'test_key_unavailable' );
		}
		return $details['key'];
	}

	/**
	 * Encode one public-key integer using base64url.
	 *
	 * @param string $value Raw public-key bytes.
	 */
	private static function b64( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7517 fixture encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
