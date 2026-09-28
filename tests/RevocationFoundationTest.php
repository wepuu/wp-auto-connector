<?php
/**
 * Phase 2.0.4B signed revocation tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use WPAuto\Connector\OAuth\JwksCache;
use WPAuto\Connector\OAuth\RevocationEventVerifier;
use WPAuto\Connector\OAuth\RevocationRateLimiter;
use WPAuto\Connector\OAuth\RevocationStateRepository;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentityRepository;

/** Verifies the signed-event, cache, and monotonic state contract. */
final class RevocationFoundationTest extends TestCase {
	private const TENANT   = '11111111-1111-4111-8111-111111111111';
	private const SITE     = 'site_00000001';
	private const RESOURCE = 'https://example.test/wp-json/wp-auto/mcp';
	private const ISSUER   = 'https://auth.example.test';
	private const KID      = 'kms-key-0001';
	/**
	 * Stable fixture private key.
	 *
	 * @var string
	 */
	private string $private_key;

	/**
	 * Public fixture JWK.
	 *
	 * @var array<string,mixed>
	 */
	private array $jwk;

	/** Create an active paired-site fixture. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']             = array();
		$GLOBALS['wp_auto_test_option_autoload']     = array();
		$GLOBALS['wp_auto_test_rest_url']            = 'https://example.test/wp-json/';
		$GLOBALS['wp_auto_test_current_blog_id']     = 1;
		$GLOBALS['wp_auto_test_update_option_calls'] = 0;
		$this->private_key                           = self::private_key();
		$key = openssl_pkey_get_private( $this->private_key );
		self::assertNotFalse( $key );
		$details = openssl_pkey_get_details( $key );
		self::assertIsArray( $details );
		$this->jwk = array(
			'kty' => 'RSA',
			'kid' => self::KID,
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => self::b64( $details['rsa']['n'] ),
			'e'   => self::b64( $details['rsa']['e'] ),
		);
		$settings  = new ConnectionSettings();
		$identity  = ( new SiteIdentityRepository() )->get_or_create();
		self::assertTrue( $settings->enable( 'https://platform.example.test', self::ISSUER, self::TENANT ) );
		self::assertTrue( $settings->mark_pending() );
		self::assertTrue( $settings->mark_active( self::SITE, $identity->kid(), $details['key'], self::KID ) );
	}

	/** A valid event passes while an audience array fails closed. */
	public function test_exact_signed_event_verifies_and_array_audience_fails(): void {
		$fetcher  = new TestJwksFetcher( $this->jwk );
		$verifier = new RevocationEventVerifier( null, new JwksCache( $fetcher ) );
		$claims   = $verifier->verify( $this->token(), 1000 );
		self::assertSame( 'grant_00000001', $claims['grant_id'] );
		$this->expectException( \RuntimeException::class );
		$verifier->verify( $this->token( array( 'aud' => array( self::RESOURCE ) ) ), 1000 );
	}

	/** An unknown key ID causes one refresh and is then denied. */
	public function test_unknown_kid_refreshes_once_then_denies(): void {
		$fetcher = new TestJwksFetcher( $this->jwk );
		$cache   = new JwksCache( $fetcher );
		self::assertSame( self::KID, $cache->keys( self::ISSUER, self::KID, 1000 )[ self::KID ]['kid'] );
		try {
			$cache->keys( self::ISSUER, 'unknown-key-01', 1001 );
			self::fail( 'Unknown kid must fail.' );
		} catch ( \RuntimeException ) {
			self::assertSame( 2, $fetcher->calls );
		}
	}

	/** An authoritative JWKS removal must override otherwise-safe stale state. */
	public function test_authoritative_key_removal_never_falls_back_to_stale_key(): void {
		$fetcher = new TestJwksFetcher( $this->jwk );
		$cache   = new JwksCache( $fetcher );
		$cache->keys( self::ISSUER, self::KID, 1000 );
		$replacement        = $this->jwk;
		$replacement['kid'] = 'kms-key-0002';
		$fetcher->replace( $replacement );
		$this->expectException( \RuntimeException::class );
		$cache->keys( self::ISSUER, self::KID, 1301 );
	}

	/** Deny state is monotonic, idempotent, hashed, and non-autoloaded. */
	public function test_revocation_state_is_monotonic_idempotent_and_content_free(): void {
		$state = new RevocationStateRepository();
		$event = array(
			'event_type' => 'grant',
			'grant_id'   => 'grant_00000001',
			'sequence'   => 2,
			'reason'     => 'refresh_replay',
		);
		self::assertTrue( $state->apply( $event ) );
		self::assertTrue( $state->apply( $event ) );
		self::assertTrue( $state->denies_grant( 'grant_00000001' ) );
		self::assertFalse( $state->apply( array_merge( $event, array( 'sequence' => 1 ) ) ) );
		$stored = $GLOBALS['wp_auto_test_options'][ RevocationStateRepository::OPTION_NAME ];
		self::assertStringNotContainsString( 'grant_00000001', wp_json_encode( $stored ) );
		self::assertFalse( $GLOBALS['wp_auto_test_option_autoload'][ RevocationStateRepository::OPTION_NAME ] );
	}

