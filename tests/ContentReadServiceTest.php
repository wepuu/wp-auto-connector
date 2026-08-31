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
		$this->service                               = new ContentReadService();
		$GLOBALS['wp_auto_test_current_user_id']     = 10;
		$GLOBALS['wp_auto_test_capabilities']        = array( 'read' => true );
		$GLOBALS['wp_auto_test_posts']               = array();
		$GLOBALS['wp_auto_test_terms']               = array();
		$GLOBALS['wp_auto_test_thumbnail_ids']       = array();
		$GLOBALS['wp_auto_test_last_query_args']     = array();
		$GLOBALS['wp_auto_test_query_args_history']  = array();
		$GLOBALS['wp_auto_test_object_capabilities'] = array();
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
		self::assertSame( 100, $args['posts_per_page'] );
		self::assertNotSame( -1, $args['posts_per_page'] );
		self::assertSame( 0, $args['offset'] );
		self::assertSame(
			array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			$args['orderby']
		);
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
			'date'     => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'modified' => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'title'    => array(
				'title' => 'DESC',
				'ID'    => 'DESC',
			),
			'id'       => array( 'ID' => 'DESC' ),
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
			'password property'  => array( array( 'password' => 'secret' ) ),
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
	 * Verify the maximum page size is accepted inside a bounded raw scan chunk.
	 */
	public function test_search_accepts_per_page_fifty(): void {
		$result = $this->service->search_posts( array( 'per_page' => 50 ) );

		self::assertIsArray( $result );
		self::assertSame( 50, $result['per_page'] );
		self::assertSame( 100, $GLOBALS['wp_auto_test_last_query_args']['posts_per_page'] );
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
	 * Verify logical offset is applied after final object authorization.
	 */
	public function test_permission_aware_pagination_starts_with_hidden_then_visible_posts(): void {
		$GLOBALS['wp_auto_test_posts']                               = array(
			$this->post( 1, 'publish', 20 ),
			$this->post( 2, 'publish', 20 ),
			$this->post( 3, 'publish', 20 ),
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][1] = false;

		$first  = $this->service->search_posts(
			array(
				'page'     => 1,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$second = $this->service->search_posts(
			array(
				'page'     => 2,
				'per_page' => 1,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		self::assertSame( array( 2 ), array_column( $first['items'], 'id' ) );
		self::assertTrue( $first['has_more'] );
		self::assertSame( array( 3 ), array_column( $second['items'], 'id' ) );
		self::assertFalse( $second['has_more'] );
	}

	/**
	 * Verify multiple interleaved final-authorization failures do not create gaps.
	 */
	public function test_permission_aware_pagination_ignores_multiple_interleaved_failures(): void {
		$GLOBALS['wp_auto_test_posts']                            = array_map(
			fn ( int $id ): WP_Post => $this->post( $id, 'publish', 20 ),
			range( 1, 7 )
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array(
			1 => false,
			3 => false,
			4 => false,
			6 => false,
		);

		foreach ( array( 2, 5, 7 ) as $page => $expected_id ) {
			$result = $this->service->search_posts(
				array(
					'page'     => $page + 1,
					'per_page' => 1,
					'orderby'  => 'id',
					'order'    => 'asc',
				)
			);

			self::assertSame( array( $expected_id ), array_column( $result['items'], 'id' ) );
		}
	}

	/**
	 * Verify deep pages and authorization-heavy scans fail with a stable bound.
	 */
	public function test_search_returns_stable_error_when_bounded_window_cannot_be_proven(): void {
		$deep = $this->service->search_posts(
			array(
				'page'     => 1000,
				'per_page' => 1,
			)
		);

		self::assertInstanceOf( WP_Error::class, $deep );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $deep->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_query_args_history'] );

		$GLOBALS['wp_auto_test_posts']                            = array_map(
			fn ( int $id ): WP_Post => $this->post( $id, 'publish', 20 ),
			range( 1, 1000 )
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array_fill_keys( range( 1, 1000 ), false );
		$bounded = $this->service->search_posts( array( 'per_page' => 1 ) );

		self::assertInstanceOf( WP_Error::class, $bounded );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $bounded->get_error_code() );
		self::assertSame( 10, count( $GLOBALS['wp_auto_test_query_args_history'] ) );
		self::assertSame( 900, $GLOBALS['wp_auto_test_last_query_args']['offset'] );
		self::assertSame( 100, $GLOBALS['wp_auto_test_last_query_args']['posts_per_page'] );
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
	 * Verify password-protected content requires read_post and edit_post.
	 */
	public function test_password_protected_post_requires_object_edit_permission(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'publish', 20, null, null, 'post', 'secret' ),
		);

		$rejected_get    = $this->service->get_post( array( 'id' => 1 ) );
		$rejected_search = $this->service->search_posts(
			array(
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);

		self::assertInstanceOf( WP_Error::class, $rejected_get );
		self::assertSame( 'wp_auto_content_not_found', $rejected_get->get_error_code() );
		self::assertSame( array(), $rejected_search['items'] );

		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][1] = true;
		$allowed_get    = $this->service->get_post( array( 'id' => 1 ) );
		$allowed_search = $this->service->search_posts(
			array(
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);

		self::assertIsArray( $allowed_get );
		self::assertSame( 1, $allowed_get['id'] );
		self::assertSame( array( 1 ), array_column( $allowed_search['items'], 'id' ) );
		self::assertArrayNotHasKey( 'password', $allowed_get );
		self::assertArrayNotHasKey( 'post_password', $allowed_get );
	}

	/**
	 * Verify search and get share identical final eligibility decisions.
	 */
	public function test_search_excludes_every_post_that_get_rejects(): void {
		$GLOBALS['wp_auto_test_posts']                               = array(
			$this->post( 1, 'publish', 20 ),
			$this->post( 2, 'publish', 20, null, null, 'post', 'secret' ),
			$this->post( 3, 'publish', 20 ),
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][3] = false;

		$result = $this->service->search_posts(
			array(
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);

		self::assertSame( array( 1 ), array_column( $result['items'], 'id' ) );
		foreach ( array( 2, 3 ) as $post_id ) {
			$error = $this->service->get_post( array( 'id' => $post_id ) );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
		}
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
			'password input'   => array(
				array(
					'id'       => 1,
					'password' => 'secret',
				),
			),
			'non-object input' => array( 1 ),
		);
	}

	/**
	 * Verify pages reuse strict search validation and a fixed page query.
	 *
	 * @dataProvider invalidSearchInputProvider
	 * @param mixed $input Invalid search input.
	 */
	public function test_page_search_rejects_invalid_input_and_uses_fixed_type( $input ): void {

		$result = $this->service->search_pages( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );

		$valid = $this->service->search_pages( array( 'per_page' => 50 ) );
		self::assertIsArray( $valid );
		self::assertSame( 'page', $GLOBALS['wp_auto_test_last_query_args']['post_type'] );
		self::assertSame( 50, $valid['per_page'] );
	}

	/**
	 * Verify Page search applies shared defaults and safe order mappings.
	 */
	public function test_page_search_accepts_defaults_and_frozen_ordering(): void {
		$GLOBALS['wp_auto_test_posts'] = array( $this->post( 1, 'publish', 20, null, null, 'page' ) );

		$defaults = $this->service->search_pages();
		self::assertSame( 1, $defaults['page'] );
		self::assertSame( 10, $defaults['per_page'] );
		self::assertSame( 'publish', $GLOBALS['wp_auto_test_last_query_args']['post_status'] );
		self::assertSame( '', $GLOBALS['wp_auto_test_last_query_args']['s'] );
		self::assertSame(
			array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			$GLOBALS['wp_auto_test_last_query_args']['orderby']
		);

		foreach ( array( 'date', 'modified', 'title', 'id' ) as $orderby ) {
			$result = $this->service->search_pages( array( 'orderby' => $orderby ) );
			self::assertIsArray( $result );
		}

		foreach ( array(
			'asc'  => 'ASC',
			'desc' => 'DESC',
		) as $order => $mapped ) {
			$result = $this->service->search_pages( array( 'order' => $order ) );
			self::assertIsArray( $result );
			self::assertSame( $mapped, $GLOBALS['wp_auto_test_last_query_args']['order'] );
		}
	}

	/**
	 * Verify page searches use Page capability primitives for non-public scope.
	 */
	public function test_page_search_uses_page_capability_object(): void {

		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'draft', 10, null, null, 'page' ),
			$this->post( 2, 'draft', 20, null, null, 'page' ),
			$this->post( 3, 'pending', 20, null, null, 'page' ),
			$this->post( 4, 'future', 20, null, null, 'page' ),
			$this->post( 5, 'private', 20, null, null, 'page' ),
			$this->post( 6, 'pending', 10, null, null, 'page' ),
			$this->post( 7, 'future', 10, null, null, 'page' ),
			$this->post( 8, 'private', 10, null, null, 'page' ),
		);

		foreach ( array(
			'draft'   => 1,
			'pending' => 6,
			'future'  => 7,
			'private' => 8,
		) as $status => $expected_id ) {
			$own = $this->service->search_pages(
				array(
					'status'  => $status,
					'orderby' => 'id',
					'order'   => 'asc',
				)
			);
			self::assertSame( array( $expected_id ), array_column( $own['items'], 'id' ) );
			self::assertSame( 10, $GLOBALS['wp_auto_test_last_query_args']['author'] );
		}

		$GLOBALS['wp_auto_test_capabilities'] += array(
			'edit_others_pages'    => true,
			'edit_published_pages' => true,
			'read_private_pages'   => true,
		);

		foreach ( array(
			'draft'   => array( 1, 2 ),
			'pending' => array( 3, 6 ),
			'future'  => array( 4, 7 ),
			'private' => array( 5, 8 ),
		) as $status => $expected_ids ) {
			$result = $this->service->search_pages(
				array(
					'status'  => $status,
					'orderby' => 'id',
					'order'   => 'asc',
				)
			);
			self::assertSame( $expected_ids, array_column( $result['items'], 'id' ) );
			self::assertArrayNotHasKey( 'author', $GLOBALS['wp_auto_test_last_query_args'] );
		}
	}

	/**
	 * Verify page output is exact, stored, hierarchical, and taxonomy-free.
	 */
	public function test_page_get_returns_exact_root_and_child_contracts(): void {

		$GLOBALS['wp_auto_test_posts']             = array(
			$this->post( 20, 'publish', 10, null, null, 'page' ),
			$this->post( 21, 'publish', 10, null, null, 'page', '', 20 ),
		);
		$GLOBALS['wp_auto_test_thumbnail_ids'][21] = 91;

		$root  = $this->service->get_page( array( 'id' => 20 ) );
		$child = $this->service->get_page( array( 'id' => 21 ) );

		self::assertSame(
			array( 'id', 'type', 'status', 'slug', 'title', 'excerpt', 'content', 'link', 'author_id', 'date_gmt', 'modified_gmt', 'featured_media_id', 'parent_id' ),
			array_keys( $child )
		);
		self::assertSame( 'page', $child['type'] );
		self::assertSame( '<!-- wp:paragraph --><p>Stored 21</p><!-- /wp:paragraph -->', $child['content'] );
		self::assertSame( 0, $root['parent_id'] );
		self::assertSame( 20, $child['parent_id'] );
		self::assertSame( 91, $child['featured_media_id'] );
		self::assertArrayNotHasKey( 'categories', $child );
		self::assertArrayNotHasKey( 'tags', $child );
		self::assertArrayNotHasKey( 'meta', $child );
	}

	/**
	 * Verify page search and get share final authorization and password policy.
	 */
	public function test_page_search_and_get_share_final_eligibility(): void {

		$GLOBALS['wp_auto_test_posts']                               = array(
			$this->post( 1, 'publish', 20, null, null, 'page' ),
			$this->post( 2, 'publish', 20, null, null, 'page', 'secret' ),
			$this->post( 3, 'publish', 20, null, null, 'page' ),
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][3] = false;

		$search = $this->service->search_pages(
			array(
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);
		self::assertSame( array( 1 ), array_column( $search['items'], 'id' ) );

		foreach ( array( 2, 3 ) as $page_id ) {
			$error = $this->service->get_page( array( 'id' => $page_id ) );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
		}

		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][2] = true;
		self::assertIsArray( $this->service->get_page( array( 'id' => 2 ) ) );
	}

	/**
	 * Verify Page and Post get hide wrong types in both directions.
	 */
	public function test_get_hides_cross_type_and_missing_objects(): void {

		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 1, 'publish', 10 ),
			$this->post( 2, 'publish', 10, null, null, 'page' ),
		);

		foreach ( array(
			$this->service->get_page( array( 'id' => 1 ) ),
			$this->service->get_post( array( 'id' => 2 ) ),
			$this->service->get_page( array( 'id' => 999 ) ),
		) as $error ) {
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
		}
	}

	/**
	 * Verify Page logical pagination ignores final-authorization failures.
	 */
	public function test_page_pagination_applies_offset_after_final_authorization(): void {

		$GLOBALS['wp_auto_test_posts']                            = array_map(
			fn ( int $id ): WP_Post => $this->post( $id, 'publish', 20, null, null, 'page' ),
			range( 1, 7 )
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array(
			1 => false,
			3 => false,
			4 => false,
			6 => false,
		);

		foreach ( array( 2, 5, 7 ) as $index => $expected_id ) {
			$result = $this->service->search_pages(
				array(
					'page'     => $index + 1,
					'per_page' => 1,
					'orderby'  => 'id',
					'order'    => 'asc',
				)
			);
			self::assertSame( array( $expected_id ), array_column( $result['items'], 'id' ) );
			self::assertSame( $index < 2, $result['has_more'] );
		}
	}

	/**
	 * Verify Pages share the bounded 100/1000 scanner and stable error.
	 */
	public function test_page_search_uses_shared_bounded_window(): void {

		$deep = $this->service->search_pages(
			array(
				'page'     => 1000,
				'per_page' => 1,
			)
		);
		self::assertInstanceOf( WP_Error::class, $deep );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $deep->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_query_args_history'] );

		$GLOBALS['wp_auto_test_posts']                            = array_map(
			fn ( int $id ): WP_Post => $this->post( $id, 'publish', 20, null, null, 'page' ),
			range( 1, 1000 )
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array_fill_keys( range( 1, 1000 ), false );
		$bounded = $this->service->search_pages( array( 'per_page' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $bounded );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $bounded->get_error_code() );
		self::assertSame( 10, count( $GLOBALS['wp_auto_test_query_args_history'] ) );
		self::assertSame( 900, $GLOBALS['wp_auto_test_last_query_args']['offset'] );
	}

	/**
	 * Verify Page Get uses the shared strict ID validation.
	 *
	 * @dataProvider invalidGetInputProvider
	 * @param mixed $input Invalid get input.
	 */
	public function test_page_get_rejects_invalid_input( $input ): void {

		$result = $this->service->get_page( $input );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
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
	 * @param string      $password Stored post password.
	 * @param int         $parent_id Parent post ID.
	 */
	private function post(
		int $id,
		string $status,
		int $author,
		?string $date_gmt = null,
		?string $modified_gmt = null,
		string $post_type = 'post',
		string $password = '',
		int $parent_id = 0
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
				'post_password'     => $password,
				'post_author'       => $author,
				'post_parent'       => $parent_id,
				'post_date_gmt'     => $date_gmt ?? sprintf( '2026-01-%02d 00:00:00', min( $id, 28 ) ),
				'post_modified_gmt' => $modified_gmt ?? sprintf( '2026-02-%02d 00:00:00', min( $id, 28 ) ),
			)
		);
	}
}
