<?php
/**
 * Explicit uninstall cleanup tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

// Test fixtures intentionally model physical rows containing a fixed meta_key.
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Uninstall\PrivateStateCleanup;

/**
 * Covers the closed ADR-004 lifecycle and database boundary.
 */
final class UninstallTest extends TestCase {
	/**
	 * Reset isolated database, lifecycle, and multisite fixtures.
	 */
	protected function setUp(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated wpdb test double.
		$GLOBALS['wpdb']                                    = new \wpdb();
		$GLOBALS['wp_auto_test_options']                    = array();
		$GLOBALS['wp_auto_test_blog_options']               = array();
		$GLOBALS['wp_auto_test_option_rows']                = array( 1 => array() );
		$GLOBALS['wp_auto_test_post_meta']                  = array();
		$GLOBALS['wp_auto_test_post_meta_values']           = array();
		$GLOBALS['wp_auto_test_postmeta_rows']              = array( 1 => array() );
		$GLOBALS['wp_auto_test_user_meta']                  = array();
		$GLOBALS['wp_auto_test_delete_user_meta_exception'] = null;
		$GLOBALS['wp_auto_test_get_users_exception']        = null;
		$GLOBALS['wp_auto_test_physical_blog_ids']          = array( 1 );
		$GLOBALS['wp_auto_test_current_blog_id']            = 1;
		$GLOBALS['wp_auto_test_site_info']['multisite']     = false;
		$GLOBALS['wp_auto_test_db_prepare_exception']       = null;
		$GLOBALS['wp_auto_test_db_last_error']              = '';
		$GLOBALS['wp_auto_test_db_suppress_state']          = false;
		$GLOBALS['wp_auto_test_db_suppress_history']        = array();
		$GLOBALS['wp_auto_test_db_prepared_queries']        = array();
		$GLOBALS['wp_auto_test_get_results_calls']          = 0;
		$GLOBALS['wp_auto_test_get_results_history']        = array();
		$GLOBALS['wp_auto_test_get_results_exception']      = null;
		$GLOBALS['wp_auto_test_get_results_override_set']   = false;
		$GLOBALS['wp_auto_test_get_results_override']       = null;
		$GLOBALS['wp_auto_test_fail_delete_option']         = false;
		$GLOBALS['wp_auto_test_delete_option_return_after_delete'] = null;
		$GLOBALS['wp_auto_test_delete_option_exception']           = null;
		$GLOBALS['wp_auto_test_delete_option_calls']               = 0;
		$GLOBALS['wp_auto_test_delete_post_meta_calls']            = 0;
		$GLOBALS['wp_auto_test_delete_post_meta_exception']        = null;
		$GLOBALS['wp_auto_test_delete_post_meta_exception_after']  = null;
		$GLOBALS['wp_auto_test_delete_post_meta_return_override']  = null;
		$GLOBALS['wp_auto_test_switch_history']                    = array();
		$GLOBALS['wp_auto_test_restore_history']                   = array();
		$GLOBALS['wp_auto_test_switch_exception_before']           = null;
		$GLOBALS['wp_auto_test_switch_exception_after']            = null;
		$GLOBALS['wp_auto_test_restore_exception_before']          = null;
		$GLOBALS['wp_auto_test_restore_exception_after']           = null;
		$GLOBALS['_wp_switched_stack']                             = array();
		$GLOBALS['switched']                                       = false;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated multisite context fixture.
		$GLOBALS['table_prefix'] = 'wp_';
		\wp_auto_test_apply_blog_context( 1 );
	}

