<?php
/**
 * Taxonomy mutation service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TaxonomyMutationAuditStore;
use WPAuto\Connector\Taxonomy\TaxonomyMutationService;

/** Covers Category Create authorization, idempotency, and post-write proof. */
final class TaxonomyMutationServiceTest extends TestCase {
	/** Reset the isolated taxonomy, option, and capability fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']                 = 7;
		$GLOBALS['wp_auto_test_capabilities']                    = array( 'manage_categories' => true );
		$GLOBALS['wp_auto_test_taxonomy_terms']                  = array();
		$GLOBALS['wp_auto_test_next_term_id']                    = 3000;
		$GLOBALS['wp_auto_test_insert_term_calls']               = 0;
		$GLOBALS['wp_auto_test_insert_term_result']              = null;
		$GLOBALS['wp_auto_test_insert_term_exception']           = null;
		$GLOBALS['wp_auto_test_last_insert_term_args']           = array();
		$GLOBALS['wp_auto_test_term_meta']                       = array();
		$GLOBALS['wp_auto_test_term_meta_values']                = array();
		$GLOBALS['wp_auto_test_fail_update_term_meta']           = false;
		$GLOBALS['wp_auto_test_options']                         = array();
		$GLOBALS['wp_auto_test_option_autoload']                 = array();
		$GLOBALS['wp_auto_test_option_cache']                    = array();
		$GLOBALS['wp_auto_test_notoptions_cache']                = null;
		$GLOBALS['wp_auto_test_alloptions_cache']                = null;
		$GLOBALS['wp_auto_test_use_option_cache']                = false;
		$GLOBALS['wp_auto_test_cache_delete_exception']          = null;
		$GLOBALS['wp_auto_test_db_query_calls']                  = 0;
		$GLOBALS['wp_auto_test_db_query_exception']              = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception']  = null;
		$GLOBALS['wp_auto_test_db_last_error']                   = '';
		$GLOBALS['wp_auto_test_db_return_override']              = null;
		$GLOBALS['wp_auto_test_db_suppress_state']               = false;
		$GLOBALS['wp_auto_test_db_prepared_queries']             = array();
		$GLOBALS['wp_auto_test_delete_option_calls']             = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']              = false;
		$GLOBALS['wp_auto_test_delete_option_exception']         = null;
		$GLOBALS['wp_auto_test_update_option_calls']             = 0;
		$GLOBALS['wp_auto_test_fail_update_option']              = false;
		$GLOBALS['wp_auto_test_update_option_exception_on_call'] = null;
		$GLOBALS['wp_auto_test_uuid_counter']                    = 0;
		$GLOBALS['wp_auto_test_current_blog_id']                 = 1;
		$GLOBALS['wp_auto_test_before_current_user_id']          = null;
		$GLOBALS['wp_auto_test_before_current_user_can']         = null;
		$GLOBALS['wp_auto_test_current_user_can_exception']      = null;
		$GLOBALS['wp_auto_test_taxonomies']['category']          = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		$GLOBALS['wp_auto_test_taxonomies']['post_tag']          = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
	}

	/** Creates one category with only the allowlisted Core fields. */
	public function test_creates_category_and_records_private_state(): void {
		$input = array(
			'name'            => 'News',
			'idempotency_key' => 'category-key-1',
			'slug'            => 'news',
			'description'     => 'Editorial news.',
		);

		$result = ( new TaxonomyMutationService() )->create_category( $input );

		self::assertIsArray( $result );
		self::assertSame( 3001, $result['id'] );
		self::assertSame( 'News', $result['name'] );
		self::assertSame( 'news', $result['slug'] );
		self::assertSame( 'Editorial news.', $result['description'] );
		self::assertSame( 0, $result['parent_id'] );
		self::assertFalse( $result['idempotency_replayed'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_insert_term_calls'] );
		self::assertSame(
			array(
				'description' => 'Editorial news.',
				'parent'      => 0,
				'slug'        => 'news',
			),
			$GLOBALS['wp_auto_test_last_insert_term_args']['args']
		);

		$options             = $GLOBALS['wp_auto_test_options'];
		$idempotency_options = array_filter( array_keys( $options ), static fn( string $name ): bool => str_starts_with( $name, 'wp_auto_connector_taxonomy_idempotency_' ) );
		self::assertCount( 1, $idempotency_options );
		$claim = $options[ array_values( $idempotency_options )[0] ];
		self::assertSame( 'completed', $claim['state'] );
		self::assertSame( 3001, $claim['target_id'] );
		self::assertSame( array( array( 'version', 'operation', 'ability', 'actor_user_id', 'target_term_id', 'taxonomy', 'timestamp_gmt', 'fingerprint', 'parent_id' ) ), array( array_keys( $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ][0] ) ) );
	}

	/** Creates one tag with only the non-hierarchical allowlisted Core fields. */
	public function test_creates_tag_without_parent_and_records_tag_audit(): void {
		$input = array(
			'name'            => 'Featured',
			'idempotency_key' => 'tag-key-1',
			'slug'            => 'featured',
			'description'     => 'Featured content.',
		);

		$result = ( new TaxonomyMutationService() )->create_tag( $input );

		self::assertIsArray( $result );
		self::assertSame( array( 'id', 'name', 'slug', 'description', 'count', 'idempotency_replayed' ), array_keys( $result ) );
		self::assertSame( 3001, $result['id'] );
		self::assertSame( 'Featured', $result['name'] );
		self::assertSame( 'featured', $result['slug'] );
		self::assertSame( 'Featured content.', $result['description'] );
		self::assertFalse( $result['idempotency_replayed'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_insert_term_calls'] );
		self::assertSame(
			array(
				'description' => 'Featured content.',
				'slug'        => 'featured',
			),
			$GLOBALS['wp_auto_test_last_insert_term_args']['args']
		);
		self::assertSame( 'post_tag', $GLOBALS['wp_auto_test_last_insert_term_args']['taxonomy'] );
		self::assertSame( array( 'version', 'operation', 'ability', 'actor_user_id', 'target_term_id', 'taxonomy', 'timestamp_gmt', 'fingerprint' ), array_keys( $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ][0] ) );
		self::assertSame( 'wp-auto/tag-create', $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ][0]['ability'] );
		self::assertArrayNotHasKey( 'parent_id', $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ][0] );
	}

