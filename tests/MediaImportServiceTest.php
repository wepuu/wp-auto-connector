<?php
/**
 * Remote media import service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Media\MediaImportContract;
use WPAuto\Connector\Media\MediaImportService;
use WPAuto\Connector\Media\MediaMutationAuditStore;
use WPAuto\Connector\Media\RemoteMediaDownloaderInterface;

/** Covers import authorization, idempotency, Core ingestion, and privacy. */
final class MediaImportServiceTest extends TestCase {
	/** Reset import fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']                   = 7;
		$GLOBALS['wp_auto_test_before_get_post']                   = null;
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
		$GLOBALS['wp_auto_test_next_attachment_id']                = 3000;
		$GLOBALS['wp_auto_test_media_paths']                       = array();
		$GLOBALS['wp_auto_test_allowed_mime_types']                = array( 'png' => 'image/png' );
	}

	/** Remove only test-created media paths. */
	protected function tearDown(): void {
		foreach ( $GLOBALS['wp_auto_test_media_paths'] as $path ) {
			wp_delete_file( $path );
		}
	}

	/** A valid remote file creates one verified attachment and private audit event. */
	public function test_import_creates_one_verified_attachment(): void {
		$downloader = $this->downloader();
		$result     = ( new MediaImportService( null, null, null, null, $downloader ) )->import( $this->input() );

		self::assertIsArray( $result );
		self::assertSame( array_keys( MediaImportContract::output_schema()['properties'] ), array_keys( $result ) );
		self::assertFalse( $result['idempotency_replayed'] );
		self::assertSame( 'image/png', $result['mime_type'] );
		self::assertSame( 1, $downloader->calls );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
		$events = get_post_meta( $result['id'], MediaMutationAuditStore::meta_key(), true );
		self::assertSame( 'import_url', $events[0]['operation'] );
		self::assertSame( 'wp-auto/media-import-url', $events[0]['ability'] );
		self::assertArrayNotHasKey( 'url', $events[0] );
	}

