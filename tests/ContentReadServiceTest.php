<?php
/**
 * Content read service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Content\ContentReadService;

/**
 * Covers safe queries, visibility, pagination, and existence hiding.
 */
final class ContentReadServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var ContentReadService
	 */
	private ContentReadService $service;

	/**
	 * Reset the fake Core state for each behavior test.
	 */
	protected function setUp(): void {
		$this->service                           = new ContentReadService();
		$GLOBALS['wp_auto_test_current_user_id'] = 10;
		$GLOBALS['wp_auto_test_capabilities']    = array( 'read' => true );
		$GLOBALS['wp_auto_test_posts']           = array();
		$GLOBALS['wp_auto_test_terms']           = array();
		$GLOBALS['wp_auto_test_thumbnail_ids']   = array();
		$GLOBALS['wp_auto_test_last_query_args'] = array();
	}

	/**
	 * Verify PHP defaults and the bounded fixed query construction.
	 */
	public function test_search_applies_server_defaults_and_safe_fixed_query(): void {
		$GLOBALS['wp_auto_test_posts'] = array( $this->post( 1, 'publish', 20 ) );

		$result = $this->service->search_posts( array() );
		$args   = $GLOBALS['wp_auto_test_last_query_args'];

		self::assertIsArray( $result );
		self::assertSame( 1, $result['page'] );
		self::assertSame( 10, $result['per_page'] );
		self::assertSame( 1, $result['returned'] );
		self::assertFalse( $result['has_more'] );
		self::assertSame( 'post', $args['post_type'] );
		self::assertSame( 'publish', $args['post_status'] );
		self::assertSame( '', $args['s'] );
		self::assertSame( 11, $args['posts_per_page'] );
		self::assertNotSame( -1, $args['posts_per_page'] );
		self::assertSame( 0, $args['offset'] );
		self::assertSame( 'modified', $args['orderby'] );
		self::assertSame( 'DESC', $args['order'] );
		self::assertSame( 'readable', $args['perm'] );
		self::assertTrue( $args['no_found_rows'] );
		self::assertArrayNotHasKey( 'meta_query', $args );
		self::assertArrayNotHasKey( 'tax_query', $args );
	}

	/**
	 * Verify all frozen enum values map only to approved query values.
	 */
	public function test_search_accepts_frozen_status_orderby_and_order_values(): void {
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $status ) {
			$result = $this->service->search_posts( array( 'status' => $status ) );
			self::assertIsArray( $result );
		}

		foreach ( array(
			'date'     => 'date',
			'modified' => 'modified',
			'title'    => 'title',
			'id'       => 'ID',
		) as $input => $mapped ) {
			$result = $this->service->search_posts( array( 'orderby' => $input ) );
			self::assertIsArray( $result );
			self::assertSame( $mapped, $GLOBALS['wp_auto_test_last_query_args']['orderby'] );
		}

		foreach ( array(
			'asc'  => 'ASC',
			'desc' => 'DESC',
		) as $input => $mapped ) {
			$result = $this->service->search_posts( array( 'order' => $input ) );
			self::assertIsArray( $result );
			self::assertSame( $mapped, $GLOBALS['wp_auto_test_last_query_args']['order'] );
		}
	}

	/**
	 * Verify direct service use cannot bypass schema constraints.
	 *
	 * @dataProvider invalidSearchInputProvider
	 * @param mixed $input Invalid search input.
	 */
	public function test_search_rejects_invalid_direct_input( $input ): void {
		$result = $this->service->search_posts( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Invalid direct service inputs.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalidSearchInputProvider(): array {
		return array(
			'non-object'         => array( 'invalid' ),
			'extra property'     => array( array( 'post_type' => 'page' ) ),
			'long search'        => array( array( 'search' => str_repeat( 'a', 201 ) ) ),
			'invalid status'     => array( array( 'status' => 'any' ) ),
			'inherit status'     => array( array( 'status' => 'inherit' ) ),
			'trash status'       => array( array( 'status' => 'trash' ) ),
			'auto draft status'  => array( array( 'status' => 'auto-draft' ) ),
			'page zero'          => array( array( 'page' => 0 ) ),
			'page string'        => array( array( 'page' => '1' ) ),
			'per page zero'      => array( array( 'per_page' => 0 ) ),
			'per page 51'        => array( array( 'per_page' => 51 ) ),
			'unlimited'          => array( array( 'per_page' => -1 ) ),
			'invalid orderby'    => array( array( 'orderby' => 'rand' ) ),
			'invalid order'      => array( array( 'order' => 'sideways' ) ),
			'overflowing offset' => array(
				array(
					'page'     => PHP_INT_MAX,
					'per_page' => 50,
				),
			),
		);
	}

	/**
	 * Verify the maximum page size is accepted and queried as max plus one.
	 */
	public function test_search_accepts_per_page_fifty(): void {
		$result = $this->service->search_posts( array( 'per_page' => 50 ) );

		self::assertIsArray( $result );
		self::assertSame( 50, $result['per_page'] );
		self::assertSame( 51, $GLOBALS['wp_auto_test_last_query_args']['posts_per_page'] );
	}

	/**
	 * Verify lightweight fields, stored dates, and excluded sensitive fields.
	 */
	public function test_search_returns_only_the_frozen_lightweight_record(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 7, 'publish', 20, '2026-06-01 01:02:03', '2026-06-02 04:05:06' ),
		);

		$result = $this->service->search_posts();
		$item   = $result['items'][0];

		self::assertSame( array( 'id', 'title', 'slug', 'status', 'link', 'author_id', 'date_gmt', 'modified_gmt' ), array_keys( $item ) );
		self::assertSame( '2026-06-01 01:02:03', $item['date_gmt'] );
		self::assertSame( '2026-06-02 04:05:06', $item['modified_gmt'] );
		self::assertSame( count( $result['items'] ), $result['returned'] );
		self::assertArrayNotHasKey( 'content', $item );
		self::assertArrayNotHasKey( 'excerpt', $item );
		self::assertArrayNotHasKey( 'meta', $item );
		self::assertArrayNotHasKey( 'total', $result );
		self::assertArrayNotHasKey( 'total_pages', $result );
	}

	/**
	 * Verify read-only and author-like identities see only Core-readable objects.
	 */
	public function test_non_public_search_is_scoped_by_ownership_and_capabilities(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'draft', 10 ),
			$this->post( 2, 'draft', 20 ),
			$this->post( 3, 'pending', 10 ),
			$this->post( 4, 'pending', 20 ),
			$this->post( 5, 'future', 10 ),
			$this->post( 6, 'future', 20 ),
			$this->post( 7, 'private', 10 ),
			$this->post( 8, 'private', 20 ),
		);

		foreach ( array(
			'draft'   => 1,
			'pending' => 3,
			'future'  => 5,
			'private' => 7,
		) as $status => $expected_id ) {
			$result = $this->service->search_posts(
				array(
					'status'  => $status,
					'orderby' => 'id',
					'order'   => 'asc',
				)
			);
			self::assertSame( array( $expected_id ), array_column( $result['items'], 'id' ) );
			self::assertSame( 10, $GLOBALS['wp_auto_test_last_query_args']['author'] );
		}
	}

	/**
	 * Verify editor-like primitive capabilities reveal only their Core scope.
	 */
	public function test_non_public_search_allows_others_only_with_required_capabilities(): void {
		$GLOBALS['wp_auto_test_posts']        = array(
			$this->post( 1, 'draft', 20 ),
			$this->post( 2, 'pending', 20 ),
			$this->post( 3, 'future', 20 ),
			$this->post( 4, 'private', 20 ),
		);
		$GLOBALS['wp_auto_test_capabilities'] = array(
			'read'                 => true,
			'edit_others_posts'    => true,
			'edit_published_posts' => true,
			'read_private_posts'   => true,
		);

		foreach ( array(
			'draft'   => 1,
			'pending' => 2,
			'future'  => 3,
			'private' => 4,
		) as $status => $expected_id ) {
			$result = $this->service->search_posts( array( 'status' => $status ) );
			self::assertSame( array( $expected_id ), array_column( $result['items'], 'id' ) );
			self::assertArrayNotHasKey( 'author', $GLOBALS['wp_auto_test_last_query_args'] );
		}
	}

	/**
	 * Verify future visibility needs both Core primitive capabilities.
	 */
	public function test_future_search_requires_edit_published_capability_for_others(): void {
		$GLOBALS['wp_auto_test_posts']                             = array(
			$this->post( 1, 'future', 10 ),
			$this->post( 2, 'future', 20 ),
		);
		$GLOBALS['wp_auto_test_capabilities']['edit_others_posts'] = true;

		$result = $this->service->search_posts(
			array(
				'status'  => 'future',
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);

		self::assertSame( array( 1 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 10, $GLOBALS['wp_auto_test_last_query_args']['author'] );
	}

	/**
	 * Verify logical pages and has_more are based only on query-readable objects.
	 */
	public function test_permission_aware_pagination_ignores_interleaved_hidden_posts(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'draft', 10 ),
			$this->post( 2, 'draft', 20 ),
			$this->post( 3, 'draft', 20 ),
			$this->post( 4, 'draft', 10 ),
			$this->post( 5, 'draft', 20 ),
			$this->post( 6, 'draft', 10 ),
		);

		$first  = $this->service->search_posts(
			array(
				'status'   => 'draft',
				'page'     => 1,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$second = $this->service->search_posts(
			array(
				'status'   => 'draft',
				'page'     => 2,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$third  = $this->service->search_posts(
			array(
				'status'   => 'draft',
				'page'     => 3,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$beyond = $this->service->search_posts(
			array(
				'status'   => 'draft',
				'page'     => 4,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);

		self::assertSame( array( 1 ), array_column( $first['items'], 'id' ) );
		self::assertTrue( $first['has_more'] );
		self::assertSame( array( 4 ), array_column( $second['items'], 'id' ) );
		self::assertTrue( $second['has_more'] );
		self::assertSame( array( 6 ), array_column( $third['items'], 'id' ) );
		self::assertFalse( $third['has_more'] );
		self::assertSame( array(), $beyond['items'] );
		self::assertSame( 0, $beyond['returned'] );
		self::assertFalse( $beyond['has_more'] );
	}

	/**
	 * Verify post-get returns exact stored fields and Core-derived relationships.
	 */
	public function test_get_returns_exact_frozen_stored_post_contract(): void {
		$post                                     = $this->post( 9, 'publish', 20, '2026-07-01 01:02:03', '2026-07-02 04:05:06' );
		$GLOBALS['wp_auto_test_posts']            = array( $post );
		$GLOBALS['wp_auto_test_terms'][9]         = array(
			'category' => array( '3', 4 ),
			'post_tag' => array( 8 ),
		);
		$GLOBALS['wp_auto_test_thumbnail_ids'][9] = 99;

		$result = $this->service->get_post( array( 'id' => 9 ) );
		$search = $this->service->search_posts( array( 'search' => 'Post 9' ) );

		self::assertSame(
			array( 'id', 'type', 'status', 'slug', 'title', 'excerpt', 'content', 'link', 'author_id', 'date_gmt', 'modified_gmt', 'featured_media_id', 'categories', 'tags' ),
			array_keys( $result )
		);
		self::assertSame( 'post', $result['type'] );
		self::assertSame( 'Stored excerpt 9', $result['excerpt'] );
		self::assertSame( '<!-- wp:paragraph --><p>Stored 9</p><!-- /wp:paragraph -->', $result['content'] );
		self::assertSame( '2026-07-02 04:05:06', $result['modified_gmt'] );
		self::assertSame( $search['items'][0]['modified_gmt'], $result['modified_gmt'] );
		self::assertSame( 99, $result['featured_media_id'] );
		self::assertSame( array( 3, 4 ), $result['categories'] );
		self::assertSame( array( 8 ), $result['tags'] );
		self::assertArrayNotHasKey( 'meta', $result );
	}

	/**
	 * Verify taxonomy errors are hidden as deterministic empty lists.
	 */
	public function test_get_handles_term_lookup_errors_without_leaking_them(): void {
		$GLOBALS['wp_auto_test_posts']    = array( $this->post( 9, 'publish', 20 ) );
		$GLOBALS['wp_auto_test_terms'][9] = array(
			'category' => new WP_Error( 'db_error', 'Sensitive internal detail.' ),
			'post_tag' => new WP_Error( 'db_error', 'Sensitive internal detail.' ),
		);

		$result = $this->service->get_post( array( 'id' => 9 ) );

		self::assertSame( array(), $result['categories'] );
		self::assertSame( array(), $result['tags'] );
	}

	/**
	 * Verify published and own draft get behavior follows Core read_post.
	 */
	public function test_get_allows_published_and_own_draft_for_read_capable_identity(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'publish', 20 ),
			$this->post( 2, 'draft', 10 ),
		);

		self::assertIsArray( $this->service->get_post( array( 'id' => 1 ) ) );
		self::assertIsArray( $this->service->get_post( array( 'id' => 2 ) ) );
	}

	/**
	 * Verify missing, wrong-type, and inaccessible objects are indistinguishable.
	 */
	public function test_get_hides_existence_for_missing_wrong_type_and_unauthorized_objects(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 2, 'draft', 20 ),
			$this->post( 3, 'private', 20 ),
			$this->post( 4, 'publish', 20, null, null, 'page' ),
		);

		$errors = array(
			$this->service->get_post( array( 'id' => 999 ) ),
			$this->service->get_post( array( 'id' => 4 ) ),
			$this->service->get_post( array( 'id' => 2 ) ),
			$this->service->get_post( array( 'id' => 3 ) ),
		);

		foreach ( $errors as $error ) {
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
			self::assertSame( 'The requested content was not found.', $error->get_error_message() );
			self::assertSame( array( 'status' => 404 ), $error->get_error_data() );
		}
	}

	/**
	 * Verify malformed IDs never reach object lookup semantics.
	 *
	 * @dataProvider invalidGetInputProvider
	 * @param mixed $input Invalid get input.
	 */
	public function test_get_rejects_invalid_input( $input ): void {
		$result = $this->service->get_post( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
	}

	/**
	 * Invalid get inputs.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalidGetInputProvider(): array {
		return array(
			'missing id'       => array( array() ),
			'zero id'          => array( array( 'id' => 0 ) ),
			'string id'        => array( array( 'id' => '1' ) ),
			'extra property'   => array(
				array(
					'id'      => 1,
					'context' => 'edit',
				),
			),
			'non-object input' => array( 1 ),
		);
	}

	/**
	 * Build a deterministic Core-like post fixture.
	 *
	 * @param int         $id Post ID.
	 * @param string      $status Post status.
	 * @param int         $author Author user ID.
	 * @param string|null $date_gmt Stored GMT creation date.
	 * @param string|null $modified_gmt Stored GMT modification date.
	 * @param string      $post_type Post type.
	 */
	private function post(
		int $id,
		string $status,
		int $author,
		?string $date_gmt = null,
		?string $modified_gmt = null,
		string $post_type = 'post'
	): WP_Post {
		return new WP_Post(
			array(
				'ID'                => $id,
				'post_type'         => $post_type,
				'post_status'       => $status,
				'post_name'         => 'post-' . $id,
				'post_title'        => 'Post ' . $id,
				'post_excerpt'      => 'Stored excerpt ' . $id,
				'post_content'      => '<!-- wp:paragraph --><p>Stored ' . $id . '</p><!-- /wp:paragraph -->',
				'post_author'       => $author,
				'post_date_gmt'     => $date_gmt ?? sprintf( '2026-01-%02d 00:00:00', min( $id, 28 ) ),
				'post_modified_gmt' => $modified_gmt ?? sprintf( '2026-02-%02d 00:00:00', min( $id, 28 ) ),
			)
		);
	}
}
