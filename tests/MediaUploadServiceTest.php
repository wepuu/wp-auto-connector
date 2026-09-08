<?php
/**
 * Authenticated image upload service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Media\MediaMutationAuditStore;
use WPAuto\Connector\Media\MediaUploadContract;
use WPAuto\Connector\Media\MediaUploadService;

/** Covers validation, authorization, file handling, idempotency, and audit safety. */
final class MediaUploadServiceTest extends TestCase {
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

	/** Reset all upload fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']                   = 7;
		$GLOBALS['wp_auto_test_capabilities']                      = array(
			'read'         => true,
			'upload_files' => true,
			'edit_posts'   => true,
			'edit_pages'   => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']               = array();
		$GLOBALS['wp_auto_test_posts']                             = array();
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_notoptions_cache']                  = null;
		$GLOBALS['wp_auto_test_alloptions_cache']                  = null;
		$GLOBALS['wp_auto_test_use_option_cache']                  = false;
		$GLOBALS['wp_auto_test_db_query_exception']                = null;
		$GLOBALS['wp_auto_test_db_query_after_write_exception']    = null;
		$GLOBALS['wp_auto_test_db_last_error']                     = '';
		$GLOBALS['wp_auto_test_db_return_override']                = null;
		$GLOBALS['wp_auto_test_fail_update_option']                = false;
		$GLOBALS['wp_auto_test_fail_update_option_on_call']        = null;
		$GLOBALS['wp_auto_test_update_option_calls']               = 0;
		$GLOBALS['wp_auto_test_fail_delete_option']                = false;
		$GLOBALS['wp_auto_test_delete_option_exception']           = null;
		$GLOBALS['wp_auto_test_post_meta']                         = array();
		$GLOBALS['wp_auto_test_post_meta_values']                  = array();
		$GLOBALS['wp_auto_test_fail_update_meta']                  = false;
		$GLOBALS['wp_auto_test_update_meta_exception']             = null;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
		$GLOBALS['wp_auto_test_attachment_urls']                   = array();
		$GLOBALS['wp_auto_test_attached_files']                    = array();
		$GLOBALS['wp_auto_test_original_image_paths']              = array();
		$GLOBALS['wp_auto_test_attachment_metadata']               = array();
		$GLOBALS['wp_auto_test_max_upload_size']                   = 10485760;
		$GLOBALS['wp_auto_test_image_mime']                        = 'image/png';
		$GLOBALS['wp_auto_test_filetype_override']                 = null;
		$GLOBALS['wp_auto_test_tempnam_failure']                   = false;
		$GLOBALS['wp_auto_test_delete_file_failure']               = false;
		$GLOBALS['wp_auto_test_media_handle_result']               = null;
		$GLOBALS['wp_auto_test_media_handle_calls']                = 0;
		$GLOBALS['wp_auto_test_media_transform']                   = false;
		$GLOBALS['wp_auto_test_next_attachment_id']                = 2000;
		$GLOBALS['wp_auto_test_media_paths']                       = array();
		$GLOBALS['wp_auto_test_allowed_mime_types']                = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'avif'         => 'image/avif',
		);
	}

	/** Remove only test-created media paths. */
	protected function tearDown(): void {
		foreach ( $GLOBALS['wp_auto_test_media_paths'] as $path ) {
			wp_delete_file( $path );
		}
	}