	/** Tag replay is independent from Category scopes and never creates a duplicate. */
	public function test_tag_replay_is_idempotent_and_taxonomy_scoped(): void {
		$service = new TaxonomyMutationService();
		$tag     = array(
			'name'            => 'News',
			'idempotency_key' => 'shared-key-1',
		);

		$first_tag  = $service->create_tag( $tag );
		$second_tag = $service->create_tag( $tag );
		$category   = $service->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'shared-key-1',
			)
		);

		self::assertIsArray( $first_tag );
		self::assertIsArray( $second_tag );
		self::assertIsArray( $category );
		self::assertSame( $first_tag['id'], $second_tag['id'] );
		self::assertFalse( $first_tag['idempotency_replayed'] );
		self::assertTrue( $second_tag['idempotency_replayed'] );
		self::assertNotSame( $first_tag['id'], $category['id'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_insert_term_calls'] );
	}

	/** Tags reject hierarchical controls and use their taxonomy's actual capability. */
	public function test_tag_rejects_parent_and_uses_actual_manage_terms_capability(): void {
		$service = new TaxonomyMutationService();
		$invalid = $service->create_tag(
			array(
				'name'            => 'Featured',
				'idempotency_key' => 'tag-key-2',
				'parent_id'       => 1,
			)
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'wp_auto_invalid_request', $invalid->get_error_code() );

		$GLOBALS['wp_auto_test_capabilities']['manage_categories']         = false;
		$GLOBALS['wp_auto_test_taxonomies']['post_tag']->cap->manage_terms = 'manage_custom_tags';
		self::assertInstanceOf(
			\WP_Error::class,
			$service->create_tag(
				array(
					'name'            => 'Featured',
					'idempotency_key' => 'tag-key-3',
				)
			)
		);
		$GLOBALS['wp_auto_test_capabilities']['manage_custom_tags'] = true;
		self::assertIsArray(
			$service->create_tag(
				array(
					'name'            => 'Featured',
					'idempotency_key' => 'tag-key-4',
				)
			)
		);
	}

	/** Replaying an exact key returns the original term without another Core create. */
	public function test_exact_replay_is_idempotent_and_does_not_duplicate_terms(): void {
		$service = new TaxonomyMutationService();
		$input   = array(
			'name'            => 'News',
			'idempotency_key' => 'category-key-2',
		);

		$first  = $service->create_category( $input );
		$second = $service->create_category( $input );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['id'], $second['id'] );
		self::assertFalse( $first['idempotency_replayed'] );
		self::assertTrue( $second['idempotency_replayed'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_insert_term_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_term_meta'][3001][ TaxonomyMutationAuditStore::meta_key() ] );
	}

	/** Existing Core terms are explicit conflicts and release the claim. */
	public function test_existing_term_is_a_conflict_and_claim_is_released(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'][] = new \WP_Term(
			array(
				'term_id'  => 3020,
				'name'     => 'News',
				'slug'     => 'news',
				'taxonomy' => 'category',
			)
		);

		$result = ( new TaxonomyMutationService() )->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'category-key-3',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_term_conflict', $result->get_error_code() );
		self::assertSame( 0, count( array_filter( array_keys( $GLOBALS['wp_auto_test_options'] ), static fn( string $name ): bool => str_starts_with( $name, 'wp_auto_connector_taxonomy_idempotency_' ) ) ) );
		self::assertSame( 1, $GLOBALS['wp_auto_test_insert_term_calls'] );
	}

	/** Invalid input and missing parents fail before mutation. */
	public function test_invalid_input_and_parent_are_rejected(): void {
		$service = new TaxonomyMutationService();
		$invalid = $service->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'short',
			)
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'wp_auto_invalid_request', $invalid->get_error_code() );

		$missing_parent = $service->create_category(
			array(
				'name'            => 'Child',
				'idempotency_key' => 'category-key-4',
				'parent_id'       => 9999,
			)
		);
		self::assertInstanceOf( \WP_Error::class, $missing_parent );
		self::assertSame( 'wp_auto_term_not_found', $missing_parent->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_term_calls'] );
	}

	/** Reusing a key for a different payload cannot adopt the first term. */
	public function test_fingerprint_conflict_is_rejected(): void {
		$service = new TaxonomyMutationService();
		$service->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'category-key-5',
			)
		);

		$result = $service->create_category(
			array(
				'name'            => 'Sports',
				'idempotency_key' => 'category-key-5',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_idempotency_conflict', $result->get_error_code() );
		self::assertSame( 1, $GLOBALS['wp_auto_test_insert_term_calls'] );
	}

	/** Capability denial happens before claim creation and parent probing. */
	public function test_management_capability_is_required(): void {
		$GLOBALS['wp_auto_test_capabilities']['manage_categories'] = false;

		$result = ( new TaxonomyMutationService() )->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'category-key-6',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_create_failed', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** An in-progress claim is never treated as a safe replay. */
	public function test_in_progress_claim_returns_conflict_without_core_create(): void {
		$store = new \WPAuto\Connector\Taxonomy\TaxonomyCreateIdempotencyStore();
		$store->claim( 'wp-auto/category-create', 7, 'category-key-7', hash( 'sha256', '["News","","",0]' ) );

		$result = ( new TaxonomyMutationService() )->create_category(
			array(
				'name'            => 'News',
				'idempotency_key' => 'category-key-7',
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wp_auto_idempotency_in_progress', $result->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_insert_term_calls'] );
	}
}