	/** A completed import replays without another network or Core operation. */
	public function test_completed_import_replays_without_second_write(): void {
		$downloader = $this->downloader();
		$service    = new MediaImportService( null, null, null, null, $downloader );
		$first      = $service->import( $this->input() );
		$second     = $service->import( $this->input() );

		self::assertSame( $first['id'], $second['id'] );
		self::assertTrue( $second['idempotency_replayed'] );
		self::assertSame( 1, $downloader->calls );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** A live claim blocks a second owner without another download or Core write. */
	public function test_in_progress_claim_blocks_second_owner(): void {
		$input       = $this->input();
		$fingerprint = hash( 'sha256', wp_json_encode( array( $input['url'], $input['filename'], 0 ), JSON_UNESCAPED_SLASHES ) );
		$store       = new \WPAuto\Connector\Media\MediaIngestionIdempotencyStore();
		$claim       = $store->claim( 'wp-auto/media-import-url', 7, $input['idempotency_key'], $fingerprint );
		$downloader  = $this->downloader();
		$error       = ( new MediaImportService( $store, null, null, null, $downloader ) )->import( $input );

		self::assertSame( 'claimed', $claim['status'] );
		self::assertSame( 'wp_auto_idempotency_in_progress', $error->get_error_code() );
		self::assertSame( 0, $downloader->calls );
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** A completed claim with a different canonical payload is a conflict without another write. */
	public function test_completed_import_rejects_different_payload_for_same_key(): void {
		$downloader = $this->downloader();
		$service    = new MediaImportService( null, null, null, null, $downloader );
		$service->import( $this->input() );
		$conflict             = $this->input();
		$conflict['filename'] = 'other.png';
		$error                = $service->import( $conflict );

		self::assertSame( 'wp_auto_idempotency_conflict', $error->get_error_code() );
		self::assertSame( 1, $downloader->calls );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
	}

	/** An ambiguous Core sideload failure retains the claim and cleans the owned temp file. */
	public function test_core_sideload_uncertainty_retains_claim(): void {
		$GLOBALS['wp_auto_test_media_handle_result'] = new WP_Error( 'upload_error', 'hidden Core detail' );
		$downloader                                  = $this->downloader();
		$error                                       = ( new MediaImportService( null, null, null, null, $downloader ) )->import( $this->input() );

		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 1, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertCount( 1, $GLOBALS['wp_auto_test_options'] );
		$record = array_values( $GLOBALS['wp_auto_test_options'] )[0];
		self::assertSame( 'in_progress', $record['state'] );
		self::assertSame( 0, $record['target_id'] );
	}

	/** A policy rejection releases the claim and never reaches Core. */
	public function test_remote_rejection_releases_claim_without_core_write(): void {
		$downloader = new class() implements RemoteMediaDownloaderInterface {
			/** Number of download calls.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Return a sanitized remote policy error.
			 *
			 * @param string $url URL.
			 * @return array{path:string,url:string}|WP_Error
			 */
			public function download( string $url ) {
				unset( $url );
				++$this->calls;
				return new WP_Error( 'wp_auto_remote_media_rejected', 'hidden' );
			}
		};
		$error      = ( new MediaImportService( null, null, null, null, $downloader ) )->import( $this->input() );

		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 1, $downloader->calls );
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** Downloader implementation details never become public semantic errors. */
	public function test_downloader_error_is_mapped_to_remote_policy_error(): void {
		$downloader = new class() implements RemoteMediaDownloaderInterface {
			/**
			 * Return a deliberately unexpected internal error.
			 *
			 * @param string $url URL.
			 * @return WP_Error
			 */
			public function download( string $url ) {
				unset( $url );
				return new WP_Error( 'transport_internal_detail', 'sensitive lower-level detail' );
			}
		};

		$error = ( new MediaImportService( null, null, null, null, $downloader ) )->import( $this->input() );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** Malformed URL and unsupported extension fail before claim or network work. */
	public function test_invalid_url_and_filename_fail_before_network(): void {
		$downloader     = $this->downloader();
		$service        = new MediaImportService( null, null, null, null, $downloader );
		$invalid        = $this->input();
		$invalid['url'] = 'http://127.0.0.1/private.png';
		self::assertSame( 'wp_auto_remote_media_rejected', $service->import( $invalid )->get_error_code() );
		$invalid['url']      = 'https://example.com/image.png';
		$invalid['filename'] = 'image.txt';
		self::assertSame( 'wp_auto_invalid_request', $service->import( $invalid )->get_error_code() );
		self::assertSame( 0, $downloader->calls );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/** A parent that becomes unavailable before sideload keeps its existence-hidden error. */
	public function test_rechecks_parent_authorization_before_core_write(): void {
		$input                         = $this->input();
		$input['parent_id']            = 41;
		$GLOBALS['wp_auto_test_posts'] = array( $this->parent( 41 ) );
		$downloader                    = $this->downloader( 41 );

		$error = ( new MediaImportService( null, null, null, null, $downloader ) )->import( $input );
		self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_media_handle_calls'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_options'] );
	}

	/**
	 * Build a deterministic downloader fixture containing one image-shaped file.
	 *
	 * @param int|null $revoke_parent_id Parent whose edit capability is revoked during download.
	 */
	private function downloader( ?int $revoke_parent_id = null ): RemoteMediaDownloaderInterface {
		return new class( $revoke_parent_id ) implements RemoteMediaDownloaderInterface {
			/** Number of download calls.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Parent whose edit access is revoked after the download starts.
			 *
			 * @var int|null
			 */
			private ?int $revoke_parent_id;

			/**
			 * Configure an optional post-download parent permission change.
			 *
			 * @param int|null $revoke_parent_id Parent whose edit capability is revoked during download.
			 */
			public function __construct( ?int $revoke_parent_id ) {
				$this->revoke_parent_id = $revoke_parent_id;
			}

			/**
			 * Write one deterministic fixture file.
			 *
			 * @param string $url URL.
			 * @return array{path:string,url:string}|WP_Error
			 */
			public function download( string $url ) {
				++$this->calls;
				if ( null !== $this->revoke_parent_id ) {
					$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][ $this->revoke_parent_id ] = false;
				}
				$path = wp_tempnam( 'import.png' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a deterministic fixture file.
				file_put_contents( $path, 'png-bytes' );
				return array(
					'path' => $path,
					'url'  => $url,
				);
			}
		};
	}

	/** Build valid frozen-contract input. */
	private function input(): array {
		return array(
			'url'             => 'https://images.example.com/pixel.png',
			'filename'        => 'pixel.png',
			'idempotency_key' => 'import-key-000001',
		);
	}

	/**
	 * Build a parent fixture when a test needs one.
	 *
	 * @param int $id Parent ID.
	 */
	private function parent( int $id ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => 7,
			)
		);
	}
}