	/** A valid image creates exactly one attachment and a completed private claim. */
	public function test_upload_creates_one_verified_attachment(): void {
		$result = ( new MediaUploadService() )->upload( $this->input() );

		self::assertIsArray( $result );
		self::assertSame( array_keys( MediaUploadContract::output_schema()['properties'] ), array_keys( $result ) );
		self::assertFalse( $result['idempotency_replayed'] );
		self::assertSame( 'image/png', $result['mime_type'] );
		self::assertSame( 0, $result['parent_id'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
		$record = reset( $GLOBALS['wp_auto_test_options'] );
		self::assertSame( 'completed', $record['state'] );
		self::assertSame( $result['id'], $record['target_id'] );
		self::assertArrayNotHasKey( 'idempotency_key', $record );
		self::assertArrayNotHasKey( 'filename', $record );
		$events = get_post_meta( $result['id'], MediaMutationAuditStore::meta_key(), true );
		self::assertCount( 1, $events );
		self::assertSame( array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'fingerprint' ), array_keys( $events[0] ) );
	}

	/** Same request replays the existing attachment without another Core write. */
	public function test_completed_request_replays_without_second_upload(): void {
		$service = new MediaUploadService();
		$first   = $service->upload( $this->input() );
		$second  = $service->upload( $this->input() );

		self::assertSame( $first['id'], $second['id'] );
		self::assertTrue( $second['idempotency_replayed'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_posts'] );
	}

	/** Core transformations retain replay correlation through the original. */
	public function test_core_transformed_image_replays_against_original_bytes(): void {
		$GLOBALS['wp_auto_test_media_transform'] = true;
		$service                                 = new MediaUploadService();

		$first  = $service->upload( $this->input() );
		$second = $service->upload( $this->input() );

		self::assertIsArray( $first );
		self::assertSame( $first['id'], $second['id'] );
		self::assertTrue( $second['idempotency_replayed'] );
		self::assertNotSame( $GLOBALS['wp_auto_test_attached_files'][ $first['id'] ], $GLOBALS['wp_auto_test_original_image_paths'][ $first['id'] ] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** Reusing a key for different bytes is a conflict and creates nothing else. */
	public function test_same_key_with_different_payload_conflicts(): void {
		$service = new MediaUploadService();
		$service->upload( $this->input() );
		$changed = $this->input();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds benign binary transport test input.
		$changed['content_base64'] = base64_encode( 'different image-shaped bytes' );

		$error = $service->upload( $changed );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_idempotency_conflict', $error->get_error_code() );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** Strict validation rejects malformed fields before ownership or Core. */
	public function test_rejects_invalid_transport_fields_before_write(): void {
		$invalid                                 = array(
			array_merge( $this->input(), array( 'content_base64' => "aGVs\nbG8=" ) ),
			array_merge( $this->input(), array( 'content_base64' => 'data:image/png;base64,aGVsbG8=' ) ),
			array_merge( $this->input(), array( 'filename' => '../image.png' ) ),
			array_merge( $this->input(), array( 'status' => 'publish' ) ),
			array_merge( $this->input(), array( 'idempotency_key' => 'short' ) ),
		);
		$GLOBALS['wp_auto_test_max_upload_size'] = 2;
		$invalid[]                               = $this->input();

		foreach ( $invalid as $input ) {
			$error = ( new MediaUploadService() )->upload( $input );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
		}
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** MIME spoofing fails before Core and releases the deterministic claim. */
	public function test_mime_mismatch_releases_claim_without_core_write(): void {
		$GLOBALS['wp_auto_test_image_mime'] = 'image/jpeg';
		$error                              = ( new MediaUploadService() )->upload( $this->input() );

		self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** Parent visibility is hidden and only editable Post/Page drafts are accepted. */
	public function test_parent_must_be_an_editable_post_or_page_draft(): void {
		$GLOBALS['wp_auto_test_posts'] = array( $this->parent( 50, 'publish' ) );
		$input                         = $this->input();
		$input['parent_id']            = 50;
		$error                         = ( new MediaUploadService() )->upload( $input );
		self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );

		$GLOBALS['wp_auto_test_posts']                                = array( $this->parent( 50, 'draft' ) );
		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][50] = false;
		$error = ( new MediaUploadService() )->upload( $input );
		self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );

		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][50] = true;
		$result = ( new MediaUploadService() )->upload( $input );
		self::assertSame( 50, $result['parent_id'] );
	}

	/** A Core-side ambiguous failure retains ownership and blocks retry. */
	public function test_core_failure_retains_claim_and_blocks_retry(): void {
		$GLOBALS['wp_auto_test_media_handle_result'] = new WP_Error( 'core_upload_failed', 'Sensitive detail.' );
		$service                                     = new MediaUploadService();
		$error                                       = $service->upload( $this->input() );

		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
		self::assertSame( 'in_progress', reset( $GLOBALS['wp_auto_test_options'] )['state'] );
		$retry = $service->upload( $this->input() );
		self::assertSame( 'wp_auto_idempotency_in_progress', $retry->get_error_code() );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** An audit-recorded claim can finish deterministically on retry. */
	public function test_retry_completes_an_audit_recorded_claim(): void {
		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = 3;
		$service = new MediaUploadService();
		$error   = $service->upload( $this->input() );
		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 'audit_recorded', reset( $GLOBALS['wp_auto_test_options'] )['state'] );

		$GLOBALS['wp_auto_test_fail_update_option_on_call'] = null;
		$result = $service->upload( $this->input() );
		self::assertTrue( $result['idempotency_replayed'] );
		self::assertSame( 'completed', reset( $GLOBALS['wp_auto_test_options'] )['state'] );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** Service permission denial performs no claim or file work. */
	public function test_service_repeats_upload_files_permission(): void {
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = false;
		$error = ( new MediaUploadService() )->upload( $this->input() );
		self::assertSame( 'wp_auto_media_create_failed', $error->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** Build valid frozen-contract input. */
	private function input(): array {
		return array(
			'filename'        => 'pixel.png',
			'content_base64'  => self::PNG_BASE64,
			'idempotency_key' => 'upload-key-000001',
		);
	}

	/**
	 * Build a parent fixture.
	 *
	 * @param int    $id Post ID.
	 * @param string $status Post status.
	 */
	private function parent( int $id, string $status ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'post',
				'post_status' => $status,
				'post_author' => 7,
			)
		);
	}
}
