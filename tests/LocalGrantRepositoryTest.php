<?php
/**
 * Phase 2.0.3B local grant tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Grants\LocalGrantRepository;
use WPAuto\Connector\Grants\LocalUserResolverInterface;
use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentityRepository;

/** Covers opaque local binding, lifecycle invalidation, and isolation. */
final class LocalGrantRepositoryTest extends TestCase {
	private const CONTROL   = 'https://platform.example.test';
	private const ISSUER    = 'https://auth.example.test';
	private const TENANT    = '11111111-1111-4111-8111-111111111111';
	private const RESOURCE  = 'https://example.test/wp-json/wp-auto/mcp';
	private const SITE_ID   = 'site_00000001';
	private const GRANT_ID  = 'grant_00000001';
	private const CLIENT_ID = 'client_00000001';

	/** Reset isolated local persistence. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']             = array();
		$GLOBALS['wp_auto_test_option_autoload']     = array();
		$GLOBALS['wp_auto_test_update_option_calls'] = 0;
		$GLOBALS['wp_auto_test_delete_option_calls'] = 0;
		$GLOBALS['wp_auto_test_rest_url']            = 'https://example.test/wp-json/';
	}

	/** A current paired site resolves its opaque local grant without platform identity data. */
	public function test_active_grant_resolves_for_existing_local_user(): void {
		$settings   = $this->active_settings();
		$repository = new LocalGrantRepository( $this->users( true ) );
		$kid        = $settings->load()['site_key_kid'];

		self::assertTrue(
			$repository->activate(
				self::GRANT_ID,
				42,
				self::SITE_ID,
				self::CLIENT_ID,
				array( 'mcp:read', 'mcp:content.write' ),
				self::RESOURCE,
				$kid,
				1000
			)
		);

		$option_name = LocalGrantRepository::option_name( self::GRANT_ID );
		self::assertStringNotContainsString( self::GRANT_ID, $option_name );
		self::assertSame( false, $GLOBALS['wp_auto_test_option_autoload'][ $option_name ] );
		self::assertStringNotContainsString( 'subject', wp_json_encode( $GLOBALS['wp_auto_test_options'][ $option_name ] ) );
		self::assertSame( 42, $repository->find_active( self::GRANT_ID, $settings )['user_id'] );
	}

	/** Repeating immutable input is safe, while another local user is a conflict. */
	public function test_activation_is_idempotent_but_conflicts_fail_closed(): void {
		$settings   = $this->active_settings();
		$repository = new LocalGrantRepository( $this->users( true ) );
		$kid        = $settings->load()['site_key_kid'];
		$args       = array( self::GRANT_ID, 42, self::SITE_ID, self::CLIENT_ID, array( 'mcp:read' ), self::RESOURCE, $kid );

		self::assertTrue( $repository->activate( ...$args, now: 1000 ) );
		self::assertTrue( $repository->activate( ...$args, now: 1001 ) );

		$this->expectException( \RuntimeException::class );
		$repository->activate( self::GRANT_ID, 43, self::SITE_ID, self::CLIENT_ID, array( 'mcp:read' ), self::RESOURCE, $kid, 1002 );
	}

	/** User deletion, disconnect, resource change, and key change all deny locally. */
	public function test_lifecycle_changes_invalidate_without_platform_availability(): void {
		$settings   = $this->active_settings();
		$kid        = $settings->load()['site_key_kid'];
		$repository = new LocalGrantRepository( $this->users( true ) );
		$repository->activate( self::GRANT_ID, 42, self::SITE_ID, self::CLIENT_ID, array( 'mcp:read' ), self::RESOURCE, $kid, 1000 );

		self::assertNull( ( new LocalGrantRepository( $this->users( false ) ) )->find_active( self::GRANT_ID, $settings ) );

		$GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['resource'] = 'https://other.example.test/wp-json/wp-auto/mcp';
		self::assertNull( $repository->find_active( self::GRANT_ID, $settings ) );
		$GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['resource']     = self::RESOURCE;
		$GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['site_key_kid'] = 'site_' . str_repeat( 'x', 22 );
		self::assertNull( $repository->find_active( self::GRANT_ID, $settings ) );

		self::assertTrue( $settings->disconnect() );
		self::assertNull( $repository->find_active( self::GRANT_ID, $settings ) );
	}

