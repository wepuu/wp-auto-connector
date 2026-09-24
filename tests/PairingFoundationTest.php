<?php
/**
 * Phase 2.0.3B local pairing foundation tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Pairing\CanonicalResource;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\PairingRestController;
use WPAuto\Connector\Pairing\PairingStateRepository;
use WPAuto\Connector\Pairing\SiteIdentity;
use WPAuto\Connector\Pairing\SiteIdentityRepository;
use WPAuto\Connector\Pairing\SiteProofSigner;

/** Covers local identity, single-use pairing and proof bindings. */
final class PairingFoundationTest extends TestCase {
	private const ISSUER   = 'https://auth.example.test';
	private const CONTROL  = 'https://platform.example.test';
	private const TENANT   = '11111111-1111-4111-8111-111111111111';
	private const RESOURCE = 'https://example.test/wp-json/wp-auto/mcp';
	private const SITE_ID  = 'site_00000001';
	private const VERIFIER = 'vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv';

	/** Reset local WordPress state before every pairing test. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']             = array();
		$GLOBALS['wp_auto_test_option_autoload']     = array();
		$GLOBALS['wp_auto_test_update_option_calls'] = 0;
		$GLOBALS['wp_auto_test_delete_option_calls'] = 0;
		$GLOBALS['wp_auto_test_is_ssl']              = true;
		$GLOBALS['wp_auto_test_rest_url']            = 'https://example.test/wp-json/';
		$GLOBALS['wp_auto_test_rest_routes']         = array();
	}

	/** Canonical resources reject alternate target spellings. */
	public function test_canonical_resource_is_exact(): void {
		self::assertSame( self::RESOURCE, CanonicalResource::validate( 'https://EXAMPLE.test:443/wp-json/wp-auto/mcp' ) );

		foreach (
			array(
				'http://example.test/wp-json/wp-auto/mcp',
				'https://user@example.test/wp-json/wp-auto/mcp',
				'https://example.test:8443/wp-json/wp-auto/mcp',
				'https://example.test/wp-json/wp-auto/mcp?x=1',
				'https://example.test/wp-json/wp-auto/mcp#x',
				'https://example.test/wp-json/wp-auto/mcp/',
			)
			as $invalid
		) {
			try {
				CanonicalResource::validate( $invalid );
				self::fail( 'Expected invalid canonical resource.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	/** The private key remains local and the public JWK is stable. */
	public function test_site_identity_is_site_local_and_stable(): void {
		$repository = new SiteIdentityRepository();
		$first      = $repository->get_or_create();
		$second     = $repository->get_or_create();

		self::assertSame( $first->kid(), $second->kid() );
		self::assertSame( $first->public_jwk(), $second->public_jwk() );
		self::assertArrayNotHasKey( 'd', $first->public_jwk() );
		self::assertSame( false, $GLOBALS['wp_auto_test_option_autoload'][ SiteIdentityRepository::OPTION_NAME ] );
		self::assertNotSame( '', $first->secret_key() );
	}

	/** Only a verifier digest is persisted and every terminal attempt is single-use. */
	public function test_pairing_state_is_hashed_and_single_use(): void {
		$repository = new PairingStateRepository();
		self::assertTrue( $repository->begin( self::VERIFIER, self::ISSUER, self::TENANT, self::RESOURCE, 1000 ) );
		$stored = $GLOBALS['wp_auto_test_options'][ PairingStateRepository::OPTION_NAME ];
		self::assertStringNotContainsString( self::VERIFIER, wp_json_encode( $stored ) );
		self::assertSame( hash( 'sha256', self::VERIFIER ), $stored['verifier_hash'] );

		$request = $this->request();
		self::assertSame( self::TENANT, $repository->consume( $request, 1001 )['tenant_id'] );
		self::assertArrayNotHasKey( PairingStateRepository::OPTION_NAME, $GLOBALS['wp_auto_test_options'] );

		$this->expectException( \RuntimeException::class );
		$repository->consume( $request, 1002 );
	}

	/** A mismatched request terminates the attempt instead of allowing a retry. */
	public function test_pairing_mismatch_is_terminal(): void {
		$repository = new PairingStateRepository();
		$repository->begin( self::VERIFIER, self::ISSUER, self::TENANT, self::RESOURCE, 1000 );
		$request             = $this->request();
		$request['verifier'] = str_repeat( 'x', 43 );

		$denied = false;
		try {
			$repository->consume( $request, 1001 );
		} catch ( \RuntimeException ) {
			$denied = true;
		}
		self::assertTrue( $denied );
		self::assertArrayNotHasKey( PairingStateRepository::OPTION_NAME, $GLOBALS['wp_auto_test_options'] );
	}

	/** The reviewed JOSE library produces an exact, verifiable EdDSA proof. */
	public function test_pairing_proof_has_exact_header_and_claim_bindings(): void {
		$identity = ( new SiteIdentityRepository() )->get_or_create();
		$proof    = ( new SiteProofSigner() )->sign_pairing(
			$identity,
			array(
				'tenant_id'                => self::TENANT,
				'pairing_attempt_id'       => 'attempt_00000001',
				'site_id'                  => self::SITE_ID,
				'challenge'                => 'challenge_00000000000000000000000',
				'platform_issuer'          => self::ISSUER,
				'platform_signing_key_pem' => $this->platform_key_pem(),
				'platform_signing_kid'     => 'kms-key-0001',
				'resource'                 => self::RESOURCE,
			),
			new \DateTimeImmutable( '@1000' )
		);
		$config   = Configuration::forAsymmetricSigner(
			new Eddsa(),
			InMemory::plainText( $identity->secret_key() ),
			InMemory::plainText( $identity->public_key() )
		);
		$token    = $config->parser()->parse( $proof );

		self::assertSame( 'EdDSA', $token->headers()->get( 'alg' ) );
		self::assertSame( 'wepuu-site-proof+jwt', $token->headers()->get( 'typ' ) );
		self::assertSame( $identity->kid(), $token->headers()->get( 'kid' ) );
		self::assertSame( 'pairing', $token->claims()->get( 'kind' ) );
		self::assertSame( self::TENANT, $token->claims()->get( 'tenant_id' ) );
		self::assertSame( self::SITE_ID, $token->claims()->get( 'site_id' ) );
		self::assertSame( self::RESOURCE, $token->claims()->get( 'resource' ) );
		self::assertSame( 'kms-key-0001', $token->claims()->get( 'platform_signing_kid' ) );
		self::assertSame(
			SiteIdentity::base64url_encode( hash( 'sha256', $this->platform_key_pem(), true ) ),
			$token->claims()->get( 'platform_signing_key_sha256' )
		);
		$segments = explode( '.', $proof );
		$payload  = json_decode( SiteIdentity::base64url_decode( $segments[1] ), true );
		self::assertIsInt( $payload['iat'] );
		self::assertIsInt( $payload['exp'] );
		self::assertSame( 60, $token->claims()->get( 'exp' )->getTimestamp() - $token->claims()->get( 'iat' )->getTimestamp() );
		self::assertTrue(
			$config->signer()->verify( $token->signature()->hash(), $token->payload(), $config->verificationKey() )
		);
	}

	/** The route is disabled by default and returns one no-store proof when enabled. */
	public function test_pairing_route_requires_enablement_and_consumes_attempt(): void {
		$settings   = new ConnectionSettings();
		$state      = new PairingStateRepository();
		$controller = new PairingRestController( $settings, $state );
		self::assertFalse( $controller->permission() );

		self::assertTrue( $settings->enable( self::CONTROL, self::ISSUER, self::TENANT ) );
		self::assertTrue( $state->begin( self::VERIFIER, self::ISSUER, self::TENANT, self::RESOURCE, time() ) );
		self::assertTrue( $settings->mark_pending() );
		self::assertTrue( $controller->permission() );

		$response = $controller->handle( new \WP_REST_Request( $this->request() ) );
		self::assertSame( 200, $response->status );
		self::assertSame( 'no-store', $response->headers['Cache-Control'] );
		self::assertArrayHasKey( 'proof', $response->data );
		self::assertArrayHasKey( 'publicJwk', $response->data );
		self::assertSame( 'active', $settings->load()['status'] );
		self::assertSame( self::SITE_ID, $settings->load()['site_id'] );

		$replay = $controller->handle( new \WP_REST_Request( $this->request() ) );
		self::assertSame( 401, $replay->status );
		self::assertSame( array( 'error' => 'pairing_denied' ), $replay->data );
	}

	/** A changed canonical endpoint persists suspension without rewriting trust. */
	public function test_resource_drift_suspends_and_blocks_pairing_completion(): void {
		$settings   = new ConnectionSettings();
		$state      = new PairingStateRepository();
		$controller = new PairingRestController( $settings, $state );
		$identity   = ( new SiteIdentityRepository() )->get_or_create();

		self::assertTrue( $settings->enable( self::CONTROL, self::ISSUER, self::TENANT ) );
		self::assertTrue( $settings->mark_pending() );
		self::assertTrue( $settings->mark_active( self::SITE_ID, $identity->kid(), $this->platform_key_pem(), 'kms-key-0001' ) );
		$GLOBALS['wp_auto_test_rest_url'] = 'https://moved.example.test/wp-json/';

		$connection = $settings->load();
		self::assertSame( 'suspended', $connection['status'] );
		self::assertSame( self::RESOURCE, $connection['resource'] );
		self::assertSame( 'suspended', $GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['status'] );
		self::assertSame( self::RESOURCE, $GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['resource'] );
		self::assertFalse( $controller->permission() );
		self::assertFalse( $settings->mark_active( 'site_00000002', $identity->kid(), $this->platform_key_pem(), 'kms-key-0001' ) );
	}

	/** An unsafe current REST URL is an uncertain security state and suspends. */
	public function test_invalid_current_resource_suspends_fail_closed(): void {
		$settings = new ConnectionSettings();
		self::assertTrue( $settings->enable( self::CONTROL, self::ISSUER, self::TENANT ) );
		$GLOBALS['wp_auto_test_rest_url'] = 'http://example.test/wp-json/';

		self::assertSame( 'suspended', $settings->load()['status'] );
		self::assertSame( self::RESOURCE, $GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['resource'] );
	}

	/**
	 * Build one exact valid pairing proof request.
	 *
	 * @return array<string,string>
	 */
	private function request(): array {
		return array(
			'protocol_version'         => '1',
			'tenant_id'                => self::TENANT,
			'pairing_attempt_id'       => 'attempt_00000001',
			'site_id'                  => self::SITE_ID,
			'verifier'                 => self::VERIFIER,
			'challenge'                => 'challenge_00000000000000000000000',
			'platform_issuer'          => self::ISSUER,
			'platform_signing_key_pem' => $this->platform_key_pem(),
			'platform_signing_kid'     => 'kms-key-0001',
			'resource'                 => self::RESOURCE,
		);
	}

	/** Return a stable valid RSA test public key. */
	private function platform_key_pem(): string {
		return "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx3XL3Bs2st+gzQLAK1Xo\n6boStldRSNPO66Le3rwBNEqk9F4HkNepIE/uiaPn3vOrW/0YzzNj/YOgCeYdYxya\nU3SLubjMgrlkN56L3bW/xnm7+S8Z5xLA8t9t4maqR728VHkpCdygIYnllcHMU8V6\nJKhtYCzKhqnBQksnOX4+/cjxQ4XqgPWDuw/FrSFcH98kFfoxLBSdu+XlhYdG3Kr0\npKi3Vh9mTXrtuZzqbSOxT2YJ9ZPo6GfeinTV2Wy2BqupL4BfAswyBg/PapNfPjRR\nEaIMD1+B+9Mm6VPut8BfNRsHp/p8MCmnYDP7nGapLDw7BW0E0DhB1z4n1G605XA+\nQwIDAQAB\n-----END PUBLIC KEY-----\n";
	}
}
