<?php
/**
 * Taxonomy Create idempotency store tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TaxonomyCreateIdempotencyStore;

/** Covers durable taxonomy claims and ordered finalization. */
final class TaxonomyCreateIdempotencyStoreTest extends TestCase {
	/** Reset option and database fixtures. */
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
		$GLOBALS['wp_auto_test_update_option_calls']            = 0;
		$GLOBALS['wp_auto_test_current_blog_id']                = 1;
	}

	/** Claims hide the raw key and are site/actor scoped. */
	public function test_claim_is_private_non_autoloaded_and_scoped(): void {
		$store = new TaxonomyCreateIdempotencyStore();
		$first = $store->claim( 'wp-auto/category-create', 7, 'category-key-1', str_repeat( 'a', 64 ) );

		self::assertSame( 'claimed', $first['status'] );
		self::assertStringStartsWith( 'wp_auto_connector_taxonomy_idempotency_', $first['name'] );
		self::assertStringNotContainsString( 'category-key', $first['name'] );
		self::assertSame( 'off', $GLOBALS['wp_auto_test_option_autoload'][ $first['name'] ] );
		self::assertArrayNotHasKey( 'key', $first['record'] );
		self::assertSame( 'existing', $store->claim( 'wp-auto/category-create', 7, 'category-key-1', str_repeat( 'a', 64 ) )['status'] );

		$actor                                   = $store->claim( 'wp-auto/category-create', 8, 'category-key-1', str_repeat( 'a', 64 ) );
		$GLOBALS['wp_auto_test_current_blog_id'] = 2;
		$site                                    = $store->claim( 'wp-auto/category-create', 7, 'category-key-1', str_repeat( 'a', 64 ) );
		self::assertNotSame( $first['name'], $actor['name'] );
		self::assertNotSame( $first['name'], $site['name'] );
	}

	/** Category and Tag claims share the validated option family while keeping Ability scopes distinct. */
	public function test_tag_claim_uses_the_same_private_family(): void {
		$store = new TaxonomyCreateIdempotencyStore();
		$claim = $store->claim( 'wp-auto/tag-create', 7, 'tag-key-1', str_repeat( 'd', 64 ) );

		self::assertSame( 'claimed', $claim['status'] );
		self::assertSame( 'wp-auto/tag-create', $claim['record']['ability'] );
		self::assertStringStartsWith( 'wp_auto_connector_taxonomy_idempotency_', $claim['name'] );
		self::assertSame( 'existing', $store->claim( 'wp-auto/tag-create', 7, 'tag-key-1', str_repeat( 'd', 64 ) )['status'] );
	}

	/** Claims transition through target, audit, and completion states only. */
	public function test_transitions_are_ordered_and_persisted(): void {
		$store = new TaxonomyCreateIdempotencyStore();
		$claim = $store->claim( 'wp-auto/category-create', 7, 'transition-key', str_repeat( 'b', 64 ) );
		$name  = $claim['name'];

		self::assertTrue( $store->record_target_in_progress( $name, $claim['record'], 3001 ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 3001, $record['target_id'] );
		self::assertTrue( $store->mark_audit_recorded( $name, $record ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'audit_recorded', $record['state'] );
		self::assertTrue( $store->complete( $name, $record ) );
		self::assertSame( 'completed', $GLOBALS['wp_auto_test_options'][ $name ]['state'] );
	}

	/** Malformed records and wrong abilities fail closed before SQL. */
	public function test_invalid_records_are_unresolved(): void {
		$store             = new TaxonomyCreateIdempotencyStore();
		$claim             = $store->claim( 'wp-auto/category-create', 7, 'malformed-key', str_repeat( 'c', 64 ) );
		$record            = $claim['record'];
		$record['ability'] = 'wp-auto/post-create-draft';
		$GLOBALS['wp_auto_test_options'][ $claim['name'] ] = $record;

		self::assertSame( 'unresolved', $store->claim( 'wp-auto/category-create', 7, 'malformed-key', str_repeat( 'c', 64 ) )['status'] );
		self::assertSame( 'unresolved', $store->claim( 'wp-auto/post-create-draft', 7, 'other-key', str_repeat( 'c', 64 ) )['status'] );
	}
}
