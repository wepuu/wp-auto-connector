<?php
/**
 * Mutation audit store tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Content\MutationAuditStore;

/**
 * Covers local bounded attribution.
 */
final class MutationAuditStoreTest extends TestCase {
	/**
	 * Reset metadata fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_post_meta']         = array();
		$GLOBALS['wp_auto_test_fail_update_meta']  = false;
		$GLOBALS['wp_auto_test_update_meta_calls'] = 0;
	}

	/**
	 * Only the twenty newest events are retained.
	 */
	public function test_retains_only_the_twenty_most_recent_events(): void {
		$store = new MutationAuditStore();
		for ( $index = 1; $index <= 21; $index++ ) {
			self::assertTrue( $store->append( 11, array( 'sequence' => $index ) ) );
		}

		$events = get_post_meta( 11, MutationAuditStore::meta_key(), true );
		self::assertCount( 20, $events );
		self::assertSame( 2, $events[0]['sequence'] );
		self::assertSame( 21, $events[19]['sequence'] );
	}

	/**
	 * Failed metadata writes are reported.
	 */
	public function test_reports_a_failed_meta_write(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		self::assertFalse( ( new MutationAuditStore() )->append( 11, array( 'operation' => 'create' ) ) );
	}

	/**
	 * Repeated logical Create finalization does not rewrite or duplicate the event.
	 */
	public function test_append_create_once_deduplicates_without_metadata_write(): void {
		$store                   = new MutationAuditStore();
		$first                   = array(
			'version'          => 1,
			'operation'        => 'create',
			'ability'          => 'wp-auto/post-create-draft',
			'actor_user_id'    => 7,
			'target_object_id' => 11,
			'timestamp_gmt'    => '2026-09-01 00:00:00',
			'fingerprint'      => 'fingerprint-a',
		);
		$second                  = $first;
		$second['timestamp_gmt'] = '2026-09-01 00:00:01';

		self::assertTrue( $store->append_create_once( 11, $first ) );
		self::assertTrue( $store->append_create_once( 11, $second ) );
		$events = get_post_meta( 11, MutationAuditStore::meta_key(), true );
		self::assertCount( 1, $events );
		self::assertSame( $first, $events[0] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}
}