	/** The public signed-event endpoint has a site-global fixed ceiling. */
	public function test_revocation_rate_limit_denies_the_sixty_first_request(): void {
		$limiter = new RevocationRateLimiter();
		for ( $count = 0; $count < 60; ++$count ) {
			self::assertTrue( $limiter->allow( 120 ) );
		}
		self::assertFalse( $limiter->allow( 120 ) );
		self::assertTrue( $limiter->allow( 180 ) );
	}

	/**
	 * Build one signed test event.
	 *
	 * @param array<string,mixed> $overrides Claim replacements.
	 */
	private function token( array $overrides = array() ): string {
		$claims = array_merge(
			array(
				'kind'             => 'revocation',
				'protocol_version' => '1',
				'iss'              => self::ISSUER,
				'aud'              => self::RESOURCE,
				'tenant_id'        => self::TENANT,
				'site_id'          => self::SITE,
				'sequence'         => 1,
				'event_type'       => 'grant',
				'grant_id'         => 'grant_00000001',
				'reason'           => 'refresh_replay',
				'iat'              => 1000,
				'nbf'              => 995,
				'exp'              => 1060,
			),
			$overrides
		);
		return JWT::encode( $claims, $this->private_key, 'RS256', self::KID, array( 'typ' => 'wepuu-revocation+jwt' ) );
	}

	/**
	 * Convert fixture key bytes to base64url.
	 *
	 * @param string $value Raw public-key bytes.
	 */
	private static function b64( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7517 encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** Return the stable fixture private key. */
	private static function private_key(): string {
		return "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDbpfaikq81SwQS\nX2e0seQ4Tgv0BsXeaqDsF8814ofAR5VTfe46dEG3+SB7u78p/LkHTiMadk/pbzw8\ns8Ztbd7/nUSm0ngsOI2oAU+YZQB1VN4u12WJMqhzD5/EUdRTU7vJr9Nx73a3Ppvi\noIVTH4rnXjU0c1nmZUMYH/k5Ztc/t2ZdBCwxbiavqolAOT9P8TsF8m/J7tMg72Eu\nYfOVzgw5C3IiGPAuAgAj1UIE/20opalb/O98XVMEXykACEPVMEpPRp/oeRk0GG+8\npQcy9CLoTjMhfKNTnihSgqA38EFuC6IJ/u18lr6luNy+y4GJREnIrOiGX5psLuHM\napSNDKdFAgMBAAECggEABgYE37v4kJRUUgGqSScIvG+Nfd1yrzEK5TaY8OAbu27r\nHi1Jq3I1PCuZk7MYILlkxJnEtizQ77SkeQCwHB+jeiyQrad/cq0BW35ftaztaIpR\nhoTTLMJGItOmnL5mvXtCHtuSx6Dax1cw9LPUvC0VBNfNSzkvmbUktCRqVAPpOr7K\njlYk9t//8hn615w8+gYJ03vp7dm3Jyrr8QtiQ1rrUNyFcmVEkRzyty4fNM/Jhvvh\nUls+HX7o+QfLbQ6FrD82dQKHJGP6zcfe+wO4yQ1Die9gwMUohvz2yyF+CfqhPXQ5\nXOvSJeFCGvvEwEsN/jC0ODP/O0G1ylCNB3QtcIaMqQKBgQDyLU9HYVLf7reK/imd\nGXqrA6SPnquOuJbyhk/UXgVF9LuXyjI+rKgUM+1Oo6r5/EBpWXZZxRwivYfBBjww\nk8XjY3Kv6ObWwhqOTSjl/SHUBUOPLyquAQtoyINxDn5n2t9l5V4j0lxCUmxPIZiT\ngEdxK2dibBAyKIUE30VdnNKOTQKBgQDoL3bjveo7PL1xnxkzSTo5nbUfDd0sR9vI\nJjDGH5RC03y2kTPdkl7lVL2yppeY/Pnp6CpJsLLlSmZi55uc68EebFirdfUDjn2K\na8nJtpSDEBB9QMgR3GKQPEpwgp+mEim7kggC3I8GmZxtsuChS97pOMj4MlHScx5d\njIGRAK4o2QKBgQDAzfHgEku4nITj05WtzSssG6pX7SsIZU1HqEbF/FSWbVEsd32p\nCCyIaQ71HLhybbGaLe9baOINhncd5ajlw8A4WGRmSDX/pGkgAa4d7HmSIt62kAaa\noZpDwd9jkvZwGIDizsk0G7X310cDeOvQAsDeCIA2i3IZfMjqKBdBgCjhwQKBgBiE\nKoGRpBHtL/O3YOnRaZx70owc4qWyULqpjazd2MHVou2EF33l3q9Ia19Zx9gXnivc\nn9p4FeuwF2+KFRxUqGeV+SbhpaVifk8HYp8x8CyGnbccCAQayS2BsDqBEGpwsIdl\nvALRVyjTP3k10hI1+KuXm2DZr1oRXbtzAptU/w7BAoGBANFOfMsaa8/5PIVqtbY+\nCQmhniHqtAocNRFRYsDLqX1Mq89lTnHRST5uYU4VbT/gRcdD4S636gniAvn4tb+n\n5cOpxQE+e7VTMGhdKCVb5PR52YG0c9Ll9ge183C6zZJyxW28i6nM43/MWbBYrEID\nz5CKzYCmskD71ZU8wGrK+zJp\n-----END PRIVATE KEY-----\n";
	}
}
