<?php
/**
 * Featured Image Assignment service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Media\MediaMutationAuditStore;
use WPAuto\Connector\Media\MediaFeaturedService;

/** Covers strict input, authorization, concurrency, invariants, and audit. */
final class MediaFeaturedServiceTest extends TestCase {
	/** Reset all featured-image fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_current_user_id']               = 7;
		$GLOBALS['wp_auto_test_capabilities']                  = array(
			'read'         => true,
			'upload_files' => true,
			'edit_posts'   => true,
			'edit_pages'   => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']           = array(
			'edit_post' => array(
				101 => true,
				102 => true,
				103 => true,
				104 => true,
			),
			'read_post' => array(
				41 => true,
				42 => true,
				43 => true,
			),
		);
		$GLOBALS['wp_auto_test_posts']                         = array( $this->target_post(), $this->target_page(), $this->published_post(), $this->custom_post(), $this->media( 41, 'image/png' ), $this->media( 42, 'image/jpeg' ), $this->media( 43, 'image/svg+xml' ) );
		$GLOBALS['wp_auto_test_thumbnail_ids']                 = array(
			101 => 0,
			102 => 41,
		);
		$GLOBALS['wp_auto_test_post_meta']                     = array();
		$GLOBALS['wp_auto_test_post_meta_values']              = array();
		$GLOBALS['wp_auto_test_update_meta_calls']             = 0;
		$GLOBALS['wp_auto_test_fail_update_meta']              = false;
		$GLOBALS['wp_auto_test_set_thumbnail_calls']           = 0;
		$GLOBALS['wp_auto_test_set_thumbnail_result']          = null;
		$GLOBALS['wp_auto_test_set_thumbnail_exception']       = null;
		$GLOBALS['wp_auto_test_set_thumbnail_after_exception'] = null;
		$GLOBALS['wp_auto_test_before_set_thumbnail']          = null;
	}

	/** Strict validation rejects missing, extra, wrong-type, and negative fields. */
	public function test_rejects_non_contract_input_before_writes(): void {
		$invalid = array(
			array(
				'target_id' => 101,
				'media_id'  => 41,
			),
			array(
				'target_id'                  => 101,
				'media_id'                   => 41,
				'expected_featured_media_id' => 0,
				'extra'                      => 1,
			),
			array(
				'target_id'                  => '101',
				'media_id'                   => 41,
				'expected_featured_media_id' => 0,
			),
			array(
				'target_id'                  => 101,
				'media_id'                   => 0,
				'expected_featured_media_id' => 0,
			),
			array(
				'target_id'                  => 101,
				'media_id'                   => 41,
				'expected_featured_media_id' => -1,
			),
		);
		foreach ( $invalid as $input ) {
			$error = ( new MediaFeaturedService() )->set_featured( $input );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_invalid_request', $error->get_error_code() );
		}
		self::assertSame( 0, $GLOBALS['wp_auto_test_set_thumbnail_calls'] );
	}

	/** Assigning a new image returns the exact output and audits the draft target. */
	public function test_assigns_image_and_audits_target_draft(): void {
		$result = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);

