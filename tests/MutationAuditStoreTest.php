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
		$GLOBALS['wp_auto_test_post_meta']        = array();
		$GLOBALS['wp_auto_test_fail_update_meta'] = false;
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
}
