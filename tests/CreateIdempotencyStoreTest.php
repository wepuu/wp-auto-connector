<?php
/**
 * Persistent Create Draft idempotency store tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Content\CreateIdempotencyStore;

/**
 * Covers atomic claim and non-autoloaded storage behavior.
 */
final class CreateIdempotencyStoreTest extends TestCase {
	/**
	 * Reset option fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']                    = array();
		$GLOBALS['wp_auto_test_option_autoload']            = array();
		$GLOBALS['wp_auto_test_fail_update_option']         = false;
		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = null;
		$GLOBALS['wp_auto_test_update_option_calls']        = 0;
	}

	/**
	 * Claims are durable and non-autoloaded.
	 */
	public function test_claim_is_durable_and_non_autoloaded(): void {
		$store = new CreateIdempotencyStore();
		$claim = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', 'fingerprint' );
		self::assertSame( 'claimed', $claim['status'] );
		self::assertStringStartsWith( 'wp_auto_connector_idempotency_', $claim['name'] );
		self::assertSame( false, $GLOBALS['wp_auto_test_option_autoload'][ $claim['name'] ] );
		self::assertArrayNotHasKey( 'key', $claim['record'] );
		self::assertArrayNotHasKey( 'content', $claim['record'] );

		$again = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', 'fingerprint' );
		self::assertSame( 'existing', $again['status'] );
		self::assertSame( $claim['record'], $again['record'] );
	}

	/**
	 * Scope changes produce independent claims.
	 */
	public function test_different_scope_keys_produce_independent_claims(): void {
		$store  = new CreateIdempotencyStore();
		$first  = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', 'one' );
		$second = $store->claim( 'wp-auto/post-create-draft', 8, 'abc12345', 'one' );
		self::assertNotSame( $first['name'], $second['name'] );
	}

	/**
	 * Target correlation retains ownership until audit and completion succeed.
	 */
	public function test_claim_state_transitions_are_ordered_and_non_autoloaded(): void {
		$store = new CreateIdempotencyStore();
		$claim = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', 'fingerprint' );
		$name  = $claim['name'];

		self::assertSame( 'in_progress', $claim['record']['state'] );
		self::assertSame( 0, $claim['record']['target_id'] );
		self::assertTrue( $store->record_target_in_progress( $name, $claim['record'], 123 ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'in_progress', $record['state'] );
		self::assertSame( 123, $record['target_id'] );
		self::assertTrue( $store->mark_audit_recorded( $name, $record ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'audit_recorded', $record['state'] );
		self::assertSame( 123, $record['target_id'] );
		self::assertTrue( $store->complete( $name, $record ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'completed', $record['state'] );
		self::assertSame( 123, $record['target_id'] );
		self::assertSame( false, $GLOBALS['wp_auto_test_option_autoload'][ $name ] );
		self::assertArrayNotHasKey( 'key', $record );
	}
}