	/** A real current-site URL change suspends without migrating the old audience. */
	public function test_current_resource_drift_invalidates_grant_and_preserves_audience(): void {
		$settings   = $this->active_settings();
		$repository = new LocalGrantRepository( $this->users( true ) );
		$repository->activate( self::GRANT_ID, 42, self::SITE_ID, self::CLIENT_ID, array( 'mcp:read' ), self::RESOURCE, $settings->load()['site_key_kid'], 1000 );

		$GLOBALS['wp_auto_test_rest_url'] = 'https://moved.example.test/wp-json/';

		self::assertNull( $repository->find_active( self::GRANT_ID, $settings ) );
		self::assertSame( 'suspended', $settings->load()['status'] );
		self::assertSame( self::RESOURCE, $GLOBALS['wp_auto_test_options'][ ConnectionSettings::OPTION_NAME ]['resource'] );
	}

	/** Local revoke removes the authoritative mapping and is idempotent. */
	public function test_local_revoke_is_immediate_and_idempotent(): void {
		$settings   = $this->active_settings();
		$repository = new LocalGrantRepository( $this->users( true ) );
		$repository->activate( self::GRANT_ID, 42, self::SITE_ID, self::CLIENT_ID, array( 'mcp:read' ), self::RESOURCE, $settings->load()['site_key_kid'], 1000 );

		self::assertTrue( $repository->revoke( self::GRANT_ID ) );
		self::assertNull( $repository->find_active( self::GRANT_ID, $settings ) );
		self::assertTrue( $repository->revoke( self::GRANT_ID ) );
	}

	/** Create active public connection state for a test. */
	private function active_settings(): ConnectionSettings {
		$settings = new ConnectionSettings();
		self::assertTrue( $settings->enable( self::CONTROL, self::ISSUER, self::TENANT ) );
		self::assertTrue( $settings->mark_pending() );
		$kid = ( new SiteIdentityRepository() )->get_or_create()->kid();
		self::assertTrue( $settings->mark_active( self::SITE_ID, $kid, self::platform_key_pem(), 'kms-key-0001' ) );
		return $settings;
	}

	/** Return a public-only RSA fixture for platform-key pinning. */
	private static function platform_key_pem(): string {
		return "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx3XL3Bs2st+gzQLAK1Xo\n6boStldRSNPO66Le3rwBNEqk9F4HkNepIE/uiaPn3vOrW/0YzzNj/YOgCeYdYxya\nU3SLubjMgrlkN56L3bW/xnm7+S8Z5xLA8t9t4maqR728VHkpCdygIYnllcHMU8V6\nJKhtYCzKhqnBQksnOX4+/cjxQ4XqgPWDuw/FrSFcH98kFfoxLBSdu+XlhYdG3Kr0\npKi3Vh9mTXrtuZzqbSOxT2YJ9ZPo6GfeinTV2Wy2BqupL4BfAswyBg/PapNfPjRR\nEaIMD1+B+9Mm6VPut8BfNRsHp/p8MCmnYDP7nGapLDw7BW0E0DhB1z4n1G605XA+\nQwIDAQAB\n-----END PUBLIC KEY-----\n";
	}

	/**
	 * Build a deterministic local-user resolver.
	 *
	 * @param bool $exists Whether the fixture user exists.
	 */
	private function users( bool $exists ): LocalUserResolverInterface {
		return new class( $exists ) implements LocalUserResolverInterface {
			/**
			 * Build a deterministic resolver.
			 *
			 * @param bool $exists Whether the fixture user exists.
			 */
			public function __construct( private bool $exists ) {}

			/**
			 * Check the only fixture user identifier.
			 *
			 * @param int $user_id Local WordPress user identifier.
			 */
			public function exists( int $user_id ): bool {
				return $this->exists && 42 === $user_id;
			}
		};
	}
}
