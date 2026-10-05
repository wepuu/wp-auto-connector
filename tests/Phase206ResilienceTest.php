<?php
/**
 * Phase 2.0.6 connector resilience and content-boundary tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\OAuth\RevocationStateRepository;
use WPAuto\Connector\Pairing\ConnectionSettings;

/** Keeps local fail-closed state independent from platform availability. */
final class Phase206ResilienceTest extends TestCase {

	/** Reset the isolated WordPress option store. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']         = array();
		$GLOBALS['wp_auto_test_option_autoload'] = array();
		$GLOBALS['wp_auto_test_rest_url']        = 'https://example.test/wp-json/';
		$GLOBALS['wp_auto_test_current_blog_id'] = 1;
	}

	/** Local key and grant denial remain immediate and monotonic when delivery is unavailable. */
	public function test_local_denial_is_immediate_idempotent_and_out_of_order_safe(): void {
		$state = new RevocationStateRepository();
		self::assertTrue(
			$state->apply(
				array(
					'event_type' => 'key',
					'key_id'     => 'kms-key-0001',
					'sequence'   => 8,
					'reason'     => 'key_revoked',
				)
			)
		);
		self::assertTrue( $state->denies_key( 'kms-key-0001' ) );
		self::assertTrue(
			$state->apply(
				array(
					'event_type' => 'key',
					'key_id'     => 'kms-key-0001',
					'sequence'   => 8,
					'reason'     => 'key_revoked',
				)
			)
		);
		self::assertFalse(
			$state->apply(
				array(
					'event_type' => 'key',
					'key_id'     => 'kms-key-0001',
					'sequence'   => 7,
					'reason'     => 'stale_event',
				)
			)
		);
		self::assertTrue(
			$state->apply(
				array(
					'event_type' => 'grant',
					'grant_id'   => 'grant-00000001',
					'sequence'   => 9,
					'reason'     => 'site_disconnected',
				)
			)
		);
		self::assertTrue( $state->denies_grant( 'grant-00000001' ) );
		self::assertStringNotContainsString( 'grant-00000001', wp_json_encode( $GLOBALS['wp_auto_test_options'] ) );
	}

	/** A local disconnect removes the paired trust without needing a platform round trip. */
	public function test_local_disconnect_is_idempotent_and_does_not_change_application_password_path(): void {
		$settings = new ConnectionSettings();
		self::assertTrue( $settings->enable( 'https://platform.example.test', 'https://auth.example.test', '11111111-1111-4111-8111-111111111111' ) );
		self::assertTrue( $settings->mark_pending() );
		self::assertTrue( $settings->disconnect() );
		self::assertFalse( $settings->disconnect() );
		self::assertNull( $settings->load() );
		self::assertArrayNotHasKey( ConnectionSettings::OPTION_NAME, $GLOBALS['wp_auto_test_options'] );
	}
}
