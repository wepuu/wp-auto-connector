<?php
/**
 * Atomic ownership primitive tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Content\AtomicOwnershipStore;

/**
 * Covers the private INSERT/DELETE ownership contract.
 */
final class AtomicOwnershipStoreTest extends TestCase {
	/**
	 * Keeps the production entrypoint's ownership dependency ordering explicit.
	 */
	public function test_production_entrypoint_loads_ownership_before_consumers(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entrypoint = file_get_contents( dirname( __DIR__ ) . '/wp-auto-connector.php' );
		self::assertIsString( $entrypoint );

		$atomic  = strpos( $entrypoint, "require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/AtomicOwnershipStore.php';" );
		$create  = strpos( $entrypoint, "require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/CreateIdempotencyStore.php';" );
		$audit   = strpos( $entrypoint, "require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/MutationAuditStore.php';" );
		$service = strpos( $entrypoint, "require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/ContentMutationService.php';" );

		self::assertNotFalse( $atomic );
		self::assertNotFalse( $create );
		self::assertNotFalse( $audit );
		self::assertNotFalse( $service );
		self::assertTrue( $atomic < $create );
		self::assertTrue( $create < $audit );
		self::assertTrue( $audit < $service );
	}

	/**
	 * Reset database, cache, and option fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_options']                        = array();
		$GLOBALS['wp_auto_test_option_autoload']                = array();
		$GLOBALS['wp_auto_test_option_cache']                   = array();
		$GLOBALS['wp_auto_test_notoptions_cache']               = null;
		$GLOBALS['wp_auto_test_alloptions_cache']               = null;
		$GLOBALS['wp_auto_test_use_option_cache']               = true;
		$GLOBALS['wp_auto_test_cache_delete_exception']         = null;
		$GLOBALS['wp_auto_test_cache_delete_return_override']   = null;
		$GLOBALS['wp_auto_test_cache_delete_history']           = array();
		$GLOBALS['wp_auto_test_db_query_calls']                 = 0;
		$GLOBALS['wp_auto_test_db_query_exception']             = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
		$GLOBALS['wp_auto_test_db_prepare_exception']           = null;
		$GLOBALS['wp_auto_test_db_last_error']                  = '';
		$GLOBALS['wp_auto_test_db_return_override']             = null;
		$GLOBALS['wp_auto_test_db_suppress_state']              = false;
		$GLOBALS['wp_auto_test_db_suppress_history']            = array();
		$GLOBALS['wp_auto_test_db_prepared_queries']            = array();
		$GLOBALS['wp_auto_test_before_db_query']                = null;
		$GLOBALS['wp_auto_test_fail_delete_option']             = false;
		$GLOBALS['wp_auto_test_delete_option_exception']        = null;
		$GLOBALS['wp_auto_test_delete_option_calls']            = 0;
		$GLOBALS['wp_auto_test_uuid_counter']                   = 0;
	}

	/**
	 * Acquires a row with the frozen non-autoload representation.
	 */
	public function test_acquire_uses_off_autoload_and_strict_readback(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();

		$result = ( new AtomicOwnershipStore() )->acquire( $name, $value );

		self::assertSame( array( 'status' => 'acquired' ), $result );
		self::assertSame( 'off', $GLOBALS['wp_auto_test_option_autoload'][ $name ] );
		self::assertSame( $value, $GLOBALS['wp_auto_test_options'][ $name ] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_db_query_calls'] );
		self::assertSame( array( array( $name, 'options' ), array( 'notoptions', 'options' ), array( 'alloptions', 'options' ) ), $GLOBALS['wp_auto_test_cache_delete_history'] );
	}

	/**
	 * Restores a normal wpdb suppression state after a successful query.
	 */
	public function test_successful_query_restores_false_suppression_state(): void {
		$result = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'acquired', $result['status'] );
		self::assertFalse( $GLOBALS['wp_auto_test_db_suppress_state'] );
		self::assertSame( array( true, false ), $GLOBALS['wp_auto_test_db_suppress_history'] );
	}

