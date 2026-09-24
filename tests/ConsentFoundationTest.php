<?php
/**
 * Consent request and one-shot state tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Grants\ConsentController;
use WPAuto\Connector\Grants\ConsentRequestVerifier;
use WPAuto\Connector\Grants\PendingConsentRepository;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentityRepository;
use WPAuto\Connector\Pairing\SiteProofSigner;

/** Verifies strict KMS request handling and local replay protection. */
final class ConsentFoundationTest extends TestCase {
	private const TENANT   = '11111111-1111-4111-8111-111111111111';
	private const SITE_ID  = 'site_00000001';
	private const RESOURCE = 'https://example.test/wp-json/wp-auto/mcp';
	private const ISSUER   = 'https://auth.example.test';
	private const KID      = 'kms-key-0001';

	/** Reset all WordPress option state that can affect paired settings. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']                         = array();
		$GLOBALS['wp_auto_test_option_autoload']                 = array();
		$GLOBALS['wp_auto_test_current_blog_id']                 = 1;
		$GLOBALS['wp_auto_test_use_option_cache']                = false;
		$GLOBALS['wp_auto_test_fail_update_option']              = false;
		$GLOBALS['wp_auto_test_update_option_calls']             = 0;
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = null;
		$GLOBALS['wp_auto_test_fail_delete_option']              = false;
		$GLOBALS['wp_auto_test_delete_option_calls']             = 0;
		$GLOBALS['wp_auto_test_rest_url']                        = 'https://example.test/wp-json/';
		$settings = new ConnectionSettings();
		$identity = ( new SiteIdentityRepository() )->get_or_create();
		self::assertTrue( $settings->enable( 'https://platform.example.test', self::ISSUER, self::TENANT ) );
		self::assertTrue( $settings->mark_pending() );
		self::assertTrue( $settings->mark_active( self::SITE_ID, $identity->kid(), self::public_key(), self::KID ) );
		self::assertNotNull( $settings->load() );
	}

	/** The production plugin entry point must load every consent dependency before boot. */
	public function test_plugin_entrypoint_loads_consent_dependencies(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local immutable test source, not a URL.
		$entrypoint = file_get_contents( dirname( __DIR__ ) . '/wepuu-auto-connector.php' );
		self::assertIsString( $entrypoint );
		self::assertStringContainsString( 'src/Grants/ConsentRequestVerifier.php', $entrypoint );
		self::assertStringContainsString( 'src/Grants/PendingConsentRepository.php', $entrypoint );
		self::assertStringContainsString( 'src/Grants/ConsentController.php', $entrypoint );
	}

	/** Consent pages install an early header hook so opaque handles never enter Referer logs. */
	public function test_consent_page_registers_early_referrer_policy_hook(): void {
		$controller = new ConsentController();
		$controller->register();
		self::assertArrayHasKey( 'admin_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame(
			array( $controller, 'protect_consent_page_headers' ),
			$GLOBALS['wp_auto_test_hooks']['admin_init']
		);
	}

	/** A valid exact request verifies and an audience array or unknown scope fails closed. */
	public function test_consent_request_verifies_exact_profile(): void {
		$verifier = new ConsentRequestVerifier();
		$claims   = $verifier->verify( $this->signed_request(), 1000 );
		self::assertSame( self::SITE_ID, $claims['site_id'] );
		self::assertSame( array( 'mcp:read', 'mcp:content.write' ), $claims['scopes'] );

		$this->expectException( \RuntimeException::class );
		$verifier->verify( $this->signed_request( array( 'aud' => array( self::RESOURCE ) ) ), 1000 );
	}

	/** Header key rotation is pinned and unexpected claims are rejected. */
	public function test_consent_request_rejects_wrong_kid_and_extra_claim(): void {
		$verifier = new ConsentRequestVerifier();
		try {
			$verifier->verify( $this->signed_request( array(), 'kms-key-evil' ), 1000 );
			self::fail( 'Wrong kid must fail.' );
		} catch ( \RuntimeException ) {
			self::assertTrue( true );
		}
		$this->expectException( \RuntimeException::class );
		$verifier->verify( $this->signed_request( array( 'unexpected' => 'content-canary' ) ), 1000 );
	}