	/**
	 * Removes exact owned rows while preserving every non-allowlisted neighbor.
	 */
	public function test_single_site_removes_only_exact_private_state(): void {
		$valid_idempotency = $this->idempotency_name( 'a' );
		$valid_media       = $this->media_idempotency_name( 'c' );
		$valid_taxonomy    = $this->taxonomy_idempotency_name( 'f' );
		$valid_lock        = $this->audit_lock_name( 'b' );
		$preserved         = array(
			$this->idempotency_name( 'A' ),
			'wp_auto_connector_idempotency_' . str_repeat( 'c', 63 ),
			'wp_auto_connector_idempotency_' . str_repeat( 'd', 65 ),
			$this->audit_lock_name( 'e' ) . "\n",
			$this->audit_lock_name( 'e' ) . "\r\n",
			"\n" . $this->audit_lock_name( 'e' ),
			'wp_auto_connector_idempotency_' . str_repeat( 'a', 32 ) . "\n" . str_repeat( 'a', 32 ),
			$this->idempotency_name( 'a' ) . '-suffix',
			'wp_auto_connector_idempotency_' . str_repeat( 'a', 31 ) . ':' . str_repeat( 'a', 32 ),
			'wp_auto_connector_other_' . str_repeat( 'f', 64 ),
			$this->media_idempotency_name( 'A' ),
			$this->media_idempotency_name( 'd' ) . '-suffix',
		);

		$this->seed_option( 1, 1, $valid_idempotency );
		$this->seed_option( 1, 2, $valid_media );
		$this->seed_option( 1, 3, $valid_lock );
		$this->seed_option( 1, 4, $valid_taxonomy );
		foreach ( $preserved as $index => $name ) {
			$this->seed_option( 1, $index + 5, $name );
		}
		$GLOBALS['wp_auto_test_postmeta_rows'][1] = array(
			array(
				'meta_id'  => '1',
				'meta_key' => '_wp_auto_connector_mutation_audit',
			),
			array(
				'meta_id'  => '2',
				'meta_key' => '_wp_auto_connector_media_mutation_audit',
			),
			array(
				'meta_id'  => '3',
				'meta_key' => '_wp_auto_connector_taxonomy_mutation_audit',
			),
			array(
				'meta_id'  => '4',
				'meta_key' => '_unrelated_meta',
			),
		);
		$GLOBALS['wp_auto_test_termmeta_rows'][1] = array(
			array(
				'meta_id'  => '1',
				'meta_key' => '_wp_auto_connector_taxonomy_mutation_audit',
			),
			array(
				'meta_id'  => '2',
				'meta_key' => '_unrelated_term_meta',
			),
		);
		$GLOBALS['wp_auto_test_user_meta']        = array(
			10 => array(
				'wp_auto_connector_mcp_adapter_sessions' => array( 'owned-session' ),
				'mcp_adapter_sessions'                   => array( 'provider-session' ),
			),
		);

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		$options =& \wp_auto_test_options_for_blog( 1 );
		self::assertArrayNotHasKey( $valid_idempotency, $options );
		self::assertArrayNotHasKey( $valid_media, $options );
		self::assertArrayNotHasKey( $valid_taxonomy, $options );
		self::assertArrayNotHasKey( $valid_lock, $options );
		foreach ( $preserved as $name ) {
			self::assertArrayHasKey( $name, $options );
		}
		self::assertSame(
			array(
				array(
					'meta_id'  => '4',
					'meta_key' => '_unrelated_meta',
				),
			),
			$GLOBALS['wp_auto_test_postmeta_rows'][1]
		);
		self::assertSame(
			array(
				array(
					'meta_id'  => '2',
					'meta_key' => '_unrelated_term_meta',
				),
			),
			$GLOBALS['wp_auto_test_termmeta_rows'][1]
		);
		self::assertArrayNotHasKey( 'wp_auto_connector_mcp_adapter_sessions', $GLOBALS['wp_auto_test_user_meta'][10] );
		self::assertArrayHasKey( 'mcp_adapter_sessions', $GLOBALS['wp_auto_test_user_meta'][10] );
	}

