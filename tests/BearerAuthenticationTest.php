<?php
/**
 * Phase 2.0.5 scoped Bearer authentication tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPAuto\Connector\Grants\LocalGrantRepository;
use WPAuto\Connector\Grants\LocalUserResolverInterface;
use WPAuto\Connector\Mcp\McpServerRegistrar;
use WPAuto\Connector\OAuth\AbilityScopeGate;
use WPAuto\Connector\OAuth\AccessTokenVerifier;
use WPAuto\Connector\OAuth\BearerAuthenticator;
use WPAuto\Connector\OAuth\BearerChallenge;
use WPAuto\Connector\OAuth\BearerRequestContext;
use WPAuto\Connector\OAuth\BearerTokenExtractor;
use WPAuto\Connector\OAuth\JwksCache;
use WPAuto\Connector\OAuth\ProtectedResourceMetadata;
use WPAuto\Connector\OAuth\RevocationStateRepository;
use WPAuto\Connector\OAuth\ScopePolicy;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentityRepository;

/** Verifies exact token binding, dual authentication, and all 23 scope mappings. */
final class BearerAuthenticationTest extends TestCase {
	private const ISSUER   = 'https://auth.example.test';
	private const TENANT   = '11111111-1111-4111-8111-111111111111';
	private const SITE     = 'site_00000001';
	private const RESOURCE = 'https://example.test/wp-json/wp-auto/mcp';
	private const GRANT    = 'grant_00000001';
	private const CLIENT   = 'client_00000001';
	private const SUBJECT  = 'subject_0000001';
	private const JTI      = 'token_000000001';
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

	/**
	 * Active local connection settings.
	 *
	 * @var ConnectionSettings
	 */
	private ConnectionSettings $settings;

	/**
	 * Active local grant repository.
	 *
	 * @var LocalGrantRepository
	 */
	private LocalGrantRepository $grants;

	/**
	 * Shared local deny state.
	 *
	 * @var RevocationStateRepository
	 */
	private RevocationStateRepository $revocations;