	/** A resource change between preview and decision invalidates the binding. */
	public function test_consent_decision_rechecks_current_resource_binding(): void {
		$verifier = new ConsentRequestVerifier();
		$claims   = $verifier->verify( $this->signed_request(), 1000 );
		$identity = ( new SiteIdentityRepository() )->load();
		self::assertNotNull( $identity );
		self::assertSame( self::SITE_ID, $verifier->require_current_binding( $claims, $identity )['site_id'] );

		$GLOBALS['wp_auto_test_rest_url'] = 'https://moved.example.test/wp-json/';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'consent_binding_changed' );
		$verifier->require_current_binding( $claims, $identity );
	}

	/** Re-pairing never makes a previously previewed consent current again. */
	public function test_old_consent_binding_is_rejected_after_explicit_repair(): void {
		$verifier     = new ConsentRequestVerifier();
		$claims       = $verifier->verify( $this->signed_request(), 1000 );
		$settings     = new ConnectionSettings();
		$identities   = new SiteIdentityRepository();
		$old_identity = $identities->load();
		self::assertNotNull( $old_identity );

		self::assertTrue( $settings->disconnect() );
		self::assertTrue( $identities->delete() );
		self::assertTrue( $settings->enable( 'https://platform.example.test', self::ISSUER, self::TENANT ) );
		self::assertTrue( $settings->mark_pending() );
		$new_identity = $identities->get_or_create();
		self::assertNotSame( $old_identity->kid(), $new_identity->kid() );
		self::assertTrue( $settings->mark_active( 'site_00000002', $new_identity->kid(), self::public_key(), self::KID ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'consent_binding_changed' );
		$verifier->require_current_binding( $claims, $new_identity );
	}

	/** Pending consent is non-autoloaded, user-bound, expiring, and exactly once. */
	public function test_pending_consent_is_user_bound_and_single_use(): void {
		$repository = new PendingConsentRepository();
		$claims     = array(
			'grant_id'   => 'grant_00000001',
			'expires_at' => 1060,
		);
		$handle     = $repository->create( $claims, 7 );
		self::assertNull( $repository->find( $handle, 8, 1000 ) );
		try {
			$repository->consume( $handle, 8, 1000 );
			self::fail( 'A different local user must not consume pending consent.' );
		} catch ( \RuntimeException ) {
			self::assertSame( $claims, $repository->find( $handle, 7, 1000 ) );
		}
		self::assertSame( false, $GLOBALS['wp_auto_test_option_autoload'][ PendingConsentRepository::option_name( $handle ) ] );
		self::assertSame( $claims, $repository->consume( $handle, 7, 1000 ) );
		$this->expectException( \RuntimeException::class );
		$repository->consume( $handle, 7, 1000 );
	}

	/** An uncertain pending-state deletion remains fail-closed and cannot replay. */
	public function test_pending_consent_delete_failure_remains_consumed(): void {
		$repository                                 = new PendingConsentRepository();
		$handle                                     = $repository->create(
			array(
				'grant_id'   => 'grant_00000001',
				'expires_at' => 1060,
			),
			7
		);
		$GLOBALS['wp_auto_test_fail_delete_option'] = true;
		try {
			$repository->consume( $handle, 7, 1000 );
			self::fail( 'Uncertain deletion must fail closed.' );
		} catch ( \RuntimeException ) {
			$GLOBALS['wp_auto_test_fail_delete_option'] = false;
		}
		$this->expectException( \RuntimeException::class );
		$repository->consume( $handle, 7, 1000 );
	}

	/** Consent proof binds an exact singleton resource audience and decision. */
	public function test_consent_proof_binds_singleton_audience_and_decision(): void {
		$identity = ( new SiteIdentityRepository() )->get_or_create();
		$proof    = ( new SiteProofSigner() )->sign_consent(
			$identity,
			array(
				'tenant_id'       => self::TENANT,
				'site_id'         => self::SITE_ID,
				'grant_id'        => 'grant_00000001',
				'subject_id'      => 'account_00000001',
				'client_id'       => 'client.example~01',
				'scopes'          => array( 'mcp:read' ),
				'challenge'       => str_repeat( 'c', 43 ),
				'platform_issuer' => self::ISSUER,
				'resource'        => self::RESOURCE,
				'decision'        => 'denied',
			),
			new \DateTimeImmutable( '@1000' )
		);
		$segments = explode( '.', $proof );
		$payload  = json_decode( self::base64url_decode( $segments[1] ), true );
		self::assertSame( self::RESOURCE, $payload['aud'] );
		self::assertSame( 'denied', $payload['decision'] );
		self::assertSame( 'client.example~01', $payload['client_id'] );
		self::assertIsInt( $payload['iat'] );
		self::assertIsInt( $payload['exp'] );
	}

	/**
	 * Build a compact RS256 test request with PHP OpenSSL.
	 *
	 * @param array<string,mixed> $overrides Claim overrides.
	 * @param string              $kid       Protected key identifier.
	 */
	private function signed_request( array $overrides = array(), string $kid = self::KID ): string {
		$claims  = array_merge(
			array(
				'kind'             => 'consent_request',
				'protocol_version' => '1',
				'iss'              => self::ISSUER,
				'aud'              => self::RESOURCE,
				'tenant_id'        => self::TENANT,
				'site_id'          => self::SITE_ID,
				'grant_id'         => 'grant_00000001',
				'subject_id'       => 'account_00000001',
				'client_id'        => 'client_00000001',
				'resource'         => self::RESOURCE,
				'scope'            => array( 'mcp:read', 'mcp:content.write' ),
				'challenge'        => str_repeat( 'c', 43 ),
				'iat'              => 1000,
				'exp'              => 1060,
			),
			$overrides
		);
		$header  = array(
			'typ' => 'wepuu-consent-request+jwt',
			'alg' => 'RS256',
			'kid' => $kid,
		);
		$input   = self::base64url( (string) wp_json_encode( $header ) ) . '.' . self::base64url( (string) wp_json_encode( $claims ) );
		$success = openssl_sign( $input, $signature, self::private_key(), OPENSSL_ALGO_SHA256 );
		self::assertTrue( $success );
		return $input . '.' . self::base64url( $signature );
	}

	/**
	 * Encode raw test bytes as unpadded base64url.
	 *
	 * @param string $value Raw bytes.
	 */
	private static function base64url( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7515 test fixture encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode unpadded base64url test data.
	 *
	 * @param string $value Encoded bytes.
	 */
	private static function base64url_decode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 7515 test fixture decoding.
		return (string) base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
	}

	/** Return the stable test private key. */
	private static function private_key(): string {
		return "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDbpfaikq81SwQS\nX2e0seQ4Tgv0BsXeaqDsF8814ofAR5VTfe46dEG3+SB7u78p/LkHTiMadk/pbzw8\ns8Ztbd7/nUSm0ngsOI2oAU+YZQB1VN4u12WJMqhzD5/EUdRTU7vJr9Nx73a3Ppvi\noIVTH4rnXjU0c1nmZUMYH/k5Ztc/t2ZdBCwxbiavqolAOT9P8TsF8m/J7tMg72Eu\nYfOVzgw5C3IiGPAuAgAj1UIE/20opalb/O98XVMEXykACEPVMEpPRp/oeRk0GG+8\npQcy9CLoTjMhfKNTnihSgqA38EFuC6IJ/u18lr6luNy+y4GJREnIrOiGX5psLuHM\napSNDKdFAgMBAAECggEABgYE37v4kJRUUgGqSScIvG+Nfd1yrzEK5TaY8OAbu27r\nHi1Jq3I1PCuZk7MYILlkxJnEtizQ77SkeQCwHB+jeiyQrad/cq0BW35ftaztaIpR\nhoTTLMJGItOmnL5mvXtCHtuSx6Dax1cw9LPUvC0VBNfNSzkvmbUktCRqVAPpOr7K\njlYk9t//8hn615w8+gYJ03vp7dm3Jyrr8QtiQ1rrUNyFcmVEkRzyty4fNM/Jhvvh\nUls+HX7o+QfLbQ6FrD82dQKHJGP6zcfe+wO4yQ1Die9gwMUohvz2yyF+CfqhPXQ5\nXOvSJeFCGvvEwEsN/jC0ODP/O0G1ylCNB3QtcIaMqQKBgQDyLU9HYVLf7reK/imd\nGXqrA6SPnquOuJbyhk/UXgVF9LuXyjI+rKgUM+1Oo6r5/EBpWXZZxRwivYfBBjww\nk8XjY3Kv6ObWwhqOTSjl/SHUBUOPLyquAQtoyINxDn5n2t9l5V4j0lxCUmxPIZiT\ngEdxK2dibBAyKIUE30VdnNKOTQKBgQDoL3bjveo7PL1xnxkzSTo5nbUfDd0sR9vI\nJjDGH5RC03y2kTPdkl7lVL2yppeY/Pnp6CpJsLLlSmZi55uc68EebFirdfUDjn2K\na8nJtpSDEBB9QMgR3GKQPEpwgp+mEim7kggC3I8GmZxtsuChS97pOMj4MlHScx5d\njIGRAK4o2QKBgQDAzfHgEku4nITj05WtzSssG6pX7SsIZU1HqEbF/FSWbVEsd32p\nCCyIaQ71HLhybbGaLe9baOINhncd5ajlw8A4WGRmSDX/pGkgAa4d7HmSIt62kAaa\noZpDwd9jkvZwGIDizsk0G7X310cDeOvQAsDeCIA2i3IZfMjqKBdBgCjhwQKBgBiE\nKoGRpBHtL/O3YOnRaZx70owc4qWyULqpjazd2MHVou2EF33l3q9Ia19Zx9gXnivc\nn9p4FeuwF2+KFRxUqGeV+SbhpaVifk8HYp8x8CyGnbccCAQayS2BsDqBEGpwsIdl\nvALRVyjTP3k10hI1+KuXm2DZr1oRXbtzAptU/w7BAoGBANFOfMsaa8/5PIVqtbY+\nCQmhniHqtAocNRFRYsDLqX1Mq89lTnHRST5uYU4VbT/gRcdD4S636gniAvn4tb+n\n5cOpxQE+e7VTMGhdKCVb5PR52YG0c9Ll9ge183C6zZJyxW28i6nM43/MWbBYrEID\nz5CKzYCmskD71ZU8wGrK+zJp\n-----END PRIVATE KEY-----\n";
	}

	/** Return the matching stable test public key. */
	private static function public_key(): string {
		return "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA26X2opKvNUsEEl9ntLHk\nOE4L9AbF3mqg7BfPNeKHwEeVU33uOnRBt/kge7u/Kfy5B04jGnZP6W88PLPGbW3e\n/51EptJ4LDiNqAFPmGUAdVTeLtdliTKocw+fxFHUU1O7ya/Tce92tz6b4qCFUx+K\n5141NHNZ5mVDGB/5OWbXP7dmXQQsMW4mr6qJQDk/T/E7BfJvye7TIO9hLmHzlc4M\nOQtyIhjwLgIAI9VCBP9tKKWpW/zvfF1TBF8pAAhD1TBKT0af6HkZNBhvvKUHMvQi\n6E4zIXyjU54oUoKgN/BBbguiCf7tfJa+pbjcvsuBiURJyKzohl+abC7hzGqUjQyn\nRQIDAQAB\n-----END PUBLIC KEY-----\n";
	}
}
