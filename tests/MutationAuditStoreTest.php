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
		$GLOBALS['wp_auto_test_post_meta_values']  = array();
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
	 * Matching logical Create events can be verified without metadata mutation.
	 */
	public function test_has_create_event_is_read_only_and_strict(): void {
		$store = new MutationAuditStore();
		$event = array(
			'version'          => 1,
			'operation'        => 'create',
			'ability'          => 'wp-auto/post-create-draft',
			'actor_user_id'    => 7,
			'target_object_id' => 11,
			'timestamp_gmt'    => '2026-09-01 00:00:00',
			'fingerprint'      => 'fingerprint-a',
		);
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = array( $event );

		self::assertTrue( $store->has_create_event( 11, 'wp-auto/post-create-draft', 7, 'fingerprint-a' ) );
		self::assertFalse( $store->has_create_event( 11, 'wp-auto/post-create-draft', 8, 'fingerprint-a' ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * Multiple physical private audit values fail closed.
	 */
	public function test_rejects_multiple_physical_audit_containers(): void {
		$event = array(
			'operation'        => 'create',
			'ability'          => 'wp-auto/post-create-draft',
			'actor_user_id'    => 7,
			'target_object_id' => 11,
			'fingerprint'      => 'fingerprint-a',
		);
		$GLOBALS['wp_auto_test_post_meta_values'][11][ MutationAuditStore::meta_key() ] = array( array( $event ), array( $event ) );
		$store = new MutationAuditStore();

		self::assertFalse( $store->has_create_event( 11, 'wp-auto/post-create-draft', 7, 'fingerprint-a' ) );
		self::assertFalse( $store->append( 11, $event ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}
}
