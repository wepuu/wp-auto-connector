<?php
/**
 * Media mutation audit store tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Media\MediaMutationAuditStore;

/** Covers separate, bounded, fixed-schema media attribution. */
final class MediaMutationAuditStoreTest extends TestCase {
	/** Reset metadata and ownership fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_post_meta']                         = array();
		$GLOBALS['wp_auto_test_post_meta_values']                  = array();
		$GLOBALS['wp_auto_test_fail_update_meta']                  = false;
		$GLOBALS['wp_auto_test_update_meta_exception']             = null;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_db_query_exception']                = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception']    = null;
		$GLOBALS['wp_auto_test_fail_delete_option']                = false;
		$GLOBALS['wp_auto_test_uuid_counter']                      = 0;
	}

	/** Only twenty exact upload events are retained in the separate key. */
	public function test_retains_only_twenty_exact_events(): void {
		$store = new MediaMutationAuditStore();
		for ( $index = 1; $index <= 21; $index++ ) {
			self::assertTrue( $store->append( 11, $this->event( 11, (string) $index ) ) );
		}

		$events = get_post_meta( 11, MediaMutationAuditStore::meta_key(), true );
		self::assertCount( 20, $events );
		self::assertSame( hash( 'sha256', '2' ), $events[0]['fingerprint'] );
		self::assertSame( hash( 'sha256', '21' ), $events[19]['fingerprint'] );
		self::assertArrayNotHasKey( 'filename', $events[0] );
		self::assertArrayNotHasKey( 'path', $events[0] );
		self::assertArrayNotHasKey( 'idempotency_key', $events[0] );
	}

	/** Recovery evidence requires exactly one matching event. */
	public function test_recovery_evidence_is_strict_and_read_only(): void {
		$store = new MediaMutationAuditStore();
		$event = $this->event( 11, 'match' );
		$GLOBALS['wp_auto_test_post_meta'][11][ MediaMutationAuditStore::meta_key() ] = array( $event );

		self::assertTrue( $store->has_upload_event( 11, 'wp-auto/media-upload', 7, $event['fingerprint'] ) );
		self::assertFalse( $store->has_upload_event( 11, 'wp-auto/media-upload', 8, $event['fingerprint'] ) );
		$GLOBALS['wp_auto_test_post_meta'][11][ MediaMutationAuditStore::meta_key() ][] = $event;
		self::assertFalse( $store->has_upload_event( 11, 'wp-auto/media-upload', 7, $event['fingerprint'] ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/** Wrong targets and extra fields are rejected before writes. */
	public function test_rejects_invalid_events(): void {
		$store = new MediaMutationAuditStore();
		self::assertFalse( $store->append( 12, $this->event( 11, 'wrong' ) ) );
		$event          = $this->event( 11, 'extra' );
		$event['bytes'] = 'secret';
		self::assertFalse( $store->append( 11, $event ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/** Upload and update events may share one exact bounded container. */
	public function test_accepts_exact_update_event_without_text_content(): void {
		$store = new MediaMutationAuditStore();
		$event = array(
			'version'               => 1,
			'operation'             => 'update',
			'ability'               => 'wp-auto/media-update',
			'actor_user_id'         => 7,
			'target_object_id'      => 11,
			'timestamp_gmt'         => '2026-09-08 01:00:02',
			'expected_modified_gmt' => '0000-00-00 00:00:00',
			'result_modified_gmt'   => '2026-09-08 01:00:01',
		);

		self::assertTrue( $store->append( 11, $event ) );
		self::assertSame( array( $event ), get_post_meta( 11, MediaMutationAuditStore::meta_key(), true ) );
		$event['caption'] = 'must not persist';
		self::assertFalse( $store->append( 11, $event ) );
	}

	/** Sparse persisted audit containers are ambiguous and rejected. */
	public function test_rejects_sparse_persisted_container(): void {
		$event = $this->event( 11, 'sparse' );
		$GLOBALS['wp_auto_test_post_meta'][11][ MediaMutationAuditStore::meta_key() ] = array( 1 => $event );

		$store = new MediaMutationAuditStore();
		self::assertFalse( $store->has_upload_event( 11, 'wp-auto/media-upload', 7, $event['fingerprint'] ) );
		self::assertFalse( $store->append( 11, $event ) );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * Build one fixed upload event.
	 *
	 * @param int    $post_id Target attachment ID.
	 * @param string $seed Fingerprint seed.
	 */
	private function event( int $post_id, string $seed ): array {
		return array(
			'version'          => 1,
			'operation'        => 'upload',
			'ability'          => 'wp-auto/media-upload',
			'actor_user_id'    => 7,
			'target_object_id' => $post_id,
			'timestamp_gmt'    => '2026-09-01 12:00:00',
			'fingerprint'      => hash( 'sha256', $seed ),
		);
	}
}
