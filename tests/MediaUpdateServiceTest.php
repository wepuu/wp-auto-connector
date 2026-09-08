<?php
/**
 * Media metadata update service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Media\MediaMutationAuditStore;
use WPAuto\Connector\Media\MediaUpdateContract;
use WPAuto\Connector\Media\MediaUpdateService;

/** Covers strict input, authorization, concurrency, invariants, and audit. */
final class MediaUpdateServiceTest extends TestCase {
	/** Reset all media update fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']                   = 7;
		$GLOBALS['wp_auto_test_capabilities']                      = array(
			'read'         => true,
			'upload_files' => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']               = array(
			'edit_post' => array( 41 => true ),
			'read_post' => array( 41 => true ),
		);
		$GLOBALS['wp_auto_test_posts']                             = array( $this->attachment() );
		$GLOBALS['wp_auto_test_attachment_urls']                   = array( 41 => 'https://example.test/uploads/image.png' );
		$GLOBALS['wp_auto_test_attached_files']                    = array( 41 => '/uploads/image.png' );
		$GLOBALS['wp_auto_test_original_image_paths']              = array( 41 => '/uploads/image.png' );
		$GLOBALS['wp_auto_test_attachment_metadata']               = array(
			41 => array(
				'width'  => 100,
				'height' => 80,
			),
		);
		$GLOBALS['wp_auto_test_post_meta']                         = array( 41 => array( '_wp_attachment_image_alt' => 'Old alt' ) );
		$GLOBALS['wp_auto_test_post_meta_values']                  = array();
		$GLOBALS['wp_auto_test_options']                           = array();
		$GLOBALS['wp_auto_test_option_autoload']                   = array();
		$GLOBALS['wp_auto_test_option_cache']                      = array();
		$GLOBALS['wp_auto_test_fail_update_meta']                  = false;
		$GLOBALS['wp_auto_test_update_meta_exception']             = null;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
		$GLOBALS['wp_auto_test_update_result']                     = null;
		$GLOBALS['wp_auto_test_update_exception']                  = null;
		$GLOBALS['wp_auto_test_update_after_exception']            = null;
		$GLOBALS['wp_auto_test_last_update_args']                  = array();
		$GLOBALS['wp_auto_test_next_modified_gmt']                 = '2026-09-08 01:00:01';
		$GLOBALS['wp_auto_test_get_post_calls']                    = 0;
		$GLOBALS['wp_auto_test_before_get_post']                   = null;
		$GLOBALS['wp_auto_test_get_post_exception']                = null;
		$GLOBALS['wp_auto_test_get_post_exception_on_call']        = null;
		$GLOBALS['wp_auto_test_filters']                           = array();
		$GLOBALS['wp_auto_test_current_user_id_calls']             = 0;
		$GLOBALS['wp_auto_test_current_user_can_calls']            = 0;
		$GLOBALS['wp_auto_test_before_current_user_can']           = null;
		$GLOBALS['wp_auto_test_uuid_counter']                      = 0;
	}

	/** All four allowlisted fields update and return the exact full record. */
	public function test_updates_allowlisted_fields_and_appends_exact_audit(): void {
		$result = ( new MediaUpdateService() )->update(
			$this->input(
				array(
					'title'       => 'New title',
					'alt_text'    => 'New \\ alt',
					'caption'     => 'New caption',
					'description' => 'New description',
				)
			)
		);

		self::assertIsArray( $result );
		self::assertSame( array_keys( MediaUpdateContract::output_schema()['properties'] ), array_keys( $result ) );
		self::assertSame( 'New title', $result['title'] );
		self::assertSame( 'New \\ alt', $result['alt_text'] );
		self::assertSame( 'New caption', $result['caption'] );
		self::assertSame( 'New description', $result['description'] );
		self::assertSame( array( 'ID', 'wp_auto_connector_guard_token', 'post_title', 'post_excerpt', 'post_content' ), array_keys( $GLOBALS['wp_auto_test_last_update_args'] ) );
		$events = get_post_meta( 41, MediaMutationAuditStore::meta_key(), true );
		self::assertSame( array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'expected_modified_gmt', 'result_modified_gmt' ), array_keys( $events[0] ) );
		self::assertSame( 'update', $events[0]['operation'] );
		self::assertArrayNotHasKey( 'title', $events[0] );
		self::assertArrayNotHasKey( 'alt_text', $events[0] );
	}

	/** Alt-only updates do not invoke the attachment post update path. */
	public function test_alt_only_update_uses_only_fixed_meta_key(): void {
		$result = ( new MediaUpdateService() )->update( $this->input( array( 'alt_text' => 'Only alt' ) ) );

		self::assertSame( 'Only alt', $result['alt_text'] );
		self::assertSame( 'Old title', $result['title'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
		self::assertSame( 2, $GLOBALS['wp_auto_test_update_meta_calls'] ); // Alt plus audit.
		self::assertSame( '2026-09-08 01:00:00', $result['modified_gmt'] );
	}

	/** Strict validation rejects missing mutations, extras, wrong types, bounds, and dates. */
	public function test_rejects_non_contract_input_before_writes(): void {
		$invalid = array(
			array(
				'id'                    => 41,
				'expected_modified_gmt' => '2026-09-08 01:00:00',
			),
			$this->input( array( 'status' => 'publish' ) ),
			$this->input( array( 'title' => 4 ) ),
			$this->input( array( 'title' => str_repeat( 'x', 201 ) ) ),
			array(
				'id'                    => 41,
				'expected_modified_gmt' => '2026-02-30 00:00:00',
				'title'                 => 'x',
			),
		);
		foreach ( $invalid as $input ) {
			$error = ( new MediaUpdateService() )->update( $input );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
		}
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/** Missing, unsupported, and unauthorized targets share the hidden 404. */
	public function test_hides_target_existence_and_repeats_both_permissions(): void {
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = false;
		self::assertSame( 'wp_auto_media_not_found', ( new MediaUpdateService() )->update( $this->input() )->get_error_code() );

		$GLOBALS['wp_auto_test_capabilities']['upload_files']         = true;
		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][41] = false;
		self::assertSame( 'wp_auto_media_not_found', ( new MediaUpdateService() )->update( $this->input() )->get_error_code() );

		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][41] = true;
		$GLOBALS['wp_auto_test_posts'][0]->post_mime_type             = 'image/svg+xml';
		self::assertSame( 'wp_auto_media_not_found', ( new MediaUpdateService() )->update( $this->input() )->get_error_code() );
	}

	/** The final raw timestamp is checked immediately before writing. */
	public function test_rejects_a_stale_final_timestamp(): void {
		$GLOBALS['wp_auto_test_before_get_post'] = static function ( int $id, int $call ): void {
			if ( 2 === $call ) {
				$GLOBALS['wp_auto_test_posts'][0]->post_modified_gmt = '2026-09-08 01:00:02';
			}
		};
		$error                                   = ( new MediaUpdateService() )->update( $this->input() );

		self::assertSame( 'wp_auto_media_conflict', $error->get_error_code() );
		self::assertSame( 409, $error->get_error_data()['status'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
	}

	/** The exact Core zero sentinel remains a valid raw concurrency token. */
	public function test_accepts_the_core_zero_timestamp_sentinel(): void {
		$GLOBALS['wp_auto_test_posts'][0]->post_modified_gmt = '0000-00-00 00:00:00';
		$input                          = $this->input( array( 'alt_text' => 'Sentinel update' ) );
		$input['expected_modified_gmt'] = '0000-00-00 00:00:00';

		$result = ( new MediaUpdateService() )->update( $input );
		self::assertIsArray( $result );
		self::assertSame( '0000-00-00 00:00:00', $result['modified_gmt'] );
	}

	/** Core-side row tampering is overwritten by the scoped invariant guard. */
	public function test_guard_preserves_protected_attachment_fields(): void {
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_parent']    = 999;
				$data['post_status']    = 'publish';
				$data['post_mime_type'] = 'text/html';
				$data['guid']           = 'https://attacker.test/changed';
				return $data;
			},
			10,
			4
		);
		$result = ( new MediaUpdateService() )->update( $this->input() );

		self::assertIsArray( $result );
		self::assertSame( 0, $result['parent_id'] );
		self::assertSame( 'image/png', $result['mime_type'] );
		self::assertSame( 'https://example.test/uploads/image.png', $result['source_url'] );
	}

	/** A post write followed by alt failure is reported as uncertain. */
	public function test_partial_multi_write_failure_is_uncertain(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$error                                    = ( new MediaUpdateService() )->update(
			$this->input(
				array(
					'title'    => 'Applied',
					'alt_text' => 'Not applied',
				)
			)
		);

		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 'Applied', $GLOBALS['wp_auto_test_posts'][0]->post_title );
	}

	/** Audit failure after a successful attachment write is fail-closed. */
	public function test_audit_failure_after_update_is_uncertain(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$error                                    = ( new MediaUpdateService() )->update( $this->input( array( 'title' => 'Applied before audit' ) ) );

		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 'Applied before audit', $GLOBALS['wp_auto_test_posts'][0]->post_title );
	}

	/** A proven Core post failure reports update_failed and removes its guard. */
	public function test_proven_post_failure_is_stable_and_guard_is_removed(): void {
		$GLOBALS['wp_auto_test_update_result'] = new WP_Error( 'core_failure', 'Sensitive.' );
		$error                                 = ( new MediaUpdateService() )->update( $this->input() );

		self::assertSame( 'wp_auto_media_update_failed', $error->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_filters']['wp_insert_post_data'][ PHP_INT_MAX ] ?? array() );
	}

	/**
	 * Build valid input with caller-selected mutable fields.
	 *
	 * @param array<string, string> $changes Mutable presentation values.
	 */
	private function input( array $changes = array( 'title' => 'New title' ) ): array {
		return array_merge(
			array(
				'id'                    => 41,
				'expected_modified_gmt' => '2026-09-08 01:00:00',
			),
			$changes
		);
	}

	/** Build one fully populated supported image attachment. */
	private function attachment(): WP_Post {
		return new WP_Post(
			array(
				'ID'                => 41,
				'post_type'         => 'attachment',
				'post_status'       => 'inherit',
				'post_name'         => 'image',
				'post_title'        => 'Old title',
				'post_excerpt'      => 'Old caption',
				'post_content'      => 'Old description',
				'post_author'       => 7,
				'post_parent'       => 0,
				'post_mime_type'    => 'image/png',
				'post_modified_gmt' => '2026-09-08 01:00:00',
				'guid'              => 'https://example.test/uploads/image.png',
			)
		);
	}
}
