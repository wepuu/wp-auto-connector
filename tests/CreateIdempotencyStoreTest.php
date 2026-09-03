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
		$GLOBALS['wp_auto_test_options']                        = array();
		$GLOBALS['wp_auto_test_option_autoload']                = array();
		$GLOBALS['wp_auto_test_option_cache']                   = array();
		$GLOBALS['wp_auto_test_notoptions_cache']               = null;
		$GLOBALS['wp_auto_test_alloptions_cache']               = null;
		$GLOBALS['wp_auto_test_use_option_cache']               = false;
		$GLOBALS['wp_auto_test_cache_delete_exception']         = null;
		$GLOBALS['wp_auto_test_db_query_exception']             = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
		$GLOBALS['wp_auto_test_db_last_error']                  = '';
		$GLOBALS['wp_auto_test_db_return_override']             = null;
		$GLOBALS['wp_auto_test_db_suppress_state']              = false;
		$GLOBALS['wp_auto_test_db_suppress_history']            = array();
		$GLOBALS['wp_auto_test_db_prepared_queries']            = array();
		$GLOBALS['wp_auto_test_db_query_calls']                 = 0;
		$GLOBALS['wp_auto_test_delete_option_calls']            = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']             = false;
		$GLOBALS['wp_auto_test_delete_option_exception']        = null;
		$GLOBALS['wp_auto_test_fail_update_option']             = false;
		$GLOBALS['wp_auto_test_fail_update_option_on_call']     = null;
		$GLOBALS['wp_auto_test_update_option_calls']            = 0;
	}

	/**
	 * Claims are durable and non-autoloaded.
	 */
	public function test_claim_is_durable_and_non_autoloaded(): void {
		$store       = new CreateIdempotencyStore();
		$fingerprint = str_repeat( 'a', 64 );
		$claim       = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', $fingerprint );
		self::assertSame( 'claimed', $claim['status'] );
		self::assertStringStartsWith( 'wp_auto_connector_idempotency_', $claim['name'] );
		self::assertSame( 'off', $GLOBALS['wp_auto_test_option_autoload'][ $claim['name'] ] );
		self::assertArrayNotHasKey( 'key', $claim['record'] );
		self::assertArrayNotHasKey( 'content', $claim['record'] );

		$again = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', $fingerprint );
		self::assertSame( 'existing', $again['status'] );
		self::assertSame( $claim['record'], $again['record'] );
	}

	/**
	 * Scope changes produce independent claims.
	 */
	public function test_different_scope_keys_produce_independent_claims(): void {
		$store       = new CreateIdempotencyStore();
		$fingerprint = str_repeat( 'b', 64 );
		$first       = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', $fingerprint );
		$second      = $store->claim( 'wp-auto/post-create-draft', 8, 'abc12345', $fingerprint );
		self::assertNotSame( $first['name'], $second['name'] );
	}

	/**
	 * Target correlation retains ownership until audit and completion succeed.
	 */
	public function test_claim_state_transitions_are_ordered_and_non_autoloaded(): void {
		$store = new CreateIdempotencyStore();
		$claim = $store->claim( 'wp-auto/post-create-draft', 7, 'abc12345', str_repeat( 'c', 64 ) );
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
		self::assertSame( 'off', $GLOBALS['wp_auto_test_option_autoload'][ $name ] );
		self::assertArrayNotHasKey( 'key', $record );
	}

	/** Occupied malformed persisted records fail closed instead of becoming existing claims. */
	public function test_occupied_malformed_record_is_unresolved(): void {
		$store                                    = new CreateIdempotencyStore();
		$fingerprint                              = str_repeat( 'd', 64 );
		$claim                                    = $store->claim( 'wp-auto/post-create-draft', 7, 'malformed1', $fingerprint );
		$name                                     = $claim['name'];
		$record                                   = $claim['record'];
		$record['actor_user_id']                  = 0;
		$GLOBALS['wp_auto_test_options'][ $name ] = $record;

		$occupied = $store->claim( 'wp-auto/post-create-draft', 7, 'malformed1', $fingerprint );

		self::assertSame( 'unresolved', $occupied['status'] );
		self::assertSame( $name, $occupied['name'] );
	}

	/** State transitions reject malformed records before any option update. */
	public function test_state_transitions_validate_persisted_record_before_writing(): void {
		$store                                       = new CreateIdempotencyStore();
		$claim                                       = $store->claim( 'wp-auto/post-create-draft', 7, 'transition1', str_repeat( 'e', 64 ) );
		$name                                        = $claim['name'];
		$record                                      = $claim['record'];
		$record['updated_gmt']                       = '0000-00-00 00:00:00';
		$GLOBALS['wp_auto_test_update_option_calls'] = 0;

		self::assertFalse( $store->record_target_in_progress( $name, $record, 123 ) );
		self::assertFalse( $store->mark_audit_recorded( $name, $record ) );
		self::assertFalse( $store->complete( $name, $record ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_option_calls'] );
	}
}
