<?php
/**
 * Taxonomy assignment service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TaxonomyAssignmentService;
use WPAuto\Connector\Taxonomy\TaxonomyMutationAuditStore;

/** Covers authorization, bounded replacement, concurrency, and invariant proof. */
final class TaxonomyAssignmentServiceTest extends TestCase {
	/** Reset a draft Post, built-in terms, relationships, and private state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']                = 7;
		$GLOBALS['wp_auto_test_capabilities']                   = array(
			'assign_terms' => true,
			'edit_posts'   => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']            = array();
		$GLOBALS['wp_auto_test_posts']                          = array(
			new \WP_Post(
				array(
					'ID'          => 100,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_author' => 7,
				)
			),
		);
		$GLOBALS['wp_auto_test_taxonomy_terms']                 = array(
			new \WP_Term(
				array(
					'term_id'  => 10,
					'name'     => 'News',
					'taxonomy' => 'category',
				)
			),
			new \WP_Term(
				array(
					'term_id'  => 11,
					'name'     => 'Product',
					'taxonomy' => 'category',
				)
			),
			new \WP_Term(
				array(
					'term_id'  => 20,
					'name'     => 'Featured',
					'taxonomy' => 'post_tag',
				)
			),
		);
		$GLOBALS['wp_auto_test_taxonomies']                     = array(
			'category' => (object) array(
				'cap' => (object) array(
					'manage_terms' => 'manage_categories',
					'assign_terms' => 'assign_terms',
				),
			),
			'post_tag' => (object) array(
				'cap' => (object) array(
					'manage_terms' => 'manage_categories',
					'assign_terms' => 'assign_terms',
				),
			),
		);
		$GLOBALS['wp_auto_test_object_term_ids']                = array(
			100 => array(
				'category' => array(),
				'post_tag' => array( 20 ),
			),
		);
		$GLOBALS['wp_auto_test_set_object_terms_calls']         = 0;
		$GLOBALS['wp_auto_test_set_object_terms_result']        = null;
		$GLOBALS['wp_auto_test_set_object_terms_exception']     = null;
		$GLOBALS['wp_auto_test_before_set_object_terms']        = null;
		$GLOBALS['wp_auto_test_after_set_object_terms']         = null;
		$GLOBALS['wp_auto_test_object_terms_result']            = null;
		$GLOBALS['wp_auto_test_post_meta']                      = array( 100 => array( '_unrelated' => 'keep' ) );
		$GLOBALS['wp_auto_test_post_meta_values']               = array();
		$GLOBALS['wp_auto_test_filters']                        = array();
		$GLOBALS['wp_auto_test_options']                        = array();
		$GLOBALS['wp_auto_test_option_autoload']                = array();
		$GLOBALS['wp_auto_test_option_cache']                   = array();
		$GLOBALS['wp_auto_test_notoptions_cache']               = null;
		$GLOBALS['wp_auto_test_alloptions_cache']               = null;
		$GLOBALS['wp_auto_test_use_option_cache']               = false;
		$GLOBALS['wp_auto_test_cache_delete_exception']         = null;
		$GLOBALS['wp_auto_test_db_query_calls']                 = 0;
		$GLOBALS['wp_auto_test_db_query_exception']             = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
		$GLOBALS['wp_auto_test_db_last_error']                  = '';
		$GLOBALS['wp_auto_test_db_return_override']             = null;
		$GLOBALS['wp_auto_test_db_suppress_state']              = false;
		$GLOBALS['wp_auto_test_db_prepared_queries']            = array();
		$GLOBALS['wp_auto_test_uuid_counter']                   = 0;
		$GLOBALS['wp_auto_test_current_blog_id']                = 1;
		$GLOBALS['wp_auto_test_update_meta_calls']              = 0;
		$GLOBALS['wp_auto_test_fail_update_meta']               = false;
		$GLOBALS['wp_auto_test_update_meta_exception']          = null;
	}

	/** Successful replacement passes the exact IDs, preserves the non-target set, and audits once. */
	public function test_assigns_category_set_on_draft_post(): void {
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );

