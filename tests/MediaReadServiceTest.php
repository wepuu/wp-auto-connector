<?php
/**
 * Media read service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Media\MediaReadContract;
use WPAuto\Connector\Media\MediaReadService;

/** Covers bounded queries, privacy, exact output, and stable errors. */
final class MediaReadServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var MediaReadService
	 */
	private MediaReadService $service;

	/** Reset media and WordPress fixture state. */
	protected function setUp(): void {
		$this->service                               = new MediaReadService();
		$GLOBALS['wp_auto_test_current_user_id']     = 10;
		$GLOBALS['wp_auto_test_capabilities']        = array(
			'read'         => true,
			'upload_files' => true,
		);
		$GLOBALS['wp_auto_test_posts']               = array();
		$GLOBALS['wp_auto_test_object_capabilities'] = array();
		$GLOBALS['wp_auto_test_query_args_history']  = array();
		$GLOBALS['wp_auto_test_last_query_args']     = array();
		$GLOBALS['wp_auto_test_attachment_urls']     = array();
		$GLOBALS['wp_auto_test_attached_files']      = array();
		$GLOBALS['wp_auto_test_attachment_metadata'] = array();
		$GLOBALS['wp_auto_test_post_meta']           = array();
		$GLOBALS['wp_auto_test_post_meta_values']    = array();
	}

	/** Verify the fixed bounded query and lightweight envelope. */
	public function test_search_uses_fixed_bounded_query_and_exact_output(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->image( 1, 'image/png' ),
			$this->image( 2, 'application/pdf' ),
			$this->image( 3, 'image/jpeg' ),
			$this->post( 4 ),
		);

		$result = $this->service->search();

		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), array_keys( $result ) );
		self::assertSame( array( 3, 1 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 2, $result['returned'] );
		self::assertFalse( $result['has_more'] );
		self::assertSame( 'attachment', $GLOBALS['wp_auto_test_last_query_args']['post_type'] );
		self::assertSame( 'inherit', $GLOBALS['wp_auto_test_last_query_args']['post_status'] );
		self::assertSame( MediaReadContract::MIME_TYPES, $GLOBALS['wp_auto_test_last_query_args']['post_mime_type'] );
		self::assertSame( 100, $GLOBALS['wp_auto_test_last_query_args']['posts_per_page'] );
		self::assertTrue( $GLOBALS['wp_auto_test_last_query_args']['no_found_rows'] );
		self::assertSame( array_keys( MediaReadContract::item_properties() ), array_keys( $result['items'][0] ) );
		self::assertStringNotContainsString( 'uploads', $result['items'][0]['filename'] );
	}

	/** Verify MIME filtering and deterministic ordering. */
	public function test_search_applies_mime_filter_defaults_and_stable_order(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->image( 2, 'image/png', 0, 'Same' ),
			$this->image( 1, 'image/jpeg', 0, 'Same' ),
			$this->image( 3, 'image/png', 0, 'Same' ),
		);

		$result = $this->service->search(
			array(
				'mime_type' => 'image/png',
				'orderby'   => 'title',
				'order'     => 'asc',
			)
		);

		self::assertSame( array( 2, 3 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 'image/png', $GLOBALS['wp_auto_test_last_query_args']['post_mime_type'] );
		self::assertSame(
			array(
				'title' => 'ASC',
				'ID'    => 'ASC',
			),
			$GLOBALS['wp_auto_test_last_query_args']['orderby']
		);
	}

	/** Verify Get returns only the exact full record. */
	public function test_get_returns_exact_full_record_and_fixed_alt_meta_only(): void {
		$attachment                    = $this->image( 7, 'image/webp' );
		$GLOBALS['wp_auto_test_posts'] = array( $attachment );
		$GLOBALS['wp_auto_test_post_meta'][7]['_wp_attachment_image_alt'] = 'Meaningful alt';

		$result = $this->service->get( array( 'id' => 7 ) );

		self::assertSame( array_keys( MediaReadContract::get_output_schema()['properties'] ), array_keys( $result ) );
		self::assertSame( 'image-7.webp', $result['filename'] );
		self::assertSame( 'Meaningful alt', $result['alt_text'] );
		self::assertSame( 'Caption 7', $result['caption'] );
		self::assertSame( 'Description 7', $result['description'] );
		self::assertSame( 1207, $result['width'] );
		self::assertSame( 807, $result['height'] );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'meta', $result );
	}

	/** Verify attachment and parent visibility are both required. */
	public function test_search_and_get_hide_inaccessible_attachment_and_parent(): void {
		$visible_parent                = $this->post( 90 );
		$hidden_parent                 = $this->post( 91 );
		$GLOBALS['wp_auto_test_posts'] = array(
			$visible_parent,
			$hidden_parent,
			$this->image( 1, 'image/png', 90 ),
			$this->image( 2, 'image/png', 91 ),
			$this->image( 3, 'image/png' ),
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array(
			2  => false,
			91 => false,
		);

		$search = $this->service->search(
			array(
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);
		self::assertSame( array( 1, 3 ), array_column( $search['items'], 'id' ) );

		foreach ( array( 2 ) as $id ) {
			$error = $this->service->get( array( 'id' => $id ) );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
		}
	}

	/** Verify password-protected parents require edit permission. */
	public function test_password_protected_parent_requires_edit_permission(): void {
		$GLOBALS['wp_auto_test_posts'] = array(
			$this->post( 90, 'secret' ),
			$this->image( 1, 'image/jpeg', 90 ),
		);

		$hidden = $this->service->get( array( 'id' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $hidden );

		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][90] = true;
		self::assertIsArray( $this->service->get( array( 'id' => 1 ) ) );
	}

	/** Verify a missing parent hides its attachment. */
	public function test_missing_parent_hides_attachment(): void {
		$GLOBALS['wp_auto_test_posts'] = array( $this->image( 1, 'image/jpeg', 999 ) );
		$error                         = $this->service->get( array( 'id' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
	}

	/** Verify all ineligible target classes share one public error. */
	public function test_get_hides_missing_wrong_type_unsupported_and_unauthorized(): void {
		$GLOBALS['wp_auto_test_posts']                               = array(
			$this->post( 1 ),
			$this->image( 2, 'image/svg+xml' ),
			$this->image( 3, 'image/png' ),
		);
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][3] = false;

		foreach ( array( 999, 1, 2, 3 ) as $id ) {
			$error = $this->service->get( array( 'id' => $id ) );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
			self::assertSame( array( 'status' => 404 ), $error->get_error_data() );
		}
	}

	/** Verify the service repeats the baseline capability check. */
	public function test_service_repeats_upload_files_permission(): void {
		$GLOBALS['wp_auto_test_posts']                        = array( $this->image( 1 ) );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = false;

		$get    = $this->service->get( array( 'id' => 1 ) );
		$search = $this->service->search();
		self::assertSame( 'wp_auto_media_not_found', $get->get_error_code() );
		self::assertSame( 'wp_auto_media_not_found', $search->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_query_args_history'] );
	}

	/**
	 * Verify Search rejects every non-contract input.
	 *
	 * @dataProvider invalidSearchInputProvider
	 * @param mixed $input Invalid input.
	 */
	public function test_search_rejects_invalid_input( $input ): void {
		$error = $this->service->search( $input );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
	}

	/**
	 * Provide invalid Search input.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalidSearchInputProvider(): array {
		return array(
			'not object'     => array( 'bad' ),
			'extra property' => array( array( 'status' => 'inherit' ) ),
			'long search'    => array( array( 'search' => str_repeat( 'x', 201 ) ) ),
			'bad mime'       => array( array( 'mime_type' => 'image/svg+xml' ) ),
			'zero page'      => array( array( 'page' => 0 ) ),
			'string page'    => array( array( 'page' => '1' ) ),
			'unlimited page' => array( array( 'per_page' => -1 ) ),
			'too large page' => array( array( 'per_page' => 51 ) ),
			'bad orderby'    => array( array( 'orderby' => 'rand' ) ),
			'bad order'      => array( array( 'order' => 'sideways' ) ),
		);
	}

	/**
	 * Verify Get rejects every non-contract input.
	 *
	 * @dataProvider invalidGetInputProvider
	 * @param mixed $input Invalid input.
	 */
	public function test_get_rejects_invalid_input( $input ): void {
		$error = $this->service->get( $input );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
	}

	/**
	 * Provide invalid Get input.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalidGetInputProvider(): array {
		return array(
			'missing' => array( array() ),
			'zero'    => array( array( 'id' => 0 ) ),
			'string'  => array( array( 'id' => '1' ) ),
			'extra'   => array(
				array(
					'id'      => 1,
					'context' => 'edit',
				),
			),
			'scalar'  => array( 1 ),
		);
	}

	/** Verify logical pagination ignores interleaved ineligible records. */
	public function test_logical_pagination_ignores_ineligible_records(): void {
		$GLOBALS['wp_auto_test_posts']                            = array_map( fn( int $id ): WP_Post => $this->image( $id ), range( 1, 7 ) );
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array(
			1 => false,
			3 => false,
			4 => false,
			6 => false,
		);

		foreach ( array( 2, 5, 7 ) as $index => $expected_id ) {
			$result = $this->service->search(
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

	/** Verify deep and authorization-heavy scans stop at 1,000 candidates. */
	public function test_search_enforces_the_fixed_thousand_candidate_window(): void {
		$deep = $this->service->search(
			array(
				'page'     => 1000,
				'per_page' => 1,
			)
		);
		self::assertSame( 'wp_auto_pagination_window_exceeded', $deep->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_query_args_history'] );

		$GLOBALS['wp_auto_test_posts']                            = array_map( fn( int $id ): WP_Post => $this->image( $id ), range( 1, 1000 ) );
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'] = array_fill_keys( range( 1, 1000 ), false );
		$bounded = $this->service->search( array( 'per_page' => 1 ) );
		self::assertSame( 'wp_auto_pagination_window_exceeded', $bounded->get_error_code() );
		self::assertCount( 10, $GLOBALS['wp_auto_test_query_args_history'] );
		self::assertSame( 900, $GLOBALS['wp_auto_test_last_query_args']['offset'] );
	}

	/** Verify corrupt records fail Get and cannot enter Search output. */
	public function test_corrupt_records_fail_get_and_are_skipped_by_search(): void {
		$GLOBALS['wp_auto_test_posts']                  = array( $this->image( 1 ), $this->image( 2 ) );
		$GLOBALS['wp_auto_test_attachment_urls'][1]     = false;
		$GLOBALS['wp_auto_test_attachment_metadata'][2] = array(
			'width'  => 0,
			'height' => 0,
		);

		foreach ( array( 1, 2 ) as $id ) {
			$error = $this->service->get( array( 'id' => $id ) );
			self::assertSame( 'wp_auto_media_read_failed', $error->get_error_code() );
		}
		$search = $this->service->search();
		self::assertSame( array(), $search['items'] );
	}

	/**
	 * Build and register a deterministic image attachment fixture.
	 *
	 * @param int         $id Attachment ID.
	 * @param string      $mime MIME type.
	 * @param int         $parent_id Parent ID.
	 * @param string|null $title Optional title.
	 */
	private function image( int $id, string $mime = 'image/jpeg', int $parent_id = 0, ?string $title = null ): WP_Post {
		$extension                                      = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		)[ $mime ] ?? 'bin';
		$GLOBALS['wp_auto_test_attachment_urls'][ $id ] = 'https://example.test/wp-content/uploads/image-' . $id . '.' . $extension;
		$GLOBALS['wp_auto_test_attached_files'][ $id ]  = 'D:/wordpress/uploads/image-' . $id . '.' . $extension;
		$GLOBALS['wp_auto_test_attachment_metadata'][ $id ]                   = array(
			'width'  => 1200 + $id,
			'height' => 800 + $id,
		);
		$GLOBALS['wp_auto_test_post_meta'][ $id ]['_wp_attachment_image_alt'] = '';

		return new WP_Post(
			array(
				'ID'                => $id,
				'post_type'         => 'attachment',
				'post_status'       => 'inherit',
				'post_title'        => $title ?? 'Image ' . $id,
				'post_excerpt'      => 'Caption ' . $id,
				'post_content'      => 'Description ' . $id,
				'post_author'       => 10,
				'post_parent'       => $parent_id,
				'post_mime_type'    => $mime,
				'post_date_gmt'     => sprintf( '2026-01-%02d 00:00:00', min( $id, 28 ) ),
				'post_modified_gmt' => sprintf( '2026-02-%02d 00:00:00', min( $id, 28 ) ),
			)
		);
	}

	/**
	 * Build a deterministic parent Post fixture.
	 *
	 * @param int    $id Post ID.
	 * @param string $password Optional password.
	 */
	private function post( int $id, string $password = '' ): WP_Post {
		return new WP_Post(
			array(
				'ID'            => $id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Parent ' . $id,
				'post_author'   => 20,
				'post_password' => $password,
			)
		);
	}
}
