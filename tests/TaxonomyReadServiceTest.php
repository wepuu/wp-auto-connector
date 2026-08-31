<?php
/**
 * Taxonomy read service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Term;
use WPAuto\Connector\Taxonomy\TaxonomyReadService;

/**
 * Covers strict inputs, bounded term queries, outputs, and stable pagination.
 */
final class TaxonomyReadServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var TaxonomyReadService
	 */
	private TaxonomyReadService $service;

	/**
	 * Reset isolated WordPress term-query state.
	 */
	protected function setUp(): void {
		$this->service                                = new TaxonomyReadService();
		$GLOBALS['wp_auto_test_filters']              = array();
		$GLOBALS['wp_auto_test_taxonomy_terms']       = array();
		$GLOBALS['wp_auto_test_get_terms_error']      = null;
		$GLOBALS['wp_auto_test_last_term_query_args'] = array();
		$GLOBALS['wp_auto_test_term_query_history']   = array();
		$GLOBALS['wp_auto_test_last_term_clauses']    = array();
	}

	/**
	 * Verify defaults become one bounded non-hierarchical category query.
	 */
	public function test_category_defaults_use_one_fixed_bounded_query(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'] = array(
			$this->term( 1, 'Category A', 'category-a', 'Stored <b>A</b>', 0, 0, 'category' ),
			$this->term( 2, 'Category B', 'category-b', 'Stored B', 3, 1, 'category' ),
			$this->term( 9, 'Tag A', 'tag-a', 'Tag', 1, 0, 'post_tag' ),
		);

		$result = $this->service->list_categories();
		$args   = $GLOBALS['wp_auto_test_last_term_query_args'];

		self::assertSame( 'category', $args['taxonomy'] );
		self::assertSame( '', $args['search'] );
		self::assertSame( 21, $args['number'] );
		self::assertSame( 0, $args['offset'] );
		self::assertSame( 'name', $args['orderby'] );
		self::assertSame( 'ASC', $args['order'] );
		self::assertFalse( $args['hide_empty'] );
		self::assertFalse( $args['hierarchical'] );
		self::assertFalse( $args['pad_counts'] );
		self::assertSame( 'all', $args['fields'] );
		self::assertFalse( $args['update_term_meta_cache'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_term_query_history'] );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), array_keys( $result ) );
		self::assertSame( 1, $result['page'] );
		self::assertSame( 20, $result['per_page'] );
		self::assertSame( 2, $result['returned'] );
		self::assertFalse( $result['has_more'] );
		self::assertSame(
			array( 'id', 'name', 'slug', 'description', 'count', 'parent_id' ),
			array_keys( $result['items'][0] )
		);
		self::assertSame( 'Stored <b>A</b>', $result['items'][0]['description'] );
		self::assertSame( 1, $result['items'][1]['parent_id'] );
		self::assertArrayNotHasKey( 'taxonomy', $result['items'][0] );
		self::assertArrayNotHasKey( 'meta', $result['items'][0] );
		self::assertArrayNotHasKey( 'total', $result );
	}

	/**
	 * Verify Tags fix post_tag and never expose a hierarchy field.
	 */
	public function test_tags_use_fixed_taxonomy_and_exact_flat_output(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'] = array(
			$this->term( 3, 'Tag', 'tag', 'Stored tag', 4, 99, 'post_tag' ),
			$this->term( 4, 'Category', 'category', 'Category', 2, 0, 'category' ),
		);

		$result = $this->service->list_tags();

		self::assertSame( 'post_tag', $GLOBALS['wp_auto_test_last_term_query_args']['taxonomy'] );
		self::assertSame( array( 'id', 'name', 'slug', 'description', 'count' ), array_keys( $result['items'][0] ) );
		self::assertArrayNotHasKey( 'parent_id', $result['items'][0] );
		self::assertSame( 3, $result['items'][0]['id'] );
	}

	/**
	 * Verify all public order values map to safe Core fields and directions.
	 */
	public function test_accepts_frozen_ordering_and_page_size_boundaries(): void {
		foreach ( array(
			'name'  => 'name',
			'count' => 'count',
			'id'    => 'term_id',
			'slug'  => 'slug',
		) as $public_order => $core_order ) {
			$result = $this->service->list_categories( array( 'orderby' => $public_order ) );
			self::assertIsArray( $result );
			self::assertSame( $core_order, $GLOBALS['wp_auto_test_last_term_query_args']['orderby'] );
		}

		foreach ( array(
			'asc'  => 'ASC',
			'desc' => 'DESC',
		) as $public_order => $core_order ) {
			$result = $this->service->list_tags( array( 'order' => $public_order ) );
			self::assertIsArray( $result );
			self::assertSame( $core_order, $GLOBALS['wp_auto_test_last_term_query_args']['order'] );
		}

		foreach ( array( 1, 50 ) as $per_page ) {
			$result = $this->service->list_categories( array( 'per_page' => $per_page ) );
			self::assertSame( $per_page, $result['per_page'] );
			self::assertSame( $per_page + 1, $GLOBALS['wp_auto_test_last_term_query_args']['number'] );
		}
	}

	/**
	 * Verify logical paging uses one extra row and no totals.
	 */
	public function test_pagination_uses_one_extra_term_for_has_more(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'] = array(
			$this->term( 1, 'A', 'a' ),
			$this->term( 2, 'B', 'b' ),
			$this->term( 3, 'C', 'c' ),
		);

		$first = $this->service->list_categories(
			array(
				'page'     => 1,
				'per_page' => 2,
				'orderby'  => 'id',
			)
		);
		$last  = $this->service->list_categories(
			array(
				'page'     => 2,
				'per_page' => 2,
				'orderby'  => 'id',
			)
		);

		self::assertSame( array( 1, 2 ), array_column( $first['items'], 'id' ) );
		self::assertTrue( $first['has_more'] );
		self::assertSame( array( 3 ), array_column( $last['items'], 'id' ) );
		self::assertFalse( $last['has_more'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_last_term_query_args']['offset'] );
		self::assertSame( 3, $GLOBALS['wp_auto_test_last_term_query_args']['number'] );
		self::assertArrayNotHasKey( 'total_pages', $first );
	}

	/**
	 * Verify a pathological Category offset is rejected before query setup.
	 */
	public function test_rejects_deep_category_page_before_query_or_filter(): void {
		$result = $this->service->list_categories(
			array(
				'page'     => 10000000,
				'per_page' => 50,
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $result->get_error_code() );
		self::assertSame( array( 'status' => 400 ), $result->get_error_data() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_term_query_history'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters'] );
	}

	/**
	 * Verify Tags share the same pre-query deep-page protection.
	 */
	public function test_rejects_deep_tag_page_before_query_or_filter(): void {
		$result = $this->service->list_tags(
			array(
				'page'     => 10000000,
				'per_page' => 50,
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $result->get_error_code() );
		self::assertSame( 'The requested page exceeds the supported search window.', $result->get_error_message() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_term_query_history'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters'] );
	}

	/**
	 * Verify the highest exact one-item query window remains supported.
	 */
	public function test_allows_maximum_supported_term_query_window(): void {
		$result = $this->service->list_categories(
			array(
				'page'     => 999,
				'per_page' => 1,
			)
		);

		self::assertIsArray( $result );
		self::assertCount( 1, $GLOBALS['wp_auto_test_term_query_history'] );
		self::assertSame( 998, $GLOBALS['wp_auto_test_last_term_query_args']['offset'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_last_term_query_args']['number'] );
		self::assertSame( 999, $result['page'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters']['terms_clauses'][10] );
	}

	/**
	 * Verify the first one-item page beyond the window is rejected.
	 */
	public function test_rejects_first_unsupported_term_query_window(): void {
		$result = $this->service->list_categories(
			array(
				'page'     => 1000,
				'per_page' => 1,
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $result->get_error_code() );
		self::assertSame( array( 'status' => 400 ), $result->get_error_data() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_term_query_history'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters'] );
	}

	/**
	 * Verify search and direct-count hide-empty semantics stay inside the fixed query.
	 */
	public function test_search_and_hide_empty_filter_direct_term_counts(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'] = array(
			$this->term( 1, 'News Parent', 'news-parent', '', 0 ),
			$this->term( 2, 'News Child', 'news-child', '', 2, 1 ),
			$this->term( 3, 'Other', 'other', '', 3 ),
		);

		$result = $this->service->list_categories(
			array(
				'search'     => 'News',
				'hide_empty' => true,
			)
		);

		self::assertSame( array( 2 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 'News', $GLOBALS['wp_auto_test_last_term_query_args']['search'] );
		self::assertTrue( $GLOBALS['wp_auto_test_last_term_query_args']['hide_empty'] );
		self::assertFalse( $GLOBALS['wp_auto_test_last_term_query_args']['hierarchical'] );
	}

	/**
	 * Verify equal primary values use the same-direction term ID tie-breaker.
	 */
	public function test_stable_tie_breaker_prevents_duplicate_or_skipped_pages(): void {
		$GLOBALS['wp_auto_test_taxonomy_terms'] = array(
			$this->term( 3, 'Same', 'same-c', '', 1 ),
			$this->term( 1, 'Same', 'same-a', '', 1 ),
			$this->term( 2, 'Same', 'same-b', '', 1 ),
		);

		$ids = array();
		foreach ( range( 1, 3 ) as $page ) {
			$result = $this->service->list_categories(
				array(
					'page'     => $page,
					'per_page' => 1,
					'orderby'  => 'name',
					'order'    => 'asc',
				)
			);
			$ids[]  = $result['items'][0]['id'];
		}

		self::assertSame( array( 1, 2, 3 ), $ids );
		self::assertSame( 'ORDER BY t.name ASC, t.term_id', $GLOBALS['wp_auto_test_last_term_clauses']['orderby'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters']['terms_clauses'][10] );

		$descending = $this->service->list_categories(
			array(
				'per_page' => 3,
				'orderby'  => 'count',
				'order'    => 'desc',
			)
		);
		self::assertSame( array( 3, 2, 1 ), array_column( $descending['items'], 'id' ) );
		self::assertSame( 'ORDER BY tt.count DESC, t.term_id', $GLOBALS['wp_auto_test_last_term_clauses']['orderby'] );
	}

	/**
	 * Verify the ID order does not install a redundant query filter.
	 */
	public function test_id_order_uses_core_term_id_without_filter(): void {
		$this->service->list_tags( array( 'orderby' => 'id' ) );

		self::assertArrayNotHasKey( 'wp_auto_connector_stable_terms_order', $GLOBALS['wp_auto_test_last_term_query_args'] );
		self::assertSame( 'ORDER BY t.term_id', $GLOBALS['wp_auto_test_last_term_clauses']['orderby'] );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters'] );
	}

	/**
	 * Verify Core query failures become one generic stable error and clean filters.
	 */
	public function test_query_errors_are_normalized_without_internal_details(): void {
		$GLOBALS['wp_auto_test_get_terms_error'] = new WP_Error( 'db_error', 'Sensitive database detail.' );

		$result = $this->service->list_categories();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_taxonomy_query_failed', $result->get_error_code() );
		self::assertSame( 'The taxonomy terms could not be retrieved.', $result->get_error_message() );
		self::assertSame( array( 'status' => 500 ), $result->get_error_data() );
		self::assertStringNotContainsString( 'Sensitive', $result->get_error_message() );
		self::assertEmpty( $GLOBALS['wp_auto_test_filters']['terms_clauses'][10] );
	}

	/**
	 * Verify malformed or injected inputs fail before Core querying.
	 *
	 * @dataProvider invalidInputProvider
	 * @param mixed $input Invalid list input.
	 */
	public function test_rejects_invalid_or_injected_input( $input ): void {
		$result = $this->service->list_categories( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_term_query_history'] );
	}

	/**
	 * Invalid taxonomy-list inputs.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalidInputProvider(): array {
		return array(
			'non-object input'   => array( 'terms' ),
			'search non-string'  => array( array( 'search' => 1 ) ),
			'search too long'    => array( array( 'search' => str_repeat( 'x', 201 ) ) ),
			'page zero'          => array( array( 'page' => 0 ) ),
			'page string'        => array( array( 'page' => '1' ) ),
			'per page zero'      => array( array( 'per_page' => 0 ) ),
			'per page too large' => array( array( 'per_page' => 51 ) ),
			'per page string'    => array( array( 'per_page' => '20' ) ),
			'bad orderby'        => array( array( 'orderby' => 'description' ) ),
			'bad order'          => array( array( 'order' => 'ASC' ) ),
			'hide empty integer' => array( array( 'hide_empty' => 1 ) ),
			'taxonomy override'  => array( array( 'taxonomy' => 'nav_menu' ) ),
			'number injection'   => array( array( 'number' => 0 ) ),
			'offset injection'   => array( array( 'offset' => 100 ) ),
			'include injection'  => array( array( 'include' => array( 1 ) ) ),
			'exclude injection'  => array( array( 'exclude' => array( 1 ) ) ),
			'object injection'   => array( array( 'object_ids' => array( 1 ) ) ),
			'meta injection'     => array( array( 'meta_query' => array() ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Verifies rejection of injected query arguments.
		);
	}

	/**
	 * Verify an offset that cannot be represented is rejected before querying.
	 */
	public function test_rejects_offset_overflow_before_query(): void {
		foreach ( array( 1, 2 ) as $per_page ) {
			$result = $this->service->list_tags(
				array(
					'page'     => PHP_INT_MAX,
					'per_page' => $per_page,
				)
			);

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
			self::assertSame( array(), $GLOBALS['wp_auto_test_term_query_history'] );
		}
	}

	/**
	 * Build a deterministic Core-like term fixture.
	 *
	 * @param int    $id Term ID.
	 * @param string $name Stored term name.
	 * @param string $slug Stored term slug.
	 * @param string $description Stored term description.
	 * @param int    $count Stored WordPress term count.
	 * @param int    $parent_id Parent term ID.
	 * @param string $taxonomy Term taxonomy.
	 */
	private function term(
		int $id,
		string $name,
		string $slug,
		string $description = '',
		int $count = 0,
		int $parent_id = 0,
		string $taxonomy = 'category'
	): WP_Term {
		return new WP_Term(
			array(
				'term_id'     => $id,
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'count'       => $count,
				'parent'      => $parent_id,
				'taxonomy'    => $taxonomy,
			)
		);
	}
}
