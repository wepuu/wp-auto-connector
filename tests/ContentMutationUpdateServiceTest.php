<?php
/**
 * Shared Update Draft service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Content\ContentMutationService;
use WPAuto\Connector\Content\MutationAuditStore;

/** Covers Update authorization, concurrency, invariants and failure semantics. */
final class ContentMutationUpdateServiceTest extends TestCase {
	/** Reset mutation fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_posts']                          = array();
		$GLOBALS['wp_auto_test_post_meta']                      = array();
		$GLOBALS['wp_auto_test_post_meta_values']               = array();
		$GLOBALS['wp_auto_test_capabilities']                   = array(
			'edit_posts' => true,
			'edit_pages' => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']            = array();
		$GLOBALS['wp_auto_test_current_user_id']                = 7;
		$GLOBALS['wp_auto_test_current_user_id_calls']          = 0;
		$GLOBALS['wp_auto_test_before_current_user_id']         = null;
		$GLOBALS['wp_auto_test_current_user_can_calls']         = 0;
		$GLOBALS['wp_auto_test_before_current_user_can']        = null;
		$GLOBALS['wp_auto_test_current_user_can_exception']     = null;
		$GLOBALS['wp_auto_test_get_post_type_object_exception'] = null;
		$GLOBALS['wp_auto_test_add_filter_exception']           = null;
		$GLOBALS['wp_auto_test_remove_filter_exception']        = null;
		$GLOBALS['wp_auto_test_filters']                        = array();
		$GLOBALS['wp_auto_test_last_update_args']               = array();
		$GLOBALS['wp_auto_test_update_result']                  = null;
		$GLOBALS['wp_auto_test_update_exception']               = null;
		$GLOBALS['wp_auto_test_update_after_exception']         = null;
		$GLOBALS['wp_auto_test_next_modified_gmt']              = '2026-09-01 12:00:01';
		$GLOBALS['wp_auto_test_get_post_calls']                 = 0;
		$GLOBALS['wp_auto_test_before_get_post']                = null;
		$GLOBALS['wp_auto_test_get_post_exception']             = null;
		$GLOBALS['wp_auto_test_get_post_exception_on_call']     = null;
		$GLOBALS['wp_auto_test_get_post_meta_exception']        = null;
		$GLOBALS['wp_auto_test_fail_update_meta']               = false;
		$GLOBALS['wp_auto_test_update_meta_exception']          = null;
		$GLOBALS['wp_auto_test_before_update_meta']             = null;
		$GLOBALS['wp_auto_test_update_meta_calls']              = 0;
		$GLOBALS['wp_auto_test_permalink_exception']            = null;
	}

	/** Post Update changes only supplied fields and returns the exact final output. */
	public function test_updates_a_post_draft_with_exact_output_and_audit(): void {
		$this->add_draft( 21, 'post' );
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 21,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => "Updated O'Reilly",
				'content'               => 'Updated body',
				'slug'                  => 'updated-slug',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( array( 'id', 'type', 'status', 'slug', 'link', 'edit_url', 'modified_gmt' ), array_keys( $result ) );
		self::assertSame( 'post', $result['type'] );
		self::assertSame( 'updated-slug', $result['slug'] );
		self::assertSame( '2026-09-01 12:00:01', $result['modified_gmt'] );
		self::assertSame( "Updated O'Reilly", get_post( 21 )->post_title );
		self::assertSame( 'Original excerpt', get_post( 21 )->post_excerpt );
		self::assertSame( array( 'ID', 'wp_auto_connector_guard_token', 'post_title', 'post_content', 'post_name' ), array_keys( $GLOBALS['wp_auto_test_last_update_args'] ) );
		self::assertArrayNotHasKey( 'post_status', $GLOBALS['wp_auto_test_last_update_args'] );
		self::assertArrayNotHasKey( 'post_author', $GLOBALS['wp_auto_test_last_update_args'] );

		$events = get_post_meta( 21, MutationAuditStore::meta_key(), true );
		self::assertCount( 1, $events );
		self::assertSame(
			array( 'version', 'operation', 'ability', 'actor_user_id', 'target_object_id', 'timestamp_gmt', 'expected_modified_gmt', 'result_modified_gmt' ),
			array_keys( $events[0] )
		);
		self::assertSame( 'update', $events[0]['operation'] );
		self::assertSame( 'wp-auto/post-update', $events[0]['ability'] );
		self::assertStringNotContainsString( 'Updated', (string) wp_json_encode( $events[0] ) );
	}

