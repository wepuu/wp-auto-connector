<?php
/**
 * Taxonomy mutation audit store tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TaxonomyMutationAuditStore;

/** Covers private bounded taxonomy audit attribution. */
final class TaxonomyMutationAuditStoreTest extends TestCase {
	/** Reset term metadata and ownership fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_term_meta']                      = array();
		$GLOBALS['wp_auto_test_term_meta_values']               = array();
		$GLOBALS['wp_auto_test_fail_update_term_meta']          = false;
		$GLOBALS['wp_auto_test_options']                        = array();
		$GLOBALS['wp_auto_test_option_autoload']                = array();
		$GLOBALS['wp_auto_test_option_cache']                   = array();
		$GLOBALS['wp_auto_test_notoptions_cache']               = null;
		$GLOBALS['wp_auto_test_alloptions_cache']               = null;
		$GLOBALS['wp_auto_test_use_option_cache']               = false;
		$GLOBALS['wp_auto_test_db_query_calls']                 = 0;
		$GLOBALS['wp_auto_test_db_query_exception']             = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
		$GLOBALS['wp_auto_test_db_last_error']                  = '';
		$GLOBALS['wp_auto_test_db_return_override']             = null;
		$GLOBALS['wp_auto_test_db_suppress_state']              = false;
		$GLOBALS['wp_auto_test_db_prepared_queries']            = array();
		$GLOBALS['wp_auto_test_cache_delete_exception']         = null;
		$GLOBALS['wp_auto_test_delete_option_calls']            = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']             = false;
		$GLOBALS['wp_auto_test_delete_option_exception']        = null;
		$GLOBALS['wp_auto_test_uuid_counter']                   = 0;
		$GLOBALS['wp_auto_test_current_blog_id']                = 1;
	}

	/** Appends one exact event and keeps the raw key out of stored metadata. */
	public function test_append_and_prove_one_event(): void {
		$store = new TaxonomyMutationAuditStore();
		$event = $this->event( 3001, str_repeat( 'a', 64 ), 7 );

		self::assertTrue( $store->append_create( 3001, $event ) );
		self::assertTrue( $store->has_create_event( 3001, 'wp-auto/category-create', 7, str_repeat( 'a', 64 ) ) );
		self::assertSame( array( $event ), $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ] );
		self::assertArrayNotHasKey( 'key', $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ][0] );
		$lock_options = array_filter(
			array_keys( $GLOBALS['wp_auto_test_options'] ),
			static fn( string $name ): bool => str_starts_with( $name, 'wp_auto_connector_mutation_audit_lock_' )
		);
		self::assertSame( array(), $lock_options );
	}

	/** Tag events use the shared audit key without a parent field. */
	public function test_append_and_prove_tag_event_without_parent(): void {
		$store = new TaxonomyMutationAuditStore();
		$event = array(
			'version'        => 1,
			'operation'      => 'create',
			'ability'        => 'wp-auto/tag-create',
			'actor_user_id'  => 7,
			'target_term_id' => 3002,
			'taxonomy'       => 'post_tag',
			'timestamp_gmt'  => '2026-09-01 12:00:00',
			'fingerprint'    => str_repeat( 'd', 64 ),
		);

		self::assertTrue( $store->append_create( 3002, $event ) );
		self::assertTrue( $store->has_create_event( 3002, 'wp-auto/tag-create', 7, str_repeat( 'd', 64 ) ) );
		self::assertSame( array( $event ), $GLOBALS['wp_auto_test_term_meta'][3002][ TaxonomyMutationAuditStore::meta_key() ] );
	}

	/** Histories are bounded to the newest twenty validated events. */
	public function test_history_is_bounded_to_twenty_events(): void {
		$store = new TaxonomyMutationAuditStore();
		for ( $index = 1; $index <= 21; ++$index ) {
			$event = $this->event( 3001, hash( 'sha256', (string) $index ), 7 );
			self::assertTrue( $store->append_create( 3001, $event ) );
		}

		$events = $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ];
		self::assertCount( 20, $events );
		self::assertSame( hash( 'sha256', '2' ), $events[0]['fingerprint'] );
	}

	/** Invalid event shapes and physical duplicates fail closed. */
	public function test_invalid_events_and_duplicates_fail_closed(): void {
		$store              = new TaxonomyMutationAuditStore();
		$event              = $this->event( 3001, str_repeat( 'b', 64 ), 7 );
		$event['parent_id'] = -1;
		self::assertFalse( $store->append_create( 3001, $event ) );

		$GLOBALS['wp_auto_test_term_meta_values'][3001][ TaxonomyMutationAuditStore::meta_key() ] = array( array(), array() );
		self::assertFalse( $store->append_create( 3001, $this->event( 3001, str_repeat( 'c', 64 ), 7 ) ) );
	}

	/**
	 * Build one valid exact event.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $fingerprint Payload fingerprint.
	 * @param int    $actor_id Actor user ID.
	 * @return array<string, mixed>
	 */
	private function event( int $term_id, string $fingerprint, int $actor_id ): array {
		return array(
			'version'        => 1,
			'operation'      => 'create',
			'ability'        => 'wp-auto/category-create',
			'actor_user_id'  => $actor_id,
			'target_term_id' => $term_id,
			'taxonomy'       => 'category',
			'timestamp_gmt'  => '2026-09-01 12:00:00',
			'fingerprint'    => $fingerprint,
			'parent_id'      => 0,
		);
	}
}
