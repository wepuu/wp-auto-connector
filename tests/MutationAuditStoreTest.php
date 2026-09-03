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
		$GLOBALS['wp_auto_test_post_meta']                         = array();
		$GLOBALS['wp_auto_test_post_meta_values']                  = array();
		$GLOBALS['wp_auto_test_fail_update_meta']                  = false;
		$GLOBALS['wp_auto_test_update_meta_exception']             = null;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
		$GLOBALS['wp_auto_test_before_update_meta']                = null;
		$GLOBALS['wp_auto_test_nested_audit']                      = null;
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_notoptions_cache']                  = null;
		$GLOBALS['wp_auto_test_alloptions_cache']                  = null;
		$GLOBALS['wp_auto_test_use_option_cache']                  = false;
		$GLOBALS['wp_auto_test_cache_delete_exception']            = null;
		$GLOBALS['wp_auto_test_db_query_calls']                    = 0;
		$GLOBALS['wp_auto_test_db_query_exception']                = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception']    = null;
		$GLOBALS['wp_auto_test_delete_option_calls']               = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']                = false;
		$GLOBALS['wp_auto_test_delete_option_exception']           = null;
		$GLOBALS['wp_auto_test_uuid_counter']                      = 0;
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
	 * A held object lock fails closed without touching the metadata container.
	 */
	public function test_held_lock_does_not_read_or_write_metadata(): void {
		$lock                                     = 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', "1\0" . '11' );
		$GLOBALS['wp_auto_test_options'][ $lock ] = '12345678-1234-4234-8234-000000000001';

		self::assertFalse( ( new MutationAuditStore() )->append( 11, array( 'sequence' => 1 ) ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertArrayHasKey( $lock, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A failed release never reports a successful append.
	 */
	public function test_release_failure_is_reported_after_metadata_write(): void {
		$GLOBALS['wp_auto_test_fail_delete_option'] = true;

		self::assertFalse( ( new MutationAuditStore() )->append( 11, array( 'sequence' => 1 ) ) );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * A metadata write that throws after persistence remains fail closed.
	 */
	public function test_post_write_metadata_throwable_is_fail_closed(): void {
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = new \RuntimeException( 'metadata failure' );

		self::assertFalse( ( new MutationAuditStore() )->append( 11, array( 'sequence' => 1 ) ) );
		self::assertArrayHasKey( 11, $GLOBALS['wp_auto_test_post_meta'] );
		self::assertArrayNotHasKey( 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', "1\0" . '11' ), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * An overlapping append is rejected while the first critical section is held.
	 */
	public function test_overlapping_append_has_one_event_and_one_metadata_write(): void {
		$event_b                                    = array( 'sequence' => 'B' );
		$GLOBALS['wp_auto_test_nested_audit']       = null;
		$GLOBALS['wp_auto_test_before_update_meta'] = static function () use ( $event_b ): void {
			$GLOBALS['wp_auto_test_nested_audit'] = ( new MutationAuditStore() )->append( 11, $event_b );
		};

		self::assertTrue( ( new MutationAuditStore() )->append( 11, array( 'sequence' => 'A' ) ) );
		self::assertFalse( $GLOBALS['wp_auto_test_nested_audit'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( array( array( 'sequence' => 'A' ) ), get_post_meta( 11, MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Different object locks allow independent appends.
	 */
	public function test_different_objects_use_independent_locks(): void {
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, array( 'sequence' => 'A' ) ) );
		self::assertTrue( $store->append( 12, array( 'sequence' => 'B' ) ) );
		self::assertSame( array( array( 'sequence' => 'A' ) ), get_post_meta( 11, MutationAuditStore::meta_key(), true ) );
		self::assertSame( array( array( 'sequence' => 'B' ) ), get_post_meta( 12, MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Retains both new events when the prior container has nineteen entries.
	 */
	public function test_retention_with_nineteen_existing_events_keeps_a_and_b(): void {
		$events = array();
		for ( $index = 1; $index <= 19; $index++ ) {
			$events[] = array( 'sequence' => $index );
		}
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = $events;
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, array( 'sequence' => 'A' ) ) );
		self::assertTrue( $store->append( 11, array( 'sequence' => 'B' ) ) );
		$final = get_post_meta( 11, MutationAuditStore::meta_key(), true );

		self::assertCount( 20, $final );
		self::assertSame( 2, $final[0]['sequence'] );
		self::assertSame( 'A', $final[18]['sequence'] );
		self::assertSame( 'B', $final[19]['sequence'] );
	}

	/**
	 * Retains both new events and trims two old entries at twenty entries.
	 */
	public function test_retention_with_twenty_existing_events_keeps_a_and_b(): void {
		$events = array();
		for ( $index = 1; $index <= 20; $index++ ) {
			$events[] = array( 'sequence' => $index );
		}
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = $events;
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, array( 'sequence' => 'A' ) ) );
		self::assertTrue( $store->append( 11, array( 'sequence' => 'B' ) ) );
		$final = get_post_meta( 11, MutationAuditStore::meta_key(), true );

		self::assertCount( 20, $final );
		self::assertSame( 3, $final[0]['sequence'] );
		self::assertSame( 'A', $final[18]['sequence'] );
		self::assertSame( 'B', $final[19]['sequence'] );
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