	/**
	 * Session cleanup fails closed when Core deletion cannot complete.
	 */
	public function test_session_cleanup_reports_delete_failure(): void {
		$GLOBALS['wp_auto_test_user_meta'][10]              = array(
			'wp_auto_connector_mcp_adapter_sessions' => array( 'owned-session' ),
		);
		$GLOBALS['wp_auto_test_delete_user_meta_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertArrayHasKey( 'wp_auto_connector_mcp_adapter_sessions', $GLOBALS['wp_auto_test_user_meta'][10] );
	}

	/**
	 * Session cleanup fails closed when bounded absence verification fails.
	 */
	public function test_session_cleanup_reports_verification_failure(): void {
		$GLOBALS['wp_auto_test_get_users_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
	}

	/**
	 * Uses only the four prepared read families and Core deletion functions.
	 */
	public function test_queries_are_bounded_prepared_reads_only(): void {
		$this->seed_option( 1, 1, $this->idempotency_name( 'a' ) );

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertNotEmpty( $GLOBALS['wp_auto_test_db_prepared_queries'] );
		foreach ( $GLOBALS['wp_auto_test_db_prepared_queries'] as $prepared ) {
			self::assertStringStartsWith( 'SELECT ', $prepared['query'] );
			self::assertStringNotContainsString( ' OFFSET ', strtoupper( $prepared['query'] ) );
		}
		$first = $GLOBALS['wp_auto_test_db_prepared_queries'][0];
		self::assertSame( 0, $first['args'][0] );
		self::assertSame( 'wp\\_auto\\_connector\\_idempotency\\_%', $first['args'][1] );
		self::assertSame( 'wp\\_auto\\_connector\\_media\\_idempotency\\_%', $first['args'][2] );
		self::assertSame( 'wp\\_auto\\_connector\\_taxonomy\\_idempotency\\_%', $first['args'][3] );
		self::assertSame( 'wp\\_auto\\_connector\\_mutation\\_audit\\_lock\\_%', $first['args'][4] );
		self::assertSame( 100, $first['args'][5] );
	}

	/**
	 * Traverses deletion and verification with independent keyset cursors.
	 */
	public function test_option_batches_do_not_skip_rows_after_deletion(): void {
		for ( $id = 1; $id <= 130; ++$id ) {
			$this->seed_option( 1, $id, 'wp_auto_connector_idempotency_' . hash( 'sha256', (string) $id ) );
		}

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_option_rows'][1] );
		$cursors = array();
		foreach ( $GLOBALS['wp_auto_test_db_prepared_queries'] as $prepared ) {
			if ( str_contains( $prepared['query'], 'SELECT option_id, option_name' ) ) {
				$cursors[] = $prepared['args'][0];
			}
		}
		self::assertSame( array( 0, 100, 130, 0 ), $cursors );
	}

	/**
	 * A false Core deletion remains incomplete when physical verification sees it.
	 */
	public function test_option_delete_failure_with_residual_is_incomplete(): void {
		$name = $this->idempotency_name( 'a' );
		$this->seed_option( 1, 1, $name );
		$GLOBALS['wp_auto_test_fail_delete_option'] = true;

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertArrayHasKey( $name, $GLOBALS['wp_auto_test_options'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * A false Core result does not prevent completion when SQL proves absence.
	 */
	public function test_false_option_delete_with_verified_absence_is_complete(): void {
		$name = $this->idempotency_name( 'a' );
		$this->seed_option( 1, 1, $name );
		$GLOBALS['wp_auto_test_delete_option_return_after_delete'] = false;

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertArrayNotHasKey( $name, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A Core deletion Throwable is sticky even when later proof is empty.
	 */
	public function test_option_delete_throwable_is_sticky_and_sanitized(): void {
		$this->seed_option( 1, 1, $this->idempotency_name( 'a' ) );
		$GLOBALS['wp_auto_test_delete_option_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		ob_start();
		$result = ( new PrivateStateCleanup() )->run();
		$output = ob_get_clean();

		self::assertFalse( $result );
		self::assertSame( '', $output );
	}

	/**
	 * A false audit delete is complete when the physical table is already empty.
	 */
	public function test_false_audit_delete_with_verified_absence_is_complete(): void {
		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertSame( 3, $GLOBALS['wp_auto_test_delete_post_meta_calls'] );
	}

	/**
	 * A short-circuited audit delete cannot hide a residual physical row.
	 */
	public function test_audit_residual_after_core_success_is_incomplete(): void {
		$GLOBALS['wp_auto_test_postmeta_rows'][1]                 = array(
			array(
				'meta_id'  => '7',
				'meta_key' => '_wp_auto_connector_mutation_audit',
			),
		);
		$GLOBALS['wp_auto_test_delete_post_meta_return_override'] = true;

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_postmeta_rows'][1] );
	}

	/**
	 * A post-delete Throwable remains incomplete despite an empty verification.
	 */
	public function test_audit_delete_after_write_throwable_is_sticky(): void {
		$GLOBALS['wp_auto_test_postmeta_rows'][1]                 = array(
			array(
				'meta_id'  => '7',
				'meta_key' => '_wp_auto_connector_mutation_audit',
			),
		);
		$GLOBALS['wp_auto_test_delete_post_meta_exception_after'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_postmeta_rows'][1] );
	}

	/**
	 * Read failure and malformed rows fail closed without a retry loop.
	 */
	public function test_invalid_read_result_is_incomplete_and_suppression_is_restored(): void {
		$GLOBALS['wp_auto_test_get_results_override_set'] = true;
		$GLOBALS['wp_auto_test_get_results_override']     = array(
			array(
				'option_id'   => '1',
				'option_name' => $this->idempotency_name( 'a' ),
				'extra'       => 'forbidden',
			),
		);

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertFalse( $GLOBALS['wp_auto_test_db_suppress_state'] );
		self::assertSame( array( true, false, true, false, true, false, true, false, true, false, true, false ), $GLOBALS['wp_auto_test_db_suppress_history'] );
	}

	/**
	 * Query preparation and database error state both fail closed.
	 */
	public function test_prepare_and_database_errors_are_incomplete_without_output(): void {
		$GLOBALS['wp_auto_test_db_prepare_exception'] = new \RuntimeException( 'sensitive-internal-detail' );
		ob_start();
		$prepare_result = ( new PrivateStateCleanup() )->run();
		$prepare_output = ob_get_clean();

		$this->setUp();
		$GLOBALS['wp_auto_test_get_results_override_set'] = true;
		$GLOBALS['wp_auto_test_get_results_override']     = array();
		$GLOBALS['wp_auto_test_db_last_error']            = 'sensitive-internal-detail';
		ob_start();
		$error_result = ( new PrivateStateCleanup() )->run();
		$error_output = ob_get_clean();

		self::assertFalse( $prepare_result );
		self::assertFalse( $error_result );
		self::assertSame( '', $prepare_output );
		self::assertSame( '', $error_output );
	}

	/**
	 * A thrown read restores error suppression and exposes no database detail.
	 */
	public function test_read_throwable_restores_suppression_and_is_silent(): void {
		$GLOBALS['wp_auto_test_get_results_exception'] = new \RuntimeException( 'sensitive-internal-detail' );
		ob_start();
		$result = ( new PrivateStateCleanup() )->run();
		$output = ob_get_clean();

		self::assertFalse( $result );
		self::assertSame( '', $output );
		self::assertFalse( $GLOBALS['wp_auto_test_db_suppress_state'] );
		self::assertSame( array( true, false, true, false, true, false, true, false, true, false, true, false ), $GLOBALS['wp_auto_test_db_suppress_history'] );
	}

	/**
	 * Audit verification failure stays incomplete without another Core delete.
	 */
	public function test_audit_verification_failure_does_not_retry_delete(): void {
		$wpdb = new class() extends \wpdb {
			/**
			 * Fail only the fixed audit verification read.
			 *
			 * @param mixed $prepared Prepared test query.
			 * @param mixed $output Requested result form.
			 */
			public function get_results( $prepared, $output = ARRAY_A ) {
				if ( is_array( $prepared ) && str_contains( $prepared['query'], 'SELECT meta_id' ) ) {
					$this->last_error = 'sensitive-internal-detail';
					return null;
				}

				return parent::get_results( $prepared, $output );
			}
		};

		self::assertFalse( ( new PrivateStateCleanup( $wpdb ) )->run() );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_post_meta_calls'] );
	}

	/**
	 * Multisite enumeration uses physical IDs, cleans each blog, and restores context.
	 */
	public function test_multisite_cleans_each_physical_blog_and_restores_context(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']      = array( 1, 2, 3 );
		foreach ( array( 1, 2, 3 ) as $blog_id ) {
			$this->seed_option( $blog_id, 1, 'wp_auto_connector_idempotency_' . hash( 'sha256', 'blog-' . $blog_id ) );
			$GLOBALS['wp_auto_test_postmeta_rows'][ $blog_id ] = array(
				array(
					'meta_id'  => '1',
					'meta_key' => '_wp_auto_connector_mutation_audit',
				),
				array(
					'meta_id'  => '2',
					'meta_key' => '_wp_auto_connector_taxonomy_mutation_audit',
				),
			);
			$GLOBALS['wp_auto_test_termmeta_rows'][ $blog_id ] = array(
				array(
					'meta_id'  => '1',
					'meta_key' => '_wp_auto_connector_taxonomy_mutation_audit',
				),
			);
		}
		$GLOBALS['wp_auto_test_user_meta'][10] = array(
			'wp_auto_connector_mcp_adapter_sessions_1' => array( 'blog-1-session' ),
			'wp_auto_connector_mcp_adapter_sessions_2' => array( 'blog-2-session' ),
			'wp_auto_connector_mcp_adapter_sessions_3' => array( 'blog-3-session' ),
			'mcp_adapter_sessions_1'                   => array( 'provider-session' ),
		);

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1, 2, 3 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( array( 1, 2, 3 ), $GLOBALS['wp_auto_test_restore_history'] );
		self::assertSame( 1, get_current_blog_id() );
		self::assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		self::assertFalse( $GLOBALS['switched'] );
		foreach ( array( 1, 2, 3 ) as $blog_id ) {
			self::assertSame( array(), $GLOBALS['wp_auto_test_option_rows'][ $blog_id ] );
			self::assertSame( array(), $GLOBALS['wp_auto_test_postmeta_rows'][ $blog_id ] );
			self::assertSame( array(), $GLOBALS['wp_auto_test_termmeta_rows'][ $blog_id ] );
		}
		self::assertSame(
			array( 'mcp_adapter_sessions_1' => array( 'provider-session' ) ),
			$GLOBALS['wp_auto_test_user_meta'][10]
		);
	}

	/**
	 * A fresh multisite request may not have initialized Core's switch globals yet.
	 */
	public function test_multisite_accepts_uninitialized_switch_globals(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']      = array( 1, 2 );
		$this->seed_option( 1, 1, $this->idempotency_name( 'a' ) );
		$this->seed_option( 2, 1, $this->audit_lock_name( 'b' ) );
		$GLOBALS['wpdb']->blogid = '1';
		unset( $GLOBALS['_wp_switched_stack'], $GLOBALS['switched'] );

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1, 2 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
		self::assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		self::assertFalse( $GLOBALS['switched'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_option_rows'][1] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_option_rows'][2] );
	}

	/**
	 * More than one site batch is consumed before terminal completion.
	 */
	public function test_multisite_uses_bounded_keyset_batches(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']      = range( 1, 55 );

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		$blog_cursors = array();
		foreach ( $GLOBALS['wp_auto_test_db_prepared_queries'] as $prepared ) {
			if ( str_contains( $prepared['query'], 'SELECT blog_id' ) ) {
				$blog_cursors[] = $prepared['args'];
			}
		}
		self::assertSame( array( array( 0, 50 ), array( 50, 50 ), array( 55, 50 ) ), $blog_cursors );
		self::assertCount( 55, $GLOBALS['wp_auto_test_switch_history'] );
	}

	/**
	 * Multisite completion requires the active blog in the physical traversal.
	 */
	public function test_multisite_missing_current_blog_is_incomplete(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']      = array( 2 );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 2 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
	}

	/**
	 * Non-canonical physical identifiers cannot drive site switching.
	 */
	public function test_multisite_rejects_noncanonical_blog_id(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite']   = true;
		$GLOBALS['wp_auto_test_get_results_override_set'] = true;
		$GLOBALS['wp_auto_test_get_results_override']     = array( array( 'blog_id' => '01' ) );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_switch_history'] );
	}

	/**
	 * Duplicate and non-monotonic blog rows fail before a repeated switch.
	 */
	public function test_multisite_rejects_nonmonotonic_blog_rows(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite']   = true;
		$GLOBALS['wp_auto_test_get_results_override_set'] = true;
		$GLOBALS['wp_auto_test_get_results_override']     = array(
			array( 'blog_id' => '1' ),
			array( 'blog_id' => '1' ),
		);

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
	}

	/**
	 * Failure before a switch frame leaves context intact and later blogs proceed.
	 */
	public function test_switch_before_context_throwable_is_incomplete_but_restored(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite']  = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']       = array( 1, 2 );
		$GLOBALS['wp_auto_test_switch_exception_before'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1, 2 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
		self::assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	/**
	 * Pre-existing switched context is preserved rather than restored below its depth.
	 */
	public function test_multisite_preserves_preexisting_switch_stack(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['_wp_switched_stack']                  = array( 1 );
		$GLOBALS['switched']                            = true;
		\wp_auto_test_apply_blog_context( 2 );
		$GLOBALS['wp_auto_test_physical_blog_ids'] = array( 2 );

		self::assertTrue( ( new PrivateStateCleanup() )->run() );
		self::assertSame( 2, get_current_blog_id() );
		self::assertSame( array( 1 ), $GLOBALS['_wp_switched_stack'] );
		self::assertTrue( $GLOBALS['switched'] );
	}

	/**
	 * A switch Throwable after context mutation is restored and later blogs continue.
	 */
	public function test_switch_after_context_throwable_restores_and_remains_incomplete(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite'] = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']      = array( 1, 2 );
		$GLOBALS['wp_auto_test_switch_exception_after'] = new \RuntimeException( 'sensitive-internal-detail' );
		$this->seed_option( 2, 1, $this->audit_lock_name( 'b' ) );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1, 2 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
		self::assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_option_rows'][2] );
	}

	/**
	 * Restore progress after a Throwable is honored but remains sticky incomplete.
	 */
	public function test_restore_after_progress_throwable_is_incomplete_but_context_is_restored(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite']  = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']       = array( 1, 2 );
		$GLOBALS['wp_auto_test_restore_exception_after'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1, 2 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertSame( 1, get_current_blog_id() );
		self::assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	/**
	 * Restore failure without progress stops later cross-blog work.
	 */
	public function test_restore_without_progress_stops_network_cleanup(): void {
		$GLOBALS['wp_auto_test_site_info']['multisite']   = true;
		$GLOBALS['wp_auto_test_physical_blog_ids']        = array( 1, 2 );
		$GLOBALS['wp_auto_test_restore_exception_before'] = new \RuntimeException( 'sensitive-internal-detail' );

		self::assertFalse( ( new PrivateStateCleanup() )->run() );
		self::assertSame( array( 1 ), $GLOBALS['wp_auto_test_switch_history'] );
		self::assertCount( 1, $GLOBALS['_wp_switched_stack'] );
	}

	/**
	 * Direct execution gate precedes loading and runtime cleanup.
	 */
	public function test_uninstall_entrypoint_keeps_gate_before_helper_load(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entrypoint = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		self::assertIsString( $entrypoint );
		$gate    = strpos( $entrypoint, "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )" );
		$require = strpos( $entrypoint, "require_once __DIR__ . '/src/Uninstall/PrivateStateCleanup.php';" );
		$run     = strpos( $entrypoint, 'PrivateStateCleanup() )->run()' );
		self::assertNotFalse( $gate );
		self::assertNotFalse( $require );
		self::assertNotFalse( $run );
		self::assertTrue( $gate < $require );
		self::assertTrue( $require < $run );
		self::assertStringNotContainsString( 'wp_die', $entrypoint );
	}

	/**
	 * Direct execution exits before loading cleanup or returning control.
	 */
	public function test_direct_entry_without_uninstall_gate_exits_silently(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Safe PHP literal for an isolated test process.
		$entrypoint = var_export( dirname( __DIR__ ) . '/uninstall.php', true );
		$result     = $this->run_php( "include {$entrypoint}; echo 'tail';" );

		self::assertSame( 0, $result['status'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * WordPress-gated execution emits nothing and returns to the deletion flow.
	 */
	public function test_wordpress_uninstall_gate_runs_silently_and_returns(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Safe PHP literal for an isolated test process.
		$bootstrap = var_export( __DIR__ . '/bootstrap.php', true );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Safe PHP literal for an isolated test process.
		$entrypoint = var_export( dirname( __DIR__ ) . '/uninstall.php', true );
		$code       = "require {$bootstrap}; define( 'WP_UNINSTALL_PLUGIN', true ); ob_start(); include {$entrypoint}; \$inner = ob_get_clean(); echo 'tail:' . \$inner;";
		$result     = $this->run_php( $code );

		self::assertSame( 0, $result['status'] );
		self::assertSame( 'tail:', $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * Seed one physical option row and matching Core option value.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param int    $option_id Physical option ID.
	 * @param string $name Exact option name.
	 */
	private function seed_option( int $blog_id, int $option_id, string $name ): void {
		$options          =& \wp_auto_test_options_for_blog( $blog_id );
		$options[ $name ] = array( 'private' => true );
		$GLOBALS['wp_auto_test_option_rows'][ $blog_id ][] = array(
			'option_id'   => (string) $option_id,
			'option_name' => $name,
		);
	}

	/**
	 * Build one canonical idempotency option name.
	 *
	 * @param string $character Repeated lowercase hexadecimal character.
	 */
	private function idempotency_name( string $character ): string {
		return 'wp_auto_connector_idempotency_' . str_repeat( $character, 64 );
	}

	/**
	 * Build one canonical media idempotency option name.
	 *
	 * @param string $character Repeated lowercase hexadecimal character.
	 */
	private function media_idempotency_name( string $character ): string {
		return 'wp_auto_connector_media_idempotency_' . str_repeat( $character, 64 );
	}

	/**
	 * Build one canonical taxonomy idempotency option name.
	 *
	 * @param string $character Repeated lowercase hexadecimal character.
	 */
	private function taxonomy_idempotency_name( string $character ): string {
		return 'wp_auto_connector_taxonomy_idempotency_' . str_repeat( $character, 64 );
	}

	/**
	 * Build one canonical audit-lock option name.
	 *
	 * @param string $character Repeated lowercase hexadecimal character.
	 */
	private function audit_lock_name( string $character ): string {
		return 'wp_auto_connector_mutation_audit_lock_' . str_repeat( $character, 64 );
	}

	/**
	 * Execute an isolated PHP snippet for entrypoint lifecycle assertions.
	 *
	 * @param string $code PHP code without opening tag.
	 * @return array{status:int,stdout:string,stderr:string}
	 */
	private function run_php( string $code ): array {
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolated local test process.
		$process = proc_open( array( PHP_BINARY, '-r', $code ), $descriptors, $pipes, dirname( __DIR__ ) );
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents
		$stdout = stream_get_contents( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents
		$stderr = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local subprocess pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local subprocess pipe.
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'stdout' => false === $stdout ? '' : $stdout,
			'stderr' => false === $stderr ? '' : $stderr,
		);
	}
}