	/**
	 * Keeps acquisition SQL and bound values constrained to the approved insert.
	 */
	public function test_acquisition_uses_only_the_approved_prepared_insert(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();

		self::assertSame( 'acquired', ( new AtomicOwnershipStore() )->acquire( $name, $value )['status'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_db_prepared_queries'] );
		$prepared = $GLOBALS['wp_auto_test_db_prepared_queries'][0];

		self::assertSame( 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, %s)', $prepared['query'] );
		self::assertSame( array( $name, maybe_serialize( $value ), 'off' ), $prepared['args'] );
		self::assertStringNotContainsString( 'SELECT', strtoupper( $prepared['query'] ) );
		self::assertStringNotContainsString( 'UPDATE', strtoupper( $prepared['query'] ) );
	}

	/**
	 * Keeps release SQL and bound values constrained to the approved binary delete.
	 */
	public function test_release_uses_only_the_approved_prepared_binary_delete(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		$store->acquire( $name, $value );

		self::assertSame( 'released', $store->release( $name, $value )['status'] );
		self::assertCount( 2, $GLOBALS['wp_auto_test_db_prepared_queries'] );
		$prepared = $GLOBALS['wp_auto_test_db_prepared_queries'][1];

		self::assertSame( 'DELETE FROM wp_options WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $prepared['query'] );
		self::assertSame( array( $name, maybe_serialize( $value ) ), $prepared['args'] );
		self::assertStringNotContainsString( 'SELECT', strtoupper( $prepared['query'] ) );
		self::assertStringNotContainsString( 'UPDATE', strtoupper( $prepared['query'] ) );
	}

	/**
	 * Clears stale absence, individual, and alloptions entries before readback.
	 */
	public function test_acquire_overrides_stale_option_caches(): void {
		$name = $this->idempotency_name();
		wp_cache_set( 'notoptions', array( $name ), 'options' );
		wp_cache_set( $name, 'stale-individual', 'options' );
		wp_cache_set( 'alloptions', array( $name => 'stale-alloptions' ), 'options' );

		$result = ( new AtomicOwnershipStore() )->acquire( $name, $this->initial_record() );

		self::assertSame( 'acquired', $result['status'] );
	}

	/**
	 * Accepts the canonical audit lock namespace and UUID token.
	 */
	public function test_audit_namespace_acquires_and_reports_occupied_uuid(): void {
		$name  = $this->audit_name();
		$token = wp_generate_uuid4();
		$store = new AtomicOwnershipStore();

		self::assertSame( 'acquired', $store->acquire( $name, $token )['status'] );
		self::assertSame( 'occupied', $store->acquire( $name, wp_generate_uuid4() )['status'] );
	}

	/**
	 * Treats a missing cache key as an acceptable invalidation result.
	 */
	public function test_cache_delete_false_is_not_failure(): void {
		$GLOBALS['wp_auto_test_cache_delete_return_override'] = false;
		$result = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'acquired', $result['status'] );
	}

	/**
	 * Rejects cross-family and malformed ownership inputs before SQL.
	 */
	public function test_invalid_namespace_value_pairs_fail_before_sql(): void {
		$store = new AtomicOwnershipStore();
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), wp_generate_uuid4() )['status'] );
		self::assertSame( 'unresolved', $store->acquire( $this->audit_name(), $this->initial_record() )['status'] );
		self::assertSame( 'unresolved', $store->acquire( $this->audit_name(), 'not-a-uuid' )['status'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Rejects malformed initial records and noncanonical option names.
	 */
	public function test_invalid_initial_records_fail_before_sql(): void {
		$store           = new AtomicOwnershipStore();
		$record          = $this->initial_record();
		$record['extra'] = true;
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );
		$record          = $this->initial_record();
		$record['state'] = 'completed';
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );
		$record              = $this->initial_record();
		$record['target_id'] = 12;
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );
		self::assertSame( 'unresolved', $store->acquire( 'wp_auto_connector_idempotency_' . str_repeat( 'A', 64 ), $this->initial_record() )['status'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Rejects malformed names, records, timestamps, and lock tokens before SQL.
	 */
	public function test_input_boundaries_fail_before_sql(): void {
		$store  = new AtomicOwnershipStore();
		$record = $this->initial_record();

		$record['ability'] = 'wp-auto/post-update';
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );

		$record                = $this->initial_record();
		$record['fingerprint'] = 'not-a-fingerprint';
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );

		$record                = $this->initial_record();
		$record['created_gmt'] = '2026-02-30 12:00:00';
		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name(), $record )['status'] );

		self::assertSame( 'unresolved', $store->acquire( $this->idempotency_name() . '_suffix', $this->initial_record() )['status'] );
		self::assertSame( 'unresolved', $store->acquire( "wp_auto_connector_idempotency_' OR 1=1 --", $this->initial_record() )['status'] );
		self::assertSame( 'unresolved', $store->acquire( $this->audit_name(), str_repeat( 'a', 37 ) )['status'] );

		self::assertSame( 0, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Returns an occupied idempotency record without applying initial-claim rules.
	 */
	public function test_occupied_idempotency_array_is_returned_without_initial_shape_validation(): void {
		$name                                     = $this->idempotency_name();
		$GLOBALS['wp_auto_test_options'][ $name ] = array(
			'version'       => 1,
			'actor_user_id' => 7,
			'ability'       => 'wp-auto/post-create-draft',
			'fingerprint'   => str_repeat( 'b', 64 ),
			'state'         => 'completed',
			'target_id'     => 123,
			'created_gmt'   => '2026-09-01 12:00:00',
			'updated_gmt'   => '2026-09-01 12:00:00',
		);

		$result = ( new AtomicOwnershipStore() )->acquire( $name, $this->initial_record() );

		self::assertSame( 'occupied', $result['status'] );
		self::assertSame( 'completed', $result['existing_value']['state'] );
	}

	/**
	 * Fails closed for occupied rows that cannot be classified safely.
	 */
	public function test_occupied_null_or_unexpected_type_is_unresolved(): void {
		$name                                     = $this->idempotency_name();
		$GLOBALS['wp_auto_test_options'][ $name ] = 'unexpected';
		self::assertSame( 'unresolved', ( new AtomicOwnershipStore() )->acquire( $name, $this->initial_record() )['status'] );

		$audit                                     = $this->audit_name();
		$GLOBALS['wp_auto_test_options'][ $audit ] = null;
		self::assertSame( 'unresolved', ( new AtomicOwnershipStore() )->acquire( $audit, wp_generate_uuid4() )['status'] );
	}

	/**
	 * Restores the caller's wpdb suppression state after a query Throwable.
	 */
	public function test_query_suppression_is_restored_after_throwable(): void {
		$GLOBALS['wp_auto_test_db_suppress_state']  = true;
		$GLOBALS['wp_auto_test_db_query_exception'] = new \RuntimeException( 'db failure' );

		$result = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'unresolved', $result['status'] );
		self::assertTrue( $GLOBALS['wp_auto_test_db_suppress_state'] );
		self::assertSame( array( true, true ), $GLOBALS['wp_auto_test_db_suppress_history'] );
	}

	/**
	 * Treats cache-operation Throwables as unresolved ownership state.
	 */
	public function test_cache_throwable_is_unresolved(): void {
		$GLOBALS['wp_auto_test_cache_delete_exception'] = new \RuntimeException( 'cache failure' );
		$result = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'unresolved', $result['status'] );
		self::assertArrayHasKey( $this->idempotency_name(), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * Treats a database error string as unresolved without exposing diagnostics.
	 */
	public function test_database_error_is_unresolved(): void {
		$GLOBALS['wp_auto_test_db_last_error'] = 'sensitive SQL detail';
		$result                                = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'unresolved', $result['status'] );
	}

	/**
	 * Treats an unexpected database return value as unresolved.
	 */
	public function test_unexpected_database_return_is_unresolved(): void {
		$GLOBALS['wp_auto_test_db_return_override'] = 2;
		$result                                     = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'unresolved', $result['status'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Treats a false database return as unresolved.
	 */
	public function test_false_database_return_is_unresolved(): void {
		$GLOBALS['wp_auto_test_db_return_override'] = false;
		$result                                     = ( new AtomicOwnershipStore() )->acquire( $this->idempotency_name(), $this->initial_record() );

		self::assertSame( 'unresolved', $result['status'] );
	}

	/**
	 * Treats a false database release return as unresolved.
	 */
	public function test_release_false_database_return_is_unresolved(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		$store->acquire( $name, $value );
		$GLOBALS['wp_auto_test_db_return_override'] = false;

		self::assertSame( 'unresolved', $store->release( $name, $value )['status'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Treats a non-empty database release error as unresolved.
	 */
	public function test_release_database_error_is_unresolved(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		$store->acquire( $name, $value );
		$GLOBALS['wp_auto_test_db_last_error'] = 'sensitive SQL detail';

		self::assertSame( 'unresolved', $store->release( $name, $value )['status'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Treats a database release Throwable as unresolved.
	 */
	public function test_release_throwable_is_unresolved(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		$store->acquire( $name, $value );
		$GLOBALS['wp_auto_test_db_query_exception'] = new \RuntimeException( 'database failure' );

		self::assertSame( 'unresolved', $store->release( $name, $value )['status'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_db_query_calls'] );
	}

	/**
	 * Releases only an exact byte-for-byte owner value.
	 */
	public function test_release_requires_exact_binary_value_and_reads_back_absence(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		self::assertSame( 'acquired', $store->acquire( $name, $value )['status'] );

		self::assertSame( 'not_owner', $store->release( $name, array_merge( $value, array( 'fingerprint' => str_repeat( 'c', 64 ) ) ) )['status'] );
		self::assertSame( 'released', $store->release( $name, $value )['status'] );
		self::assertArrayNotHasKey( $name, $GLOBALS['wp_auto_test_options'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Performs no second delete when release finalization is unresolved.
	 */
	public function test_release_does_not_retry_when_final_absence_is_unresolved(): void {
		$name  = $this->idempotency_name();
		$value = $this->initial_record();
		$store = new AtomicOwnershipStore();
		self::assertSame( 'acquired', $store->acquire( $name, $value )['status'] );
		$GLOBALS['wp_auto_test_cache_delete_exception'] = new \RuntimeException( 'cache failure' );

		self::assertSame( 'unresolved', $store->release( $name, $value )['status'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
		self::assertArrayNotHasKey( $name, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * Return a deterministic idempotency option name.
	 */
	private function idempotency_name(): string {
		return 'wp_auto_connector_idempotency_' . str_repeat( 'a', 64 );
	}

	/**
	 * Return a deterministic audit lock option name.
	 */
	private function audit_name(): string {
		return 'wp_auto_connector_mutation_audit_lock_' . str_repeat( 'b', 64 );
	}

	/**
	 * Return a valid initial claim record.
	 *
	 * @return array<string,mixed> Initial claim record.
	 */
	private function initial_record(): array {
		return array(
			'version'       => 1,
			'actor_user_id' => 7,
			'ability'       => 'wp-auto/post-create-draft',
			'fingerprint'   => str_repeat( 'a', 64 ),
			'state'         => 'in_progress',
			'target_id'     => 0,
			'created_gmt'   => '2026-09-01 12:00:00',
			'updated_gmt'   => '2026-09-01 12:00:00',
		);
	}
}
