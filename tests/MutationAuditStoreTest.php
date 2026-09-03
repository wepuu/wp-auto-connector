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
			self::assertTrue( $store->append( 11, $this->create_event( 11, (string) $index ) ) );
		}

		$events = get_post_meta( 11, MutationAuditStore::meta_key(), true );
		self::assertCount( 20, $events );
		self::assertSame( $this->fingerprint( '2' ), $events[0]['fingerprint'] );
		self::assertSame( $this->fingerprint( '21' ), $events[19]['fingerprint'] );
	}

	/**
	 * Failed metadata writes are reported.
	 */
	public function test_reports_a_failed_meta_write(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		self::assertFalse( ( new MutationAuditStore() )->append( 11, $this->create_event( 11, 'failed' ) ) );
	}

	/**
	 * A held object lock fails closed without touching the metadata container.
	 */
	public function test_held_lock_does_not_read_or_write_metadata(): void {
		$lock                                     = 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', "1\0" . '11' );
		$GLOBALS['wp_auto_test_options'][ $lock ] = '12345678-1234-4234-8234-000000000001';

		self::assertFalse( ( new MutationAuditStore() )->append( 11, $this->create_event( 11, 'held' ) ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertArrayHasKey( $lock, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A failed release never reports a successful append.
	 */
	public function test_release_failure_is_reported_after_metadata_write(): void {
		$GLOBALS['wp_auto_test_fail_delete_option'] = true;

		self::assertFalse( ( new MutationAuditStore() )->append( 11, $this->create_event( 11, 'release' ) ) );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * A metadata write that throws after persistence remains fail closed.
	 */
	public function test_post_write_metadata_throwable_is_fail_closed(): void {
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = new \RuntimeException( 'metadata failure' );

		self::assertFalse( ( new MutationAuditStore() )->append( 11, $this->create_event( 11, 'throw' ) ) );
		self::assertArrayHasKey( 11, $GLOBALS['wp_auto_test_post_meta'] );
		self::assertArrayNotHasKey( 'wp_auto_connector_mutation_audit_lock_' . hash( 'sha256', "1\0" . '11' ), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * An overlapping append is rejected while the first critical section is held.
	 */
	public function test_overlapping_append_has_one_event_and_one_metadata_write(): void {
		$event_b                                    = $this->create_event( 11, 'B' );
		$GLOBALS['wp_auto_test_nested_audit']       = null;
		$GLOBALS['wp_auto_test_before_update_meta'] = static function () use ( $event_b ): void {
			$GLOBALS['wp_auto_test_nested_audit'] = ( new MutationAuditStore() )->append( 11, $event_b );
		};

		self::assertTrue( ( new MutationAuditStore() )->append( 11, $this->create_event( 11, 'A' ) ) );
		self::assertFalse( $GLOBALS['wp_auto_test_nested_audit'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( array( $this->create_event( 11, 'A' ) ), get_post_meta( 11, MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Different object locks allow independent appends.
	 */
	public function test_different_objects_use_independent_locks(): void {
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, $this->create_event( 11, 'A' ) ) );
		self::assertTrue( $store->append( 12, $this->create_event( 12, 'B' ) ) );
		self::assertSame( array( $this->create_event( 11, 'A' ) ), get_post_meta( 11, MutationAuditStore::meta_key(), true ) );
		self::assertSame( array( $this->create_event( 12, 'B' ) ), get_post_meta( 12, MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Retains both new events when the prior container has nineteen entries.
	 */
	public function test_retention_with_nineteen_existing_events_keeps_a_and_b(): void {
		$events = array();
		for ( $index = 1; $index <= 19; $index++ ) {
			$events[] = $this->create_event( 11, (string) $index );
		}
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = $events;
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, $this->create_event( 11, 'A' ) ) );
		self::assertTrue( $store->append( 11, $this->create_event( 11, 'B' ) ) );
		$final = get_post_meta( 11, MutationAuditStore::meta_key(), true );

		self::assertCount( 20, $final );
		self::assertSame( $this->fingerprint( '2' ), $final[0]['fingerprint'] );
		self::assertSame( $this->fingerprint( 'A' ), $final[18]['fingerprint'] );
		self::assertSame( $this->fingerprint( 'B' ), $final[19]['fingerprint'] );
	}

	/**
	 * Retains both new events and trims two old entries at twenty entries.
	 */
	public function test_retention_with_twenty_existing_events_keeps_a_and_b(): void {
		$events = array();
		for ( $index = 1; $index <= 20; $index++ ) {
			$events[] = $this->create_event( 11, (string) $index );
		}
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = $events;
		$store = new MutationAuditStore();

		self::assertTrue( $store->append( 11, $this->create_event( 11, 'A' ) ) );
		self::assertTrue( $store->append( 11, $this->create_event( 11, 'B' ) ) );
		$final = get_post_meta( 11, MutationAuditStore::meta_key(), true );

		self::assertCount( 20, $final );
		self::assertSame( $this->fingerprint( '3' ), $final[0]['fingerprint'] );
		self::assertSame( $this->fingerprint( 'A' ), $final[18]['fingerprint'] );
		self::assertSame( $this->fingerprint( 'B' ), $final[19]['fingerprint'] );
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
			'fingerprint'      => $this->fingerprint( 'a' ),
		);
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = array( $event );

		self::assertTrue( $store->has_create_event( 11, 'wp-auto/post-create-draft', 7, $this->fingerprint( 'a' ) ) );
		self::assertFalse( $store->has_create_event( 11, 'wp-auto/post-create-draft', 8, $this->fingerprint( 'a' ) ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * Multiple physical private audit values fail closed.
	 */
	public function test_rejects_multiple_physical_audit_containers(): void {
		$event = $this->create_event( 11, 'duplicate' );
		$GLOBALS['wp_auto_test_post_meta_values'][11][ MutationAuditStore::meta_key() ] = array( array( $event ), array( $event ) );
		$store = new MutationAuditStore();

		self::assertFalse( $store->has_create_event( 11, 'wp-auto/post-create-draft', 7, $event['fingerprint'] ) );
		self::assertFalse( $store->append( 11, $event ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/** Invalid incoming events are rejected before ownership or metadata access. */
	public function test_rejects_invalid_incoming_event_before_lock(): void {
		$invalid = $this->create_event( 12, 'wrong-target' );
		self::assertFalse( ( new MutationAuditStore() )->append( 11, $invalid ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_db_query_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );

		$invalid = $this->create_event( 11, 'missing-key' );
		unset( $invalid['fingerprint'] );
		self::assertFalse( ( new MutationAuditStore() )->append( 11, $invalid ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/** Update events accept the exact zero modified timestamp sentinel. */
	public function test_accepts_valid_update_event(): void {
		$event = array(
			'version'               => 1,
			'operation'             => 'update',
			'ability'               => 'wp-auto/post-update',
			'actor_user_id'         => 7,
			'target_object_id'      => 11,
			'timestamp_gmt'         => '2026-09-01 00:00:00',
			'expected_modified_gmt' => '0000-00-00 00:00:00',
			'result_modified_gmt'   => '2026-09-01 00:00:00',
		);
		self::assertTrue( ( new MutationAuditStore() )->append( 11, $event ) );
		self::assertSame( $event, get_post_meta( 11, MutationAuditStore::meta_key(), true )[0] );
	}

	/** A pre-existing oversized container is malformed and is never trimmed on read. */
	public function test_rejects_oversized_existing_container_without_repair(): void {
		$events = array();
		for ( $index = 1; $index <= 21; $index++ ) {
			$events[] = $this->create_event( 11, 'oversized-' . $index );
		}
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = $events;

		$store = new MutationAuditStore();
		self::assertFalse( $store->has_create_event( 11, 'wp-auto/post-create-draft', 7, $events[0]['fingerprint'] ) );
		self::assertFalse( $store->append( 11, $this->create_event( 11, 'new' ) ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertCount( 21, get_post_meta( 11, MutationAuditStore::meta_key(), true ) );
	}

	/** Duplicate exact matching Create events are ambiguous recovery evidence. */
	public function test_duplicate_matching_create_events_fail_closed(): void {
		$event = $this->create_event( 11, 'duplicate-match' );
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = array( $event, $event );

		self::assertFalse( ( new MutationAuditStore() )->has_create_event( 11, 'wp-auto/post-create-draft', 7, $event['fingerprint'] ) );
	}

	/** Sparse persisted containers are rejected instead of silently reindexed. */
	public function test_rejects_sparse_persisted_container(): void {
		$event = $this->create_event( 11, 'sparse' );
		$GLOBALS['wp_auto_test_post_meta'][11][ MutationAuditStore::meta_key() ] = array( 1 => $event );

		self::assertFalse( ( new MutationAuditStore() )->has_create_event( 11, 'wp-auto/post-create-draft', 7, $event['fingerprint'] ) );
	}

	/**
	 * Build a valid Create event with a deterministic marker fingerprint.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $marker  Fingerprint marker.
	 */
	private function create_event( int $post_id, string $marker ): array {
		return array(
			'version'          => 1,
			'operation'        => 'create',
			'ability'          => 'wp-auto/post-create-draft',
			'actor_user_id'    => 7,
			'target_object_id' => $post_id,
			'timestamp_gmt'    => '2026-09-01 00:00:00',
			'fingerprint'      => $this->fingerprint( $marker ),
		);
	}

	/**
	 * Return a valid deterministic fingerprint.
	 *
	 * @param string $marker Fingerprint marker.
	 */
	private function fingerprint( string $marker ): string {
		return hash( 'sha256', $marker );
	}
}