	/** Build an active paired site, grant, and ephemeral RSA fixture. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_current_blog_id']                   = 1;
		$GLOBALS['wp_auto_test_blog_options']                      = array();
		$GLOBALS['wp_auto_test_use_option_cache']                  = false;
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_notoptions_cache']                  = null;
		$GLOBALS['wp_auto_test_alloptions_cache']                  = null;
		$GLOBALS['wp_auto_test_rest_url']                          = 'https://example.test/wp-json/';
		$GLOBALS['wp_auto_test_current_user_id']                   = 0;
		$GLOBALS['wp_auto_test_logged_in']                         = false;
		$GLOBALS['wp_auto_test_can_read']                          = true;
		$GLOBALS['wp_auto_test_application_passwords_supported']   = false;
		$GLOBALS['wp_auto_test_application_passwords_available']   = false;
		$GLOBALS['wp_auto_test_fail_update_option']                = false;
		$GLOBALS['wp_auto_test_fail_update_option_on_call']        = null;
		$GLOBALS['wp_auto_test_update_option_exception_on_call']   = null;
		$GLOBALS['wp_auto_test_update_option_calls']               = 0;
		$GLOBALS['wp_auto_test_add_option_exception']              = null;
		$GLOBALS['wp_auto_test_add_option_exception_after_write']  = null;
		$GLOBALS['wp_auto_test_delete_option_return_after_delete'] = null;
		$GLOBALS['wp_auto_test_uuid_counter']                      = 0;

		$this->private_key = TestRsaFixture::private_key();
		$this->jwk         = TestRsaFixture::jwk( self::KID );

		$this->settings = new ConnectionSettings();
		self::assertTrue( $this->settings->enable( 'https://platform.example.test', self::ISSUER, self::TENANT ) );
		self::assertTrue( $this->settings->mark_pending() );
		$site_kid = ( new SiteIdentityRepository() )->get_or_create()->kid();
		self::assertTrue( $this->settings->mark_active( self::SITE, $site_kid, TestRsaFixture::public_key(), self::KID ) );

		$this->revocations = new RevocationStateRepository();
		$users             = new class() implements LocalUserResolverInterface {
			/**
			 * Check the one fixture user.
			 *
			 * @param int $user_id Candidate local user ID.
			 */
			public function exists( int $user_id ): bool {
				return 42 === $user_id;
			}
		};
		$this->grants      = new LocalGrantRepository( $users, $this->revocations );
		self::assertTrue(
			$this->grants->activate(
				self::GRANT,
				42,
				self::SITE,
				self::CLIENT,
				array( 'mcp:read', 'mcp:content.write' ),
				self::RESOURCE,
				$site_kid,
				1000
			)
		);
	}

	/** Exact claims resolve the current local user without persisting the token. */
	public function test_exact_access_token_resolves_local_identity(): void {
		$identity = $this->verifier()->verify( $this->token(), 1000 );

		self::assertSame( 42, $identity['user_id'] );
		self::assertSame( array( 'mcp:read', 'mcp:content.write' ), $identity['scopes'] );
		self::assertStringNotContainsString( $this->token(), wp_json_encode( $GLOBALS['wp_auto_test_options'] ) );
	}

	/** An in-place upgrade persists the PRM rewrite once, then remains stable. */
	public function test_prm_rewrite_version_flushes_once(): void {
		$GLOBALS['wp_auto_test_flush_rewrite_calls'] = 0;
		ProtectedResourceMetadata::ensure_rewrite_version();
		ProtectedResourceMetadata::ensure_rewrite_version();

		self::assertSame( 1, $GLOBALS['wp_auto_test_flush_rewrite_calls'] );
		self::assertSame( '1', get_option( ProtectedResourceMetadata::VERSION_OPTION, '' ) );
	}

	/** Audience arrays, client confusion, and scope expansion all fail closed. */
	public function test_adversarial_claim_bindings_are_rejected(): void {
		foreach (
			array(
				array( 'aud' => array( self::RESOURCE ) ),
				array( 'client_id' => 'client_99999999' ),
				array( 'scope' => 'mcp:read mcp:media.write' ),
				array( 'tenant_id' => '22222222-2222-4222-8222-222222222222' ),
			) as $overrides
		) {
			try {
				$this->verifier()->verify( $this->token( $overrides ), 1000 );
				self::fail( 'Invalid binding must fail closed.' );
			} catch ( \RuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	/** Every key-discovery or critical-extension JOSE header fails independently. */
	public function test_forbidden_jose_headers_are_individually_rejected(): void {
		foreach (
			array(
				array( 'crit' => array( 'custom' ) ),
				array( 'jku' => 'https://attacker.example.test/jwks.json' ),
				array( 'jwk' => $this->jwk ),
				array( 'x5u' => 'https://attacker.example.test/cert.pem' ),
			) as $header
		) {
			try {
				$this->verifier()->verify( $this->token( array(), $header ), 1000 );
				self::fail( 'Forbidden JOSE header must fail closed.' );
			} catch ( \RuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	/** A local JTI deny is immediate and does not require the platform. */
	public function test_locally_revoked_jti_is_rejected(): void {
		self::assertTrue(
			$this->revocations->apply(
				array(
					'event_type'     => 'token',
					'token_jti_hash' => hash( 'sha256', self::JTI ),
					'sequence'       => 1,
				)
			)
		);
		$this->expectException( \RuntimeException::class );
		$this->verifier()->verify( $this->token(), 1000 );
	}

	/** Bearer succeeds without Application Password support and is removed after dispatch. */
	public function test_request_bridge_installs_and_restores_local_user(): void {
		$context = new BearerRequestContext();
		$bridge  = new BearerAuthenticator( $context, new BearerTokenExtractor(), $this->verifier() );
		$now     = time();
		$request = new WP_REST_Request(
			array(),
			'application/json',
			'',
			'/wp-auto/mcp',
			array(
				'authorization' => 'Bearer ' . $this->token(
					array(
						'iat' => $now,
						'nbf' => $now,
						'exp' => $now + 300,
					)
				),
			)
		);

		self::assertNull( $bridge->authenticate( null, new \stdClass(), $request ) );
		self::assertTrue( $context->authenticated() );
		self::assertSame( 42, get_current_user_id() );
		self::assertTrue( ( new McpServerRegistrar( $context ) )->check_transport_permission() );

		$response = new WP_REST_Response( array(), 200 );
		self::assertSame( $response, $bridge->restore( $response, new \stdClass(), $request ) );
		self::assertFalse( $context->authenticated() );
		self::assertSame( 0, get_current_user_id() );
	}

	/** A malformed Bearer credential never falls back to an ambient logged-in user. */
	public function test_invalid_bearer_never_falls_back(): void {
		$GLOBALS['wp_auto_test_current_user_id'] = 99;
		$GLOBALS['wp_auto_test_logged_in']       = true;
		$context                                 = new BearerRequestContext();
		$bridge                                  = new BearerAuthenticator( $context, new BearerTokenExtractor(), $this->verifier() );
		$request                                 = new WP_REST_Request( array(), 'application/json', '', '/wp-auto/mcp', array( 'authorization' => 'Bearer malformed' ) );

		$result = $bridge->authenticate( null, new \stdClass(), $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
		self::assertFalse( $context->authenticated() );
		self::assertSame( 99, get_current_user_id() );
	}

	/** Every frozen tool has one scope and Bearer scope runs before the old callback. */
	public function test_exact_twenty_three_tool_scope_policy_and_gate(): void {
		$policy = new ScopePolicy();
		self::assertCount( 23, $policy->mappings() );
		self::assertSame( 'mcp:seo.write', $policy->required_scope( 'wp-auto/seo-update' ) );

		$context = new BearerRequestContext();
		$context->install(
			array(
				'user_id'    => 42,
				'grant_id'   => self::GRANT,
				'client_id'  => self::CLIENT,
				'subject_id' => self::SUBJECT,
				'jti'        => self::JTI,
				'scopes'     => array( 'mcp:read' ),
			)
		);
		$called = false;
		$args   = ( new AbilityScopeGate( $context, $policy ) )->filter(
			array(
				'permission_callback' => static function () use ( &$called ): bool {
					$called = true;
					return true;
				},
			),
			'wp-auto/seo-update'
		);
		$result = $args['permission_callback']();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'insufficient_scope', $result->get_error_data()['oauth_error'] );
		self::assertFalse( $called );
	}

	/** PRM is path-specific and contains only public connection metadata. */
	public function test_path_specific_protected_resource_metadata(): void {
		$metadata = new ProtectedResourceMetadata( $this->settings );
		self::assertSame(
			'https://example.test/.well-known/oauth-protected-resource/wp-json/wp-auto/mcp',
			ProtectedResourceMetadata::metadata_url( self::RESOURCE )
		);
		self::assertSame( self::RESOURCE, $metadata->document()['resource'] );
		self::assertSame( array( self::ISSUER ), $metadata->document()['authorization_servers'] );
		self::assertSame( array( 'header' ), $metadata->document()['bearer_methods_supported'] );
	}

	/** Missing, invalid, scope, and local capability failures get distinct challenges. */
	public function test_bearer_challenge_preserves_failure_semantics(): void {
		$challenge = new BearerChallenge( $this->settings );
		$request   = new WP_REST_Request( array(), 'application/json', '', '/wp-auto/mcp' );
		$missing   = new WP_REST_Response( array( 'code' => 'wp_auto_connector_authentication_required' ), 401 );
		$invalid   = new WP_REST_Response( array( 'code' => 'wp_auto_connector_invalid_token' ), 401 );
		$scope     = new WP_REST_Response(
			array(
				'code' => 'wp_auto_connector_insufficient_scope',
				'data' => array( 'required_scope' => 'mcp:seo.write' ),
			),
			403
		);
		$local     = new WP_REST_Response( array( 'code' => 'wp_auto_connector_insufficient_capability' ), 403 );

		$challenge->filter( $missing, new \stdClass(), $request );
		$challenge->filter( $invalid, new \stdClass(), $request );
		$challenge->filter( $scope, new \stdClass(), $request );
		$challenge->filter( $local, new \stdClass(), $request );

		self::assertStringNotContainsString( 'error=', $missing->headers['WWW-Authenticate'] );
		self::assertStringContainsString( 'error="invalid_token"', $invalid->headers['WWW-Authenticate'] );
		self::assertStringContainsString( 'error="insufficient_scope"', $scope->headers['WWW-Authenticate'] );
		self::assertStringContainsString( 'scope="mcp:seo.write"', $scope->headers['WWW-Authenticate'] );
		self::assertArrayNotHasKey( 'WWW-Authenticate', $local->headers );
	}

	/** Build a verifier sharing the current fixture grant and deny state. */
	private function verifier(): AccessTokenVerifier {
		return new AccessTokenVerifier(
			$this->settings,
			new JwksCache( new TestJwksFetcher( $this->jwk ) ),
			$this->grants,
			$this->revocations
		);
	}

	/**
	 * Sign one test access token.
	 *
	 * @param array<string,mixed> $overrides       Claim replacements.
	 * @param array<string,mixed> $header_overrides JOSE header replacements.
	 */
	private function token( array $overrides = array(), array $header_overrides = array() ): string {
		return JWT::encode(
			array_merge(
				array(
					'iss'       => self::ISSUER,
					'aud'       => self::RESOURCE,
					'sub'       => self::SUBJECT,
					'tenant_id' => self::TENANT,
					'site_id'   => self::SITE,
					'grant_id'  => self::GRANT,
					'client_id' => self::CLIENT,
					'scope'     => 'mcp:read mcp:content.write',
					'iat'       => 1000,
					'nbf'       => 1000,
					'exp'       => 1300,
					'jti'       => self::JTI,
				),
				$overrides
			),
			$this->private_key,
			'RS256',
			self::KID,
			array_merge( array( 'typ' => 'at+jwt' ), $header_overrides )
		);
	}
}