		self::assertSame(
			array(
				'target_id'         => 101,
				'target_type'       => 'post',
				'status'            => 'draft',
				'featured_media_id' => 42,
				'changed'           => true,
			),
			$result
		);
		self::assertSame( 1, $GLOBALS['wp_auto_test_set_thumbnail_calls'] );
		self::assertSame( 42, get_post_thumbnail_id( 101 ) );
		$events = get_post_meta( 101, MediaMutationAuditStore::meta_key(), true );
		self::assertCount( 1, $events );
		self::assertSame(
			array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'expected_featured_media_id', 'result_featured_media_id' ),
			array_keys( $events[0] )
		);
		self::assertSame( 'set_featured', $events[0]['operation'] );
		self::assertSame( 101, $events[0]['target_object_id'] );
		self::assertSame( 0, $events[0]['expected_featured_media_id'] );
		self::assertSame( 42, $events[0]['result_featured_media_id'] );
		self::assertFalse( isset( $GLOBALS['wp_auto_test_post_meta'][42][ MediaMutationAuditStore::meta_key() ] ) );
	}

	/** Existing desired relation is an idempotent no-op, even with a stale expected value. */
	public function test_existing_desired_relation_is_noop_without_audit(): void {
		$result = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 102,
				'media_id'                   => 41,
				'expected_featured_media_id' => 999,
			)
		);

		self::assertSame( 102, $result['target_id'] );
		self::assertSame( 41, $result['featured_media_id'] );
		self::assertFalse( $result['changed'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_set_thumbnail_calls'] );
		self::assertSame( array(), get_post_meta( 102, MediaMutationAuditStore::meta_key(), true ) );
	}

	/** A stale expected relationship is rejected immediately before Core writes. */
	public function test_rejects_stale_featured_relationship(): void {
		$GLOBALS['wp_auto_test_thumbnail_ids'][101] = 41;
		$error                                      = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);

		self::assertSame( 'wp_auto_featured_media_conflict', $error->get_error_code() );
		self::assertSame( 409, $error->get_error_data()['status'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_set_thumbnail_calls'] );
	}

	/** Post and page use their actual edit_posts baseline capabilities. */
	public function test_uses_actual_post_type_baseline_capability(): void {
		$GLOBALS['wp_auto_test_capabilities']['edit_posts'] = false;
		$result = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 102,
				'media_id'                   => 42,
				'expected_featured_media_id' => 41,
			)
		);
		self::assertIsArray( $result );
		self::assertSame( 'page', $result['target_type'] );
		self::assertSame( 42, $result['featured_media_id'] );

		$GLOBALS['wp_auto_test_capabilities']['edit_pages'] = false;
		$error = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
	}

	/** Missing, wrong-type, non-draft, and unauthorized targets share the hidden 404. */
	public function test_hides_invalid_targets(): void {
		foreach ( array( 999, 103, 104 ) as $target_id ) {
			$error = ( new MediaFeaturedService() )->set_featured(
				array(
					'target_id'                  => $target_id,
					'media_id'                   => 42,
					'expected_featured_media_id' => 0,
				)
			);
			self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
		}
		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][101] = false;
		$error = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_content_not_found', $error->get_error_code() );
	}

	/** Missing, unsupported, and unreadable media share the hidden media 404. */
	public function test_hides_invalid_media(): void {
		foreach ( array( 999, 43 ) as $media_id ) {
			$error = ( new MediaFeaturedService() )->set_featured(
				array(
					'target_id'                  => 101,
					'media_id'                   => $media_id,
					'expected_featured_media_id' => 0,
				)
			);
			self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
		}
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][42] = false;
		$error = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
	}

	/** Parentage does not grant media read permission. */
	public function test_parentage_does_not_expand_media_authorization(): void {
		$GLOBALS['wp_auto_test_posts'][4]->post_parent                = 101;
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][41] = false;
		$error = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 41,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_media_not_found', $error->get_error_code() );
	}

	/** Target field tampering after Core is fail-closed as uncertain. */
	public function test_target_invariant_tampering_is_uncertain(): void {
		$GLOBALS['wp_auto_test_before_set_thumbnail'] = static function (): void {
			$GLOBALS['wp_auto_test_posts'][0]->post_title = 'Tampered';
		};
		$error                                        = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 42, get_post_thumbnail_id( 101 ) );
	}

	/** A proven Core false result with the old relation unchanged is update_failed. */
	public function test_proven_core_failure_is_update_failed(): void {
		$GLOBALS['wp_auto_test_set_thumbnail_result'] = false;
		$error                                        = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_featured_media_update_failed', $error->get_error_code() );
		self::assertSame( 0, get_post_thumbnail_id( 101 ) );
	}

	/** An exception after Core may have written the relation and is uncertain. */
	public function test_post_write_exception_is_uncertain(): void {
		$GLOBALS['wp_auto_test_set_thumbnail_after_exception'] = new \RuntimeException( 'ambiguous' );
		$error = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 42, get_post_thumbnail_id( 101 ) );
	}

	/** Audit failure after relation change is fail-closed and never rolls back. */
	public function test_audit_failure_after_relation_change_is_uncertain(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$error                                    = ( new MediaFeaturedService() )->set_featured(
			array(
				'target_id'                  => 101,
				'media_id'                   => 42,
				'expected_featured_media_id' => 0,
			)
		);
		self::assertSame( 'wp_auto_media_state_uncertain', $error->get_error_code() );
		self::assertSame( 42, get_post_thumbnail_id( 101 ) );
	}

	/** Build a draft post target. */
	private function target_post(): WP_Post {
		return new WP_Post(
			array(
				'ID'                => 101,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Draft post',
				'post_content'      => 'Body',
				'post_author'       => 7,
				'post_modified_gmt' => '2026-09-08 01:00:00',
			)
		);
	}

	/** Build a draft page target. */
	private function target_page(): WP_Post {
		return new WP_Post(
			array(
				'ID'                => 102,
				'post_type'         => 'page',
				'post_status'       => 'draft',
				'post_title'        => 'Draft page',
				'post_content'      => 'Page body',
				'post_author'       => 7,
				'post_modified_gmt' => '2026-09-08 01:00:00',
			)
		);
	}

	/** Build a published target that must remain unavailable. */
	private function published_post(): WP_Post {
		return new WP_Post(
			array(
				'ID'          => 103,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => 7,
			)
		);
	}

	/** Build a custom post type target that must remain unavailable. */
	private function custom_post(): WP_Post {
		return new WP_Post(
			array(
				'ID'          => 104,
				'post_type'   => 'book',
				'post_status' => 'draft',
				'post_author' => 7,
			)
		);
	}

	/**
	 * Build a supported or unsupported attachment.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $mime Attachment MIME type.
	 */
	private function media( int $id, string $mime ): WP_Post {
		return new WP_Post(
			array(
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
				'post_author'    => 7,
			)
		);
	}
}
