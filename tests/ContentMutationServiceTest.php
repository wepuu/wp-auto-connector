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
		$GLOBALS['wp_auto_test_posts']              = array();
		$GLOBALS['wp_auto_test_options']            = array();
		$GLOBALS['wp_auto_test_post_meta']          = array();
		$GLOBALS['wp_auto_test_capabilities']       = array(
			'edit_posts' => true,
			'edit_pages' => true,
		);
		$GLOBALS['wp_auto_test_current_user_id']    = 7;
		$GLOBALS['wp_auto_test_next_post_id']       = 1000;
		$GLOBALS['wp_auto_test_last_insert_args']   = array();
		$GLOBALS['wp_auto_test_insert_result']      = null;
		$GLOBALS['wp_auto_test_insert_exception']   = null;
		$GLOBALS['wp_auto_test_fail_update_option'] = false;
		$GLOBALS['wp_auto_test_fail_update_meta']   = false;
		$GLOBALS['wp_auto_test_before_update_meta'] = null;
		$GLOBALS['wp_auto_test_nested_recovery']    = null;
		$GLOBALS['wp_auto_test_filters']            = array();
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
	 * A known target is recovered without a duplicate when audit finalization was interrupted.
	 */
	public function test_known_target_recovery_completes_without_duplicate(): void {
		$service = new ContentMutationService();
		$first   = $service->create_post_draft(
			array(
				'title'           => 'Original',
				'idempotency_key' => 'blocked01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state'] = 'target_recorded';
		}
		unset( $record );
		$GLOBALS['wp_auto_test_post_meta'][ $first['id'] ][ MutationAuditStore::meta_key() ] = array();
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
	}

	/**
	 * Known-target recovery does not append a duplicate audit event.
	 */
	public function test_known_target_recovery_reuses_existing_audit_event(): void {
		$service = new ContentMutationService();
		$first   = $service->create_post_draft(
			array(
				'title'           => 'Audited target',
				'idempotency_key' => 'audited01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state'] = 'target_recorded';
		}
		unset( $record );

		$result = $service->create_post_draft(
			array(
				'title'           => 'Audited target',
				'idempotency_key' => 'audited01',
			)
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['idempotency_replayed'] );
		self::assertCount( 1, get_post_meta( $first['id'], MutationAuditStore::meta_key(), true ) );
	}

	/**
	 * Re-entrant recovery during the first audit write cannot append twice.
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
		self::assertSame( 'wp_auto_mutation_state_uncertain', $GLOBALS['wp_auto_test_nested_recovery']->get_error_code() );
		self::assertCount( 1, get_post_meta( $result['id'], MutationAuditStore::meta_key(), true ) );
		self::assertSame( 'create', get_post_meta( $result['id'], MutationAuditStore::meta_key(), true )[0]['operation'] );
	}

	/**
	 * A known target that cannot be verified remains uncertain.
	 */
	public function test_invalid_known_target_fails_closed(): void {
		$service = new ContentMutationService();
		$service->create_post_draft(
			array(
				'title'           => 'Invalid target',
				'idempotency_key' => 'invalid01',
			)
		);
		foreach ( $GLOBALS['wp_auto_test_options'] as &$record ) {
			$record['state']     = 'target_recorded';
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
		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
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
		$GLOBALS['wp_auto_test_insert_result'] = new \RuntimeException( 'after insert' );
		$result                                = ( new ContentMutationService() )->create_post_draft(
			array(
				'title'           => 'Uncertain',
				'idempotency_key' => 'uncert01',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
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
	public function test_audit_failure_returns_uncertain_after_target_is_recorded(): void {
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
		self::assertSame( 'target_recorded', $record['state'] );
	}
}
