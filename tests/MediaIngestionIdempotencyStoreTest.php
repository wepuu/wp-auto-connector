<?php
/**
 * Persistent media ingestion idempotency tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Media\MediaIngestionIdempotencyStore;

/** Covers durable media claim scoping and ordered finalization. */
final class MediaIngestionIdempotencyStoreTest extends TestCase {
	/** Reset option fixtures. */
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
		$GLOBALS['wp_auto_test_db_query_calls']                 = 0;
		$GLOBALS['wp_auto_test_fail_update_option']             = false;
		$GLOBALS['wp_auto_test_update_option_calls']            = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']             = false;
		$GLOBALS['wp_auto_test_delete_option_exception']        = null;
		$GLOBALS['wp_auto_test_current_blog_id']                = 1;
	}

	/** Claim names hide secrets and remain isolated by site and actor. */
	public function test_claim_is_private_non_autoloaded_and_scoped(): void {
		$store = new MediaIngestionIdempotencyStore();
		$first = $store->claim( 'wp-auto/media-upload', 7, 'secret-key-00001', str_repeat( 'a', 64 ) );

		self::assertSame( 'claimed', $first['status'] );
		self::assertStringStartsWith( 'wp_auto_connector_media_idempotency_', $first['name'] );
		self::assertStringNotContainsString( 'secret', $first['name'] );
		self::assertSame( 'off', $GLOBALS['wp_auto_test_option_autoload'][ $first['name'] ] );
		self::assertArrayNotHasKey( 'key', $first['record'] );
		self::assertSame( 'existing', $store->claim( 'wp-auto/media-upload', 7, 'secret-key-00001', str_repeat( 'a', 64 ) )['status'] );

		$actor                                   = $store->claim( 'wp-auto/media-upload', 8, 'secret-key-00001', str_repeat( 'a', 64 ) );
		$GLOBALS['wp_auto_test_current_blog_id'] = 2;
		$site                                    = $store->claim( 'wp-auto/media-upload', 7, 'secret-key-00001', str_repeat( 'a', 64 ) );
		self::assertNotSame( $first['name'], $actor['name'] );
		self::assertNotSame( $first['name'], $site['name'] );
	}

	/** Target, audit, and completion transitions retain one durable owner. */
	public function test_transitions_are_ordered(): void {
		$store = new MediaIngestionIdempotencyStore();
		$claim = $store->claim( 'wp-auto/media-upload', 7, 'transition-key-1', str_repeat( 'b', 64 ) );
		$name  = $claim['name'];

		self::assertTrue( $store->record_target_in_progress( $name, $claim['record'], 123 ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'in_progress', $record['state'] );
		self::assertSame( 123, $record['target_id'] );
		self::assertTrue( $store->mark_audit_recorded( $name, $record ) );
		$record = $GLOBALS['wp_auto_test_options'][ $name ];
		self::assertSame( 'audit_recorded', $record['state'] );
		self::assertTrue( $store->complete( $name, $record ) );
		self::assertSame( 'completed', $GLOBALS['wp_auto_test_options'][ $name ]['state'] );
	}

	/** Malformed occupied state is unresolved and never treated as replayable. */
	public function test_malformed_occupied_record_is_unresolved(): void {
		$store = new MediaIngestionIdempotencyStore();
		$claim = $store->claim( 'wp-auto/media-upload', 7, 'malformed-key-01', str_repeat( 'c', 64 ) );
		$GLOBALS['wp_auto_test_options'][ $claim['name'] ]['ability'] = 'wp-auto/post-create-draft';

		$result = $store->claim( 'wp-auto/media-upload', 7, 'malformed-key-01', str_repeat( 'c', 64 ) );
		self::assertSame( 'unresolved', $result['status'] );
	}
}