	/** Page Update preserves the original parent and author. */
	public function test_updates_a_page_while_preserving_parent_and_author(): void {
		$this->add_draft(
			22,
			'page',
			array(
				'post_parent' => 9,
				'post_author' => 7,
			)
		);
		$result = ( new ContentMutationService() )->update_page_draft(
			array(
				'id'                    => 22,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'excerpt'               => 'New excerpt',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( 'page', $result['type'] );
		self::assertSame( 9, get_post( 22 )->post_parent );
		self::assertSame( 7, get_post( 22 )->post_author );
	}

	/** The exact all-zero sentinel is accepted without normalization. */
	public function test_accepts_the_exact_zero_sentinel_when_stored_by_core(): void {
		$this->add_draft( 23, 'post', array( 'post_modified_gmt' => '0000-00-00 00:00:00' ) );
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 23,
				'expected_modified_gmt' => '0000-00-00 00:00:00',
				'title'                 => 'Sentinel update',
			)
		);

		self::assertIsArray( $result );
	}

	/** Invalid types, bounds, dates, field combinations, and extra keys fail before Core. */
	public function test_rejects_invalid_update_input(): void {
		$this->add_draft( 24, 'post' );
		$invalid = array(
			array(),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
			),
			array(
				'id'                    => '24',
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			),
			array(
				'id'                    => 0,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '0000-00-00 00:00:01',
				'title'                 => 'x',
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-02-30 12:00:00',
				'title'                 => 'x',
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-09-01 24:00:00',
				'title'                 => 'x',
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'slug'                  => '',
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => str_repeat( 'x', 501 ),
			),
			array(
				'id'                    => 24,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
				'status'                => 'publish',
			),
		);
		foreach ( $invalid as $input ) {
			$result = ( new ContentMutationService() )->update_post_draft( $input );
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		}
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
	}

	/** Core's all-falsy supported-field combination is rejected. */
	public function test_rejects_a_core_empty_content_combination(): void {
		$this->add_draft(
			25,
			'post',
			array(
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => 'Excerpt',
			)
		);
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 25,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'excerpt'               => '',
			)
		);

		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		self::assertSame( 'Excerpt', get_post( 25 )->post_excerpt );
	}

	/** Whitespace is truthy to Core and is not rejected as editorially empty. */
	public function test_accepts_whitespace_only_content_when_core_does(): void {
		$this->add_draft(
			37,
			'post',
			array(
				'post_title'   => '',
				'post_content' => 'Original content',
				'post_excerpt' => '',
			)
		);
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 37,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => '',
				'content'               => " \t\n",
			)
		);

		self::assertIsArray( $result );
		self::assertSame( '', get_post( 37 )->post_title );
		self::assertSame( " \t\n", get_post( 37 )->post_content );
	}

	/** String zero is falsy to Core and does not bypass its empty-content rule. */
	public function test_string_zero_matches_core_empty_content_semantics(): void {
		$this->add_draft(
			38,
			'post',
			array(
				'post_title'   => '',
				'post_content' => 'Original content',
				'post_excerpt' => '',
			)
		);
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 38,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'content'               => '0',
			)
		);

		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		self::assertSame( 'Original content', get_post( 38 )->post_content );
	}

	/** Missing, wrong-type, and unauthorized targets share one hidden 404. */
	public function test_hides_missing_wrong_type_and_unauthorized_targets(): void {
		$this->add_draft( 26, 'page' );
		$this->add_draft( 27, 'post' );
		$GLOBALS['wp_auto_test_object_capabilities']['edit_post'][27] = false;

		foreach ( array( 999, 26, 27 ) as $post_id ) {
			$result = ( new ContentMutationService() )->update_post_draft(
				array(
					'id'                    => $post_id,
					'expected_modified_gmt' => '2026-09-01 12:00:00',
					'title'                 => 'x',
				)
			);
			self::assertSame( 'wp_auto_content_not_found', $result->get_error_code() );
			self::assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	/** Baseline denial also fails closed without target disclosure. */
	public function test_baseline_permission_denial_fails_closed(): void {
		$this->add_draft( 28, 'post' );
		$GLOBALS['wp_auto_test_capabilities']['edit_posts'] = false;
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 28,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_content_not_found', $result->get_error_code() );
	}

	/** Only an authorized correct-type non-draft exposes a status conflict. */
	public function test_authorized_non_draft_returns_status_conflict(): void {
		$this->add_draft( 29, 'post', array( 'post_status' => 'publish' ) );
		$GLOBALS['wp_auto_test_capabilities']['edit_published_posts'] = true;
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 29,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_content_status_conflict', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	/** A stale raw token returns 409 and performs no write or audit. */
	public function test_stale_modified_token_rejects_without_writing(): void {
		$this->add_draft( 30, 'post' );
		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 30,
				'expected_modified_gmt' => '2026-09-01 11:59:59',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_content_conflict', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
		self::assertSame( array(), get_post_meta( 30, MutationAuditStore::meta_key(), true ) );
	}

	/** The write-time guard restores protected values changed by other filters. */
	public function test_update_guard_reasserts_all_protected_invariants(): void {
		$this->add_draft(
			31,
			'page',
			array(
				'post_parent'    => 8,
				'post_password'  => 'secret',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'menu_order'     => 4,
			)
		);
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_type']      = 'post';
				$data['post_status']    = 'publish';
				$data['post_author']    = 99;
				$data['post_parent']    = 77;
				$data['post_password']  = '';
				$data['comment_status'] = 'closed';
				$data['ping_status']    = 'closed';
				$data['menu_order']     = 88;
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->update_page_draft(
			array(
				'id'                    => 31,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Guarded',
			)
		);
		$post   = get_post( 31 );
		self::assertIsArray( $result );
		self::assertSame( 'page', $post->post_type );
		self::assertSame( 'draft', $post->post_status );
		self::assertSame( 7, $post->post_author );
		self::assertSame( 8, $post->post_parent );
		self::assertSame( 'secret', $post->post_password );
		self::assertSame( 'open', $post->comment_status );
		self::assertSame( 'open', $post->ping_status );
		self::assertSame( 4, $post->menu_order );
		self::assertSame( array(), $GLOBALS['wp_auto_test_filters']['wp_insert_post_data'][ PHP_INT_MAX ] );
	}

	/** A title-only request protects every omitted mutable field. */
	public function test_guard_protects_mutable_fields_omitted_from_title_only_update(): void {
		$this->add_draft( 39, 'post' );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_title']   = 'Filtered requested title';
				$data['post_content'] = 'Hostile omitted content';
				$data['post_excerpt'] = 'Hostile omitted excerpt';
				$data['post_name']    = 'hostile-omitted-slug';
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 39,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Requested title',
			)
		);
		$post   = get_post( 39 );

		self::assertIsArray( $result );
		self::assertSame( 'Filtered requested title', $post->post_title );
		self::assertSame( 'Original content', $post->post_content );
		self::assertSame( 'Original excerpt', $post->post_excerpt );
		self::assertSame( 'original-slug', $post->post_name );
	}

	/** A content-only request protects title, excerpt, and slug. */
	public function test_guard_protects_omitted_mutables_for_another_request_shape(): void {
		$this->add_draft( 40, 'post' );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_title']   = 'Hostile omitted title';
				$data['post_content'] = 'Filtered requested content';
				$data['post_excerpt'] = 'Hostile omitted excerpt';
				$data['post_name']    = 'hostile-omitted-slug';
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 40,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'content'               => 'Requested content',
			)
		);
		$post   = get_post( 40 );

		self::assertIsArray( $result );
		self::assertSame( 'Original title', $post->post_title );
		self::assertSame( 'Filtered requested content', $post->post_content );
		self::assertSame( 'Original excerpt', $post->post_excerpt );
		self::assertSame( 'original-slug', $post->post_name );
	}

	/** Supplying every mutable field leaves all four open to Core/filter processing. */
	public function test_guard_does_not_freeze_explicitly_supplied_mutable_fields(): void {
		$this->add_draft( 41, 'post' );
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_title']   = 'Filtered title';
				$data['post_content'] = 'Filtered content';
				$data['post_excerpt'] = 'Filtered excerpt';
				$data['post_name']    = 'filtered-slug';
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 41,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Requested title',
				'content'               => 'Requested content',
				'excerpt'               => 'Requested excerpt',
				'slug'                  => 'requested-slug',
			)
		);
		$post   = get_post( 41 );

		self::assertIsArray( $result );
		self::assertSame( 'Filtered title', $post->post_title );
		self::assertSame( 'Filtered content', $post->post_content );
		self::assertSame( 'Filtered excerpt', $post->post_excerpt );
		self::assertSame( 'filtered-slug', $post->post_name );
	}

	/** Protected raw strings survive Core's slashed filter lifecycle byte-for-byte. */
	public function test_guard_preserves_backslashes_in_protected_snapshot_strings(): void {
		$this->add_draft(
			42,
			'post',
			array(
				'post_password'         => 'pass\\word',
				'post_content_filtered' => 'filtered\\content',
				'to_ping'               => 'https://example.test/a\\b',
				'pinged'                => 'https://example.test/c\\d',
			)
		);
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_password']         = 'hostile';
				$data['post_content_filtered'] = 'hostile';
				$data['to_ping']               = 'hostile';
				$data['pinged']                = 'hostile';
				return $data;
			},
			10,
			1
		);

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 42,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Backslash update',
			)
		);
		$post   = get_post( 42 );

		self::assertIsArray( $result );
		self::assertSame( 'pass\\word', $post->post_password );
		self::assertSame( 'filtered\\content', $post->post_content_filtered );
		self::assertSame( 'https://example.test/a\\b', $post->to_ping );
		self::assertSame( 'https://example.test/c\\d', $post->pinged );
	}

	/** A nested unrelated update is not rewritten by the operation-scoped guard. */
	public function test_guard_does_not_rewrite_a_nested_unrelated_update(): void {
		$this->add_draft( 32, 'post' );
		$this->add_draft(
			33,
			'page',
			array(
				'post_status' => 'publish',
				'post_author' => 99,
			)
		);
		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr, array $unsanitized, bool $update ): array {
				static $nested = false;
				unset( $postarr, $unsanitized );
				if ( $update && ! $nested ) {
					$nested = true;
					wp_update_post(
						array(
							'ID'         => 33,
							'post_title' => 'Nested title',
						),
						true,
						true
					);
				}
				return $data;
			},
			10,
			4
		);

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 32,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Outer title',
			)
		);
		self::assertIsArray( $result );
		self::assertSame( 'Outer title', get_post( 32 )->post_title );
		self::assertSame( 'Nested title', get_post( 33 )->post_title );
		self::assertSame( 'publish', get_post( 33 )->post_status );
		self::assertSame( 99, get_post( 33 )->post_author );
	}

	/** A final pre-write re-read catches a concurrent Core change. */
	public function test_pre_write_reread_detects_a_concurrent_change(): void {
		$this->add_draft( 34, 'post' );
		$GLOBALS['wp_auto_test_before_get_post'] = static function ( int $post_id, int $call ): void {
			if ( 34 === $post_id && 3 === $call ) {
				$GLOBALS['wp_auto_test_posts'][0]->post_modified_gmt = '2026-09-01 12:00:02';
			}
		};
		$result                                  = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 34,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_content_conflict', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
	}

	/** Definitive Core failure differs from an exception after a possible write. */
	public function test_core_failure_and_uncertain_exception_have_stable_errors(): void {
		$this->add_draft( 35, 'post' );
		$GLOBALS['wp_auto_test_update_result'] = new WP_Error( 'core_failure', 'sensitive detail' );
		$failed                                = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 35,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);
		self::assertSame( 'wp_auto_content_update_failed', $failed->get_error_code() );
		self::assertStringNotContainsString( 'sensitive', $failed->get_error_message() );

		$GLOBALS['wp_auto_test_update_after_exception'] = new \RuntimeException( 'after write' );
		$uncertain                                      = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 35,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'possibly written',
			)
		);
		self::assertSame( 'wp_auto_mutation_state_uncertain', $uncertain->get_error_code() );
		self::assertSame( 'possibly written', get_post( 35 )->post_title );
	}

	/** Audit failure reports uncertainty after the Core write remains visible. */
	public function test_audit_failure_after_update_returns_uncertain(): void {
		$this->add_draft( 36, 'post' );
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$result                                   = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 36,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Written first',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertSame( 'Written first', get_post( 36 )->post_title );
	}

	/** A post-write permalink Throwable is contained as uncertain without rollback. */
	public function test_post_write_output_throwable_returns_uncertain_without_rollback(): void {
		$this->add_draft( 43, 'post' );
		$GLOBALS['wp_auto_test_permalink_exception'] = new \RuntimeException( 'sensitive permalink failure' );
		$result                                      = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 43,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'Written before output failure',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertStringNotContainsString( 'sensitive', $result->get_error_message() );
		self::assertSame( 'Written before output failure', get_post( 43 )->post_title );
	}

	/** A post-write audit Throwable is contained as uncertain without rollback. */
	public function test_post_write_audit_throwable_returns_uncertain_without_rollback(): void {
		$this->add_draft( 44, 'post' );
		$GLOBALS['wp_auto_test_update_meta_exception'] = new \RuntimeException( 'sensitive audit failure' );
		$result                                        = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 44,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'content'               => 'Written before audit failure',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertStringNotContainsString( 'sensitive', $result->get_error_message() );
		self::assertSame( 'Written before audit failure', get_post( 44 )->post_content );
	}

	/** Preflight target resolution Throwables are sanitized before any write. */
	public function test_update_preflight_get_post_throwable_is_sanitized(): void {
		$this->add_draft( 45, 'post' );
		$GLOBALS['wp_auto_test_get_post_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 45,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertStringNotContainsString( 'sensitive-internal-detail', $result->get_error_message() );
		self::assertSame( 'Original title', get_post( 45 )->post_title );
		self::assertSame( array(), get_post_meta( 45, MutationAuditStore::meta_key(), true ) );
	}

	/** Capability lookup Throwables are contained by the complete Update boundary. */
	public function test_update_capability_throwable_is_sanitized(): void {
		$this->add_draft( 46, 'post' );
		$GLOBALS['wp_auto_test_current_user_can_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 46,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertStringNotContainsString( 'sensitive-internal-detail', $result->get_error_message() );
		self::assertSame( 'Original title', get_post( 46 )->post_title );
	}

	/** Guard installation Throwables are sanitized and do not reach Core Update. */
	public function test_update_add_filter_throwable_is_sanitized(): void {
		$this->add_draft( 47, 'post' );
		$GLOBALS['wp_auto_test_add_filter_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 47,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertStringNotContainsString( 'sensitive-internal-detail', $result->get_error_message() );
		self::assertSame( array(), $GLOBALS['wp_auto_test_last_update_args'] );
	}

	/** Guard cleanup Throwables are sanitized after a possible Core Update. */
	public function test_update_remove_filter_throwable_is_sanitized(): void {
		$this->add_draft( 48, 'post' );
		$GLOBALS['wp_auto_test_remove_filter_exception'] = new \RuntimeException( 'sensitive-internal-detail' );

		$result = ( new ContentMutationService() )->update_post_draft(
			array(
				'id'                    => 48,
				'expected_modified_gmt' => '2026-09-01 12:00:00',
				'title'                 => 'x',
			)
		);

		self::assertSame( 'wp_auto_mutation_state_uncertain', $result->get_error_code() );
		self::assertStringNotContainsString( 'sensitive-internal-detail', $result->get_error_message() );
		self::assertSame( 'x', get_post( 48 )->post_title );
	}

	/**
	 * Add a deterministic draft fixture.
	 *
	 * @param int                  $id Object ID.
	 * @param string               $post_type Fixed type.
	 * @param array<string, mixed> $overrides Fixture overrides.
	 */
	private function add_draft( int $id, string $post_type, array $overrides = array() ): void {
		$GLOBALS['wp_auto_test_posts'][] = new WP_Post(
			array_merge(
				array(
					'ID'                => $id,
					'post_type'         => $post_type,
					'post_status'       => 'draft',
					'post_author'       => 7,
					'post_title'        => 'Original title',
					'post_content'      => 'Original content',
					'post_excerpt'      => 'Original excerpt',
					'post_name'         => 'original-slug',
					'post_modified_gmt' => '2026-09-01 12:00:00',
				),
				$overrides
			)
		);
	}
}