		self::assertSame(
			array(
				'target_id'   => 100,
				'target_type' => 'post',
				'status'      => 'draft',
				'taxonomy'    => 'category',
				'term_ids'    => array( 11 ),
				'changed'     => true,
			),
			$result
		);
		self::assertSame( 1, $GLOBALS['wp_auto_test_set_object_terms_calls'] );
		self::assertSame(
			array(
				'object_id' => 100,
				'term_ids'  => array( 11 ),
				'taxonomy'  => 'category',
				'append'    => false,
			),
			$GLOBALS['wp_auto_test_last_set_object_terms_args']
		);
		self::assertSame( array( 20 ), $GLOBALS['wp_auto_test_object_term_ids'][100]['post_tag'] );
		$event = $GLOBALS['wp_auto_test_post_meta'][100][ TaxonomyMutationAuditStore::meta_key() ][0];
		self::assertSame( array( 11 ), $event['result_term_ids'] );
		self::assertSame( array( '_unrelated' => array( 'keep' ) ), array_intersect_key( get_post_meta( 100 ), array( '_unrelated' => true ) ) );
	}

	/** The same service handles the fixed post_tag taxonomy without touching Categories. */
	public function test_assigns_tag_set_on_draft_post(): void {
		$result = ( new TaxonomyAssignmentService() )->assign(
			array(
				'target_id'         => 100,
				'taxonomy'          => 'post_tag',
				'term_ids'          => array( 20 ),
				'expected_term_ids' => array(),
			)
		);

		self::assertIsArray( $result );
		self::assertSame( 'post_tag', $result['taxonomy'] );
		self::assertSame( array( 20 ), $result['term_ids'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_object_term_ids'][100]['category'] );
	}

	/** A no-op is safe and does not write or append an audit event, even with a stale expected set. */
	public function test_noop_ignores_stale_precondition_without_write(): void {
		$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = array( 10 );
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 10 ), array( 999 ) ) );

		self::assertIsArray( $result );
		self::assertFalse( $result['changed'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_set_object_terms_calls'] );
		self::assertArrayNotHasKey( TaxonomyMutationAuditStore::meta_key(), $GLOBALS['wp_auto_test_post_meta'][100] ?? array() );
	}

	/** A changed set with a stale expected precondition is rejected before Core. */
	public function test_stale_expected_set_is_conflict(): void {
		$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = array( 10 );
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_conflict', $result->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_set_object_terms_calls'] );
	}

	/** IDs are strict, unique, bounded integers and empty desired sets are unavailable. */
	public function test_input_validation_is_strict_and_bounded(): void {
		$service = new TaxonomyAssignmentService();
		foreach (
			array(
				array(
					'target_id'         => '100',
					'taxonomy'          => 'category',
					'term_ids'          => array( 10 ),
					'expected_term_ids' => array(),
				),
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array(),
					'expected_term_ids' => array(),
				),
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array( 10, 10 ),
					'expected_term_ids' => array(),
				),
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array( 2 => 10 ),
					'expected_term_ids' => array(),
				),
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array( 10 ),
					'expected_term_ids' => array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51 ),
				),
			)
			as $input
		) {
			$result = $service->assign( $input );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		}
	}

	/** Requested IDs must resolve to the selected built-in taxonomy. */
	public function test_missing_or_wrong_taxonomy_term_is_hidden(): void {
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 20 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_term_not_found', $result->get_error_code() );

		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 999 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_term_not_found', $result->get_error_code() );
	}

	/** Authorization requires the taxonomy capability, post-type edit baseline, final draft Post, and object edit. */
	public function test_authorization_hides_unsupported_targets_and_capabilities(): void {
		$service                                 = new TaxonomyAssignmentService();
		$GLOBALS['wp_auto_test_current_user_id'] = 0;
		self::assertInstanceOf( \WP_Error::class, $service->assign( $this->request( array( 10 ), array() ) ) );
		$GLOBALS['wp_auto_test_current_user_id']              = 7;
		$GLOBALS['wp_auto_test_capabilities']['assign_terms'] = false;
		self::assertFalse( $service->can_assign( $this->request( array( 10 ), array() ) ) );
		$GLOBALS['wp_auto_test_capabilities']['assign_terms']          = true;
		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][100] = false;
		$result = $service->assign( $this->request( array( 10 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_content_not_found', $result->get_error_code() );
	}

	/** Selected and protected non-target relationship sets reject overflow using the fixed 51-item probe. */
	public function test_relationship_overflow_fails_closed(): void {
		$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = range( 1, 51 );
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 10 ), range( 1, 50 ) ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_set_too_large', $result->get_error_code() );
		self::assertSame( 51, $GLOBALS['wp_auto_test_last_object_terms_args']['args']['number'] );

		$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = array();
		$GLOBALS['wp_auto_test_object_term_ids'][100]['post_tag'] = range( 1, 51 );
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 10 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_set_too_large', $result->get_error_code() );
	}

	/** Core failure is reported only when final relationships prove unchanged. */
	public function test_core_failure_and_partial_state_are_distinguished(): void {
		$GLOBALS['wp_auto_test_set_object_terms_result'] = false;
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_assign_failed', $result->get_error_code() );

		$GLOBALS['wp_auto_test_set_object_terms_result'] = new \WP_Error( 'core_failure', 'hidden' );
		$GLOBALS['wp_auto_test_before_set_object_terms'] = static function (): void {
			$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = array( 11 );
		};
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_state_uncertain', $result->get_error_code() );
	}

	/** Re-entrant Core hooks cannot change protected Post fields or target metadata. */
	public function test_invariant_guards_block_reentrant_post_and_meta_writes(): void {
		$GLOBALS['wp_auto_test_before_set_object_terms'] = static function (): void {
			wp_update_post(
				array(
					'ID'          => 100,
					'post_title'  => 'tampered',
					'post_status' => 'publish',
				)
			);
			update_post_meta( 100, '_unrelated', 'tampered' );
		};
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );

		self::assertIsArray( $result );
		self::assertSame( 'draft', $result['status'] );
		self::assertSame( 'keep', get_post_meta( 100, '_unrelated', true ) );
		self::assertSame( '', get_post( 100 )->post_title );
	}

	/** A post-write relationship divergence fails closed. */
	public function test_final_relationship_divergence_is_uncertain(): void {
		$GLOBALS['wp_auto_test_after_set_object_terms'] = static function (): void {
			$GLOBALS['wp_auto_test_object_term_ids'][100]['category'] = array( 10 );
		};
		$result = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_state_uncertain', $result->get_error_code() );
	}

	/** Audit failure after a proven relationship write is uncertain, never silently successful. */
	public function test_audit_failure_is_uncertain(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$result                                   = ( new TaxonomyAssignmentService() )->assign( $this->request( array( 11 ), array() ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_state_uncertain', $result->get_error_code() );
		self::assertSame( array( 11 ), $GLOBALS['wp_auto_test_object_term_ids'][100]['category'] );
	}

	/**
	 * Build one exact assignment request.
	 *
	 * @param array<int,int> $term_ids Desired final term IDs.
	 * @param array<int,int> $expected Expected current term IDs.
	 */
	private function request( array $term_ids, array $expected ): array {
		return array(
			'target_id'         => 100,
			'taxonomy'          => 'category',
			'term_ids'          => $term_ids,
			'expected_term_ids' => $expected,
		);
	}
}
