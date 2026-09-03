<?php
/**
 * Shared Create Draft service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPAuto\Connector\Content\ContentMutationService;
use WPAuto\Connector\Content\MutationAuditStore;

/**
 * Covers authorization, Core invariants, idempotency and failure semantics.
 */
final class ContentMutationServiceTest extends TestCase {
	/**
	 * Reset mutation fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_posts']                             = array();
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_notoptions_cache']                  = null;
		$GLOBALS['wp_auto_test_alloptions_cache']                  = null;
		$GLOBALS['wp_auto_test_use_option_cache']                  = false;
		$GLOBALS['wp_auto_test_cache_delete_exception']            = null;
		$GLOBALS['wp_auto_test_db_query_exception']                = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception']    = null;
		$GLOBALS['wp_auto_test_db_last_error']                     = '';
		$GLOBALS['wp_auto_test_db_return_override']                = null;
		$GLOBALS['wp_auto_test_db_suppress_state']                 = false;
		$GLOBALS['wp_auto_test_db_suppress_history']               = array();
		$GLOBALS['wp_auto_test_db_prepared_queries']               = array();
		$GLOBALS['wp_auto_test_db_query_calls']                    = 0;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_post_meta']                         = array();
		$GLOBALS['wp_auto_test_post_meta_values']                  = array();
		$GLOBALS['wp_auto_test_capabilities']                      = array(
			'edit_posts' => true,
			'edit_pages' => true,
		);
		$GLOBALS['wp_auto_test_current_user_id']                   = 7;
		$GLOBALS['wp_auto_test_next_post_id']                      = 1000;
		$GLOBALS['wp_auto_test_last_insert_args']                  = array();
		$GLOBALS['wp_auto_test_insert_result']                     = null;
		$GLOBALS['wp_auto_test_insert_exception']                  = null;
		$GLOBALS['wp_auto_test_insert_calls']                      = 0;
		$GLOBALS['wp_auto_test_add_option_exception']              = null;
		$GLOBALS['wp_auto_test_add_option_exception_after_write']  = null;
		$GLOBALS['wp_auto_test_fail_update_option']                = false;
		$GLOBALS['wp_auto_test_fail_update_option_on_call']        = null;
		$GLOBALS['wp_auto_test_update_option_exception_on_call']   = null;
		$GLOBALS['wp_auto_test_update_option_calls']               = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']                = false;
		$GLOBALS['wp_auto_test_delete_option_exception']           = null;
		$GLOBALS['wp_auto_test_delete_option_calls']               = 0;
		$GLOBALS['wp_auto_test_fail_update_meta']                  = false;
		$GLOBALS['wp_auto_test_update_meta_exception']             = null;
		$GLOBALS['wp_auto_test_get_post_meta_exception']           = null;
		$GLOBALS['wp_auto_test_before_update_meta']                = null;
		$GLOBALS['wp_auto_test_nested_recovery']                   = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
		$GLOBALS['wp_auto_test_get_post_calls']                    = 0;
		$GLOBALS['wp_auto_test_before_get_post']                   = null;
		$GLOBALS['wp_auto_test_get_post_exception']                = null;
		$GLOBALS['wp_auto_test_get_post_exception_on_call']        = null;
		$GLOBALS['wp_auto_test_permalink_exception']               = null;
		$GLOBALS['wp_auto_test_edit_link_exception']               = null;
		$GLOBALS['wp_auto_test_filters']                           = array();
	}

	/**
	 * Creates a Post with fixed invariants and exact output.
	 */
	public function test_creates_a_post_with_fixed_invariants_and_exact_output(): void {
		$result = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => "O'Reilly draft",
				'content'         => 'Body \\ with <strong>HTML</strong>.',
				'excerpt'         => 'Summary',
				'slug'            => 'draft-slug',
				'idempotency_key' => 'post-key1',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( array( 'id', 'type', 'status', 'slug', 'link', 'edit_url', 'modified_gmt', 'idempotency_replayed' ), array_keys( $result ) );
		self::assertSame( 'post', $result['type'] );
		self::assertSame( 'draft', $result['status'] );
		self::assertFalse( $result['idempotency_replayed'] );
		self::assertSame( 'post', $GLOBALS['wp_auto_test_posts'][0]->post_type );
		self::assertSame( 'draft', $GLOBALS['wp_auto_test_posts'][0]->post_status );
		self::assertSame( 7, $GLOBALS['wp_auto_test_posts'][0]->post_author );
		self::assertSame( 'draft-slug', $GLOBALS['wp_auto_test_posts'][0]->post_name );
		self::assertArrayNotHasKey( 'tax_input', $GLOBALS['wp_auto_test_last_insert_args'] );
		self::assertArrayNotHasKey( 'meta_input', $GLOBALS['wp_auto_test_last_insert_args'] );
		self::assertCount( 1, get_post_meta( $result['id'], MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Creates a root Page and ignores any client parent.
	 */
	public function test_creates_a_root_page_and_never_uses_client_parent(): void {
		$result = ( new ContentMutationService() )->create_page_draft(
			array(
				'title'           => 'Root page',
				'idempotency_key' => 'page-key1',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( 'page', $result['type'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_posts'][0]->post_parent );
		self::assertSame( 0, $GLOBALS['wp_auto_test_last_insert_args']['post_parent'] );
	}

	/**
	 * Rejects invalid service input.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 * @dataProvider invalid_input_provider
	 */
	public function test_rejects_invalid_input( array $input ): void {
		$result = ( new ContentMutationService() )->create_post_draft( $input );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * Return invalid input fixtures.
	 */
	public function invalid_input_provider(): array {
		return array(
			'missing title' => array( array( 'idempotency_key' => 'validkey' ) ),
			'blank title'   => array(
				array(
					'title'           => " \n\t",
					'idempotency_key' => 'validkey',
				),
			),
			'bad key'       => array(
				array(
					'title'           => 'Title',
					'idempotency_key' => 'bad key!',
				),
			),
			'unknown field' => array(
				array(
					'title'           => 'Title',
					'idempotency_key' => 'validkey',
					'status'          => 'publish',
				),
			),
			'bad content'   => array(
				array(
					'title'           => 'Title',
					'idempotency_key' => 'validkey',
					'content'         => 12,
				),
			),
			'empty slug'    => array(
				array(
					'title'           => 'Title',
					'idempotency_key' => 'validkey',
					'slug'            => '',
				),
			),
		);
	}

	/**
	 * Completed replay returns the same object without a new audit event.
	 */
	public function test_completed_replay_is_same_object_and_does_not_append_audit(): void {
		$service = new ContentMutationService();
		$input   = array(
			'title'           => 'Replay me',
			'idempotency_key' => 'replay01',
		);
		$first   = $service->create_post_draft( $input );
		$second  = $service->create_post_draft( $input );

		self::assertSame( $first['id'], $second['id'] );
		self::assertTrue( $second['idempotency_replayed'] );
		self::assertCount( 1, get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * A payload conflict never creates another object.
	 */
	public function test_payload_conflict_never_creates_again(): void {
		$service = new ContentMutationService();
		$service->create_post_draft(
			array(
				'title'           => 'First',
				'idempotency_key' => 'samekey1',
			)
		);
		$result = $service->create_post_draft(
			array(
				'title'           => 'Different',
				'idempotency_key' => 'samekey1',
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_idempotency_conflict', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * An audit-recorded target is recovered without a duplicate audit event.
	 */
	public function test_audit_recorded_recovery_completes_without_duplicate(): void {
		$service = new ContentMutationService();
		$first   = $service->create_post_draft(
			array(
				'title'           => 'Original',
				'idempotency_key' => 'blocked01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state'] = 'audit_recorded';
		}
		unset( $record );
		$result = $service->create_post_draft(
			array(
				'title'           => 'Original',
				'idempotency_key' => 'blocked01',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( $first['id'], $result['id'] );
		self::assertTrue( $result['idempotency_replayed'] );
		self::assertCount( 1, get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 'completed', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * A target-correlated in-progress claim remains blocked without audit access.
	 */
	public function test_in_progress_known_target_does_not_recover(): void {
		$service = new ContentMutationService();
		$first   = $service->create_post_draft(
			array(
				'title'           => 'Audited target',
				'idempotency_key' => 'audited01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state']     = 'in_progress';
			$record['target_id'] = $first['id'];
		}
		unset( $record );
		$GLOBALS['wp_auto_test_post_meta'][ $first['id'] ][ MutationAuditStore::meta_key() ] = array();

		$result = $service->create_post_draft(
			array(
				'title'           => 'Audited target',
				'idempotency_key' => 'audited01',
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_idempotency_in_progress', $result->get_error_code() );
		self::assertSame( array(), get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * An audit-recorded claim with missing audit metadata fails closed.
	 */
	public function test_audit_recorded_missing_audit_fails_closed_without_recovery_write(): void {
		$service = new ContentMutationService();
		$first   = $service->create_post_draft(
			array(
				'title'           => 'Missing audit',
				'idempotency_key' => 'missing01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state'] = 'audit_recorded';
		}
		unset( $record );
		$GLOBALS['wp_auto_test_post_meta'][ $first['id'] ][ MutationAuditStore::meta_key() ] = array();
		$GLOBALS['wp_auto_test_update_meta_calls'] = 0;

		$result = $service->create_post_draft(
			array(
				'title'           => 'Missing audit',
				'idempotency_key' => 'missing01',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( 'audit_recorded', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * A completion failure after audit recording can be replayed safely.
	 */
	public function test_audit_recorded_completion_failure_recovers_without_new_audit(): void {
		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = 3;
		$input = array(
			'title'           => 'Completion retry',
			'idempotency_key' => 'complete1',
		);
		$first = ( new ContentMutationService() )->create_post_draft( $input );

		self::assertSame( 'wp_auto_mutation_state_uncertain', $first->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		$target_id = $GLOBALS['wp_auto_test_posts'][0]->ID;
		self::assertSame( 'audit_recorded', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
		self::assertCount( 1, get_post_meta( $target_id, MutationAuditStore::meta_key(), true ) );

		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = null;
		$replay = ( new ContentMutationService() )->create_post_draft( $input );

		self::assertIsArray( $replay );
		self::assertTrue( $replay['idempotency_replayed'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertCount( 1, get_post_meta( $target_id, MutationAuditStore::meta_key(), true ) );
		self::assertSame( 'completed', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * Re-entrant retry during the first audit write is blocked by in-progress state.
	 */
	public function test_reentrant_recovery_keeps_one_logical_create_event(): void {
		$input                                      = array(
			'title'           => 'Interleaved audit',
			'idempotency_key' => 'interleave1',
		);
		$GLOBALS['wp_auto_test_before_update_meta'] = static function () use ( $input ): void {
			$GLOBALS['wp_auto_test_nested_recovery'] = ( new ContentMutationService() )->create_post_draft( $input );
		};

		$result = ( new ContentMutationService() )->create_post_draft( $input );

		self::assertIsArray( $result );
		self::assertInstanceOf( WP_Error::class, $GLOBALS['wp_auto_test_nested_recovery'] );
		self::assertSame( 'wp_auto_idempotency_in_progress', $GLOBALS['wp_auto_test_nested_recovery']->get_error_code() );
		self::assertCount( 1, get_post_meta( $result['id'], MutationAuditStore::meta_key(), true ) );
		self::assertSame( 'create', get_post_meta( $result['id'], MutationAuditStore::meta_key(), true )[0]['operation'] );
		self::assertSame( 'completed', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/**
	 * An in-progress claim remains blocked even when its target cannot be verified.
	 */
	public function test_in_progress_invalid_target_remains_blocked(): void {
		$service = new ContentMutationService();
		$service->create_post_draft(
			array(
				'title'           => 'Invalid target',
				'idempotency_key' => 'invalid01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state']     = 'in_progress';
			$record['target_id'] = 99999;
		}
		unset( $record );

		$result = $service->create_post_draft(
			array(
				'title'           => 'Invalid target',
				'idempotency_key' => 'invalid01',
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_idempotency_in_progress', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * A proven empty Core failure releases its claim.
	 */
	public function test_core_failure_releases_only_a_proven_empty_claim(): void {
		$GLOBALS['wp_auto_test_insert_result'] = new WP_Error( 'core_failure', 'hidden' );
		$result                                = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Fails',
				'idempotency_key' => 'failure1',
			)
		);

		self::assertSame( 'wp_auto_content_create_failed', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * An exception after insert keeps the claim and blocks retries.
	 */
	public function test_exception_after_insert_keeps_claim_and_prevents_retry(): void {
		$GLOBALS['wp_auto_test_insert_result'] = new \RuntimeException( 'sensitive-internal-detail' );
		$result                                = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Uncertain',
				'idempotency_key' => 'uncert01',
			)
		);

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters']['wp_insert_post_data'][ PHP_INT_MAX ] ?? array() );
		$retry = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Uncertain',
				'idempotency_key' => 'uncert01',
			)
		);
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
	}

	/**
	 * The final guard reasserts fixed values against a filter.
	 */
	public function test_final_guard_reasserts_fixed_values_against_a_filter(): void {
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_type']   = 'page';
				$data['post_status'] = 'publish';
				$data['post_author'] = 99;
				$data['post_parent'] = 42;
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Guarded',
				'idempotency_key' => 'guarded1',
			)
		);
		self::assertIsArray( $result );
		self::assertSame( 'post', $GLOBALS['wp_auto_test_posts'][0]->post_type );
		self::assertSame( 'draft', $GLOBALS['wp_auto_test_posts'][0]->post_status );
		self::assertSame( 7, $GLOBALS['wp_auto_test_posts'][0]->post_author );
		self::assertSame( 42, $GLOBALS['wp_auto_test_posts'][0]->post_parent );
	}

	/**
	 * A nested insert without the operation marker is not rewritten by the guard.
	 */
	public function test_guard_does_not_rewrite_unrelated_nested_insert(): void {
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				static $nested = false;
				if ( ! $nested ) {
					$nested = true;
					wp_insert_post(
						array(
							'post_type'   => 'page',
							'post_status' => 'publish',
							'post_author' => 99,
							'post_parent' => 42,
						),
						true,
						true
					);
				}

				$data['post_type']   = 'page';
				$data['post_status'] = 'publish';
				$data['post_author'] = 99;
				$data['post_parent'] = 42;
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Nested guard',
				'idempotency_key' => 'nested01',
			)
		);

		self::assertIsArray( $result );
		self::assertCount( 2, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 'page', $GLOBALS['wp_auto_test_posts'][0]->post_type );
		self::assertSame( 'publish', $GLOBALS['wp_auto_test_posts'][0]->post_status );
		self::assertSame( 99, $GLOBALS['wp_auto_test_posts'][0]->post_author );
		self::assertSame( 42, $GLOBALS['wp_auto_test_posts'][0]->post_parent );
		self::assertSame( 'post', $GLOBALS['wp_auto_test_posts'][1]->post_type );
		self::assertSame( 'draft', $GLOBALS['wp_auto_test_posts'][1]->post_status );
		self::assertSame( 7, $GLOBALS['wp_auto_test_posts'][1]->post_author );
		self::assertSame( 42, $GLOBALS['wp_auto_test_posts'][1]->post_parent );
	}

	/**
	 * Audit failure leaves a recorded target in an uncertain state.
	 */
	public function test_audit_failure_returns_uncertain_while_target_remains_in_progress(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$result                                   = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Audit fails',
				'idempotency_key' => 'audit001',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		$record = array_values( $GLOBALS['wp_auto_test_options'] )[0];
		self::assertSame( 'in_progress', $record['state'] );
		self::assertSame( 1001, $record['target_id'] );

		$retry = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Audit fails',
				'idempotency_key' => 'audit001',
			)
		);
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
	}

	/**
	 * Target correlation failure keeps the claim blocking after Core creation.
	 */
	public function test_target_correlation_failure_keeps_claim_blocking(): void {
		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = 1;
		$input  = array(
			'title'           => 'Correlation fails',
			'idempotency_key' => 'correlate1',
		);
		$result = ( new ContentMutationService() )->create_post_draft( $input );

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 'in_progress', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
		self::assertSame( 0, array_values( $GLOBALS['wp_auto_test_options'] )[0]['target_id'] );

		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = null;
		$retry = ( new ContentMutationService() )->create_post_draft( $input );
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * Claim exceptions fail closed before Core Create is attempted.
	 */
	public function test_claim_throwable_is_sanitized_before_core_create(): void {
		$GLOBALS['wp_auto_test_add_option_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'claimerr1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * A claim exception after persistence remains blocking and never creates.
	 */
	public function test_claim_mutate_then_throw_is_sanitized_and_remains_blocking(): void {
		$GLOBALS['wp_auto_test_add_option_exception_after_write'] = new \RuntimeException( 'sensitive-internal-detail' );
		$input  = $this->create_input( 'claimlate' );
		$result = ( new ContentMutationService() )->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );

		$retry = ( new ContentMutationService() )->create_post_draft( $input );
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
	}

	/**
	 * A claim whose cache readback cannot be proven never enters Core Create.
	 */
	public function test_unresolved_atomic_claim_never_enters_core_create(): void {
		$GLOBALS['wp_auto_test_cache_delete_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'cacheunc1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A definitive Core failure remains create-failed after verified release.
	 */
	public function test_definitive_core_failure_with_verified_release_remains_create_failed(): void {
		$GLOBALS['wp_auto_test_insert_result'] = new WP_Error( 'core_failure', 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'released1' ) );

		self::assertSame( 'wp_auto_content_create_failed', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A false claim release cannot prove a definitive Create failure.
	 */
	public function test_release_false_maps_definitive_core_failure_to_uncertain(): void {
		$GLOBALS['wp_auto_test_insert_result']      = new WP_Error( 'core_failure', 'hidden' );
		$GLOBALS['wp_auto_test_fail_delete_option'] = true;

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'releasef1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * A throwing claim release is sanitized and is not retried.
	 */
	public function test_release_throwable_maps_definitive_core_failure_to_uncertain(): void {
		$GLOBALS['wp_auto_test_insert_result']           = new WP_Error( 'core_failure', 'hidden' );
		$GLOBALS['wp_auto_test_delete_option_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'releaset1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * Target-correlation exceptions retain the target and blocking claim.
	 */
	public function test_target_correlation_throwable_keeps_target_and_blocks_retry(): void {
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = 1;
		$input  = $this->create_input( 'targetex1' );
		$result = ( new ContentMutationService() )->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_delete_option_calls'] );
		$retry = ( new ContentMutationService() )->create_post_draft( $input );
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/**
	 * Post-write target-read exceptions are sanitized without rollback.
	 */
	public function test_post_write_get_post_throwable_is_sanitized(): void {
		$GLOBALS['wp_auto_test_get_post_exception']         = new \RuntimeException( 'sensitive-internal-detail' );
		$GLOBALS['wp_auto_test_get_post_exception_on_call'] = 1;

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'getpost1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Permalink exceptions during Create output are sanitized.
	 */
	public function test_create_permalink_throwable_is_sanitized(): void {
		$GLOBALS['wp_auto_test_permalink_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'permalink1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Edit-link exceptions during Create output are sanitized.
	 */
	public function test_create_edit_link_throwable_is_sanitized(): void {
		$GLOBALS['wp_auto_test_edit_link_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'editlink1' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Audit append exceptions are not retried or rolled back.
	 */
	public function test_audit_append_throwable_is_sanitized_without_retry(): void {
		$GLOBALS['wp_auto_test_update_meta_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'auditex01' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_delete_option_calls'] );
	}

	/**
	 * Audit-state transition exceptions are sanitized without a second audit.
	 */
	public function test_mark_audit_recorded_throwable_is_sanitized_without_duplicate_audit(): void {
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = 2;

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'markex001' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 1, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertCount( 1, get_post_meta( 1001, MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Completion exceptions preserve one target and one logical audit event.
	 */
	public function test_complete_throwable_is_sanitized_without_duplicate_create_or_audit(): void {
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = 3;

		$result = ( new ContentMutationService() )->create_post_draft( $this->create_input( 'completex' ) );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
		self::assertCount( 1, get_post_meta( 1001, MutationAuditStore::meta_key(), true ) );
		self::assertSame( 'audit_recorded', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * Completed replay target-read exceptions never trigger Create or audit.
	 */
	public function test_completed_replay_get_post_throwable_is_sanitized_without_mutation(): void {
		$input                                      = $this->create_input( 'replaygp1' );
		$service                                    = new ContentMutationService();
		$first                                      = $service->create_post_draft( $input );
		$GLOBALS['wp_auto_test_insert_calls']       = 0;
		$GLOBALS['wp_auto_test_update_meta_calls']  = 0;
		$GLOBALS['wp_auto_test_get_post_calls']     = 0;
		$GLOBALS['wp_auto_test_get_post_exception'] = new \RuntimeException( 'sensitive-internal-detail' );
		$GLOBALS['wp_auto_test_get_post_exception_on_call'] = 1;

		$result = $service->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertCount( 1, get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Completed replay output exceptions never trigger Create or audit.
	 */
	public function test_completed_replay_output_throwable_is_sanitized_without_mutation(): void {
		$input                                       = $this->create_input( 'replayout' );
		$service                                     = new ContentMutationService();
		$first                                       = $service->create_post_draft( $input );
		$GLOBALS['wp_auto_test_insert_calls']        = 0;
		$GLOBALS['wp_auto_test_update_meta_calls']   = 0;
		$GLOBALS['wp_auto_test_edit_link_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = $service->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertCount( 1, get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Audit-recorded recovery sanitizes audit-evidence read exceptions.
	 */
	public function test_audit_recorded_recovery_audit_read_throwable_is_sanitized(): void {
		$input   = $this->create_input( 'auditread' );
		$service = new ContentMutationService();
		$service->create_post_draft( $input );
		$this->set_only_claim_state( 'audit_recorded' );
		$GLOBALS['wp_auto_test_insert_calls']            = 0;
		$GLOBALS['wp_auto_test_update_meta_calls']       = 0;
		$GLOBALS['wp_auto_test_get_post_meta_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = $service->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( 'audit_recorded', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * Audit-recorded recovery sanitizes completion exceptions.
	 */
	public function test_audit_recorded_recovery_complete_throwable_is_sanitized(): void {
		$input   = $this->create_input( 'recovercp' );
		$service = new ContentMutationService();
		$service->create_post_draft( $input );
		$this->set_only_claim_state( 'audit_recorded' );
		$GLOBALS['wp_auto_test_update_option_calls']             = 0;
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = 1;
		$GLOBALS['wp_auto_test_insert_calls']                    = 0;
		$GLOBALS['wp_auto_test_update_meta_calls']               = 0;

		$result = $service->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( 'audit_recorded', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * Recovery output exceptions preserve the completed persistent state.
	 */
	public function test_audit_recorded_recovery_output_throwable_preserves_completion(): void {
		$input   = $this->create_input( 'recoverop' );
		$service = new ContentMutationService();
		$service->create_post_draft( $input );
		$this->set_only_claim_state( 'audit_recorded' );
		$GLOBALS['wp_auto_test_insert_calls']        = 0;
		$GLOBALS['wp_auto_test_update_meta_calls']   = 0;
		$GLOBALS['wp_auto_test_permalink_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = $service->create_post_draft( $input );

		$this->assert_uncertain_without_sensitive_detail( $result );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
		self::assertSame( 'completed', array_values( $GLOBALS['wp_auto_test_options'] )[0]['state'] );
	}

	/**
	 * Return one valid Create input.
	 *
	 * @param string $key Idempotency key.
	 * @return array<string, string>
	 */
	private function create_input( string $key ): array {
		return array(
			'title'           => 'SEC-1 draft',
			'idempotency_key' => $key,
		);
	}

	/**
	 * Change the single fixture claim state without changing its target.
	 *
	 * @param string $state State to install.
	 */
	private function set_only_claim_state( string $state ): void {
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state'] = $state;
		}
		unset( $record );
	}

	/**
	 * Assert the frozen sanitized uncertain-state error.
	 *
	 * @param mixed $result Service result.
	 */
	private function assert_uncertain_without_sensitive_detail( $result ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertStringNotContainsString( 'sensitive-internal-detail', $result->get_error_message() );
	}
}
