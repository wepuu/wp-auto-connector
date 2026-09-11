<?php
/**
 * SEO Update service tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPAuto\Connector\Seo\RankMathSeoProvider;
use WPAuto\Connector\Seo\SeoProviderRegistry;
use WPAuto\Connector\Seo\SeoReadService;
use WPAuto\Connector\Seo\SeoUpdateService;

/** Covers strict update validation, concurrency, writes, and protected state. */
final class SeoUpdateServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var SeoUpdateService
	 */
	private SeoUpdateService $service;

	/** Reset deterministic WordPress fixtures. */
	protected function setUp(): void {
		$this->service                                 = new SeoUpdateService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) );
		$GLOBALS['wp_auto_test_current_blog_id']       = 1;
		$GLOBALS['wp_auto_test_current_user_id']       = 10;
		$GLOBALS['wp_auto_test_capabilities']          = array(
			'read'                     => true,
			'edit_posts'               => true,
			'rank_math_onpage_general' => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities']   = array();
		$GLOBALS['wp_auto_test_posts']                 = array( $this->post( 7 ) );
		$GLOBALS['wp_auto_test_post_meta']             = array(
			7 => array(
				'rank_math_title'  => 'Old',
				'rank_math_robots' => array( 'index', 'noarchive' ),
			),
		);
		$GLOBALS['wp_auto_test_post_meta_values']      = array();
		$GLOBALS['wp_auto_test_terms']                 = array();
		$GLOBALS['wp_auto_test_thumbnail_ids']         = array();
		$GLOBALS['wp_auto_test_options']               = array();
		$GLOBALS['wp_auto_test_option_autoload']       = array();
		$GLOBALS['wp_auto_test_option_cache']          = array();
		$GLOBALS['wp_auto_test_notoptions_cache']      = null;
		$GLOBALS['wp_auto_test_alloptions_cache']      = null;
		$GLOBALS['wp_auto_test_uuid_counter']          = 0;
		$GLOBALS['wp_auto_test_fail_update_meta']      = false;
		$GLOBALS['wp_auto_test_update_meta_exception'] = null;
		$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
		$GLOBALS['wp_auto_test_update_meta_calls']                 = 0;
	}

	/** A multi-field update writes only the allowlist and preserves other robots directives. */
	public function test_updates_allowlisted_fields_and_preserves_non_target_robots(): void {
		$read   = ( new SeoReadService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) ) )->get( array( 'id' => 7 ) );
		$result = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => $read['state_token'],
				'title'                => 'New title',
				'robots'               => array(
					'index'  => 'noindex',
					'follow' => 'nofollow',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertSame( array( 'title', 'robots' ), $result['changed_fields'] );
		self::assertFalse( $result['no_op'] );
		self::assertSame( 'New title', $result['title'] );
		self::assertSame( array( 'noarchive', 'noindex', 'nofollow' ), $GLOBALS['wp_auto_test_post_meta'][7]['rank_math_robots'] );
		self::assertArrayHasKey( '_wp_auto_connector_seo_mutation_audit', $GLOBALS['wp_auto_test_post_meta'][7] );
	}

	/** JSON object key order must not affect the robots state comparison. */
	public function test_normalizes_unordered_robots_keys_before_write(): void {
		$read   = ( new SeoReadService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) ) )->get( array( 'id' => 7 ) );
		$result = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => $read['state_token'],
				'robots'               => array(
					'follow' => 'nofollow',
					'index'  => 'noindex',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertSame( array( 'robots' ), $result['changed_fields'] );
		self::assertFalse( $result['no_op'] );
		self::assertSame( array( 'noarchive', 'noindex', 'nofollow' ), $GLOBALS['wp_auto_test_post_meta'][7]['rank_math_robots'] );
	}

	/** A stale token is accepted for a true no-op. */
	public function test_stale_token_noop_succeeds(): void {
		$result = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
				'title'                => 'Old',
			)
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['no_op'] );
		self::assertSame( array(), $result['changed_fields'] );
	}

	/** A stale token blocks a real change. */
	public function test_stale_token_conflicts_before_write(): void {
		$result = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
				'title'                => 'Changed',
			)
		);
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_seo_conflict', $result->get_error_code() );
		self::assertSame( 0, $GLOBALS['wp_auto_test_update_meta_calls'] );
	}

	/** Only drafts are mutable; authorized published objects return a status conflict. */
	public function test_published_target_returns_status_conflict(): void {
		$GLOBALS['wp_auto_test_posts']                                = array( $this->post( 7, 'post', 'publish' ) );
		$GLOBALS['wp_auto_test_capabilities']['edit_published_posts'] = true;
		$read   = ( new SeoReadService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) ) )->get( array( 'id' => 7 ) );
		$result = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => $read['state_token'],
				'title'                => 'Changed',
			)
		);
		self::assertSame( 'wp_auto_seo_status_conflict', $result->get_error_code() );
	}

	/** Malformed input and unsupported providers fail without touching metadata. */
	public function test_rejects_strict_input_and_unavailable_provider(): void {
		foreach ( array(
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
			),
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
				'robots'               => array( 'index' => 'index' ),
			),
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
				'canonical_url'        => 'javascript:alert(1)',
			),
		) as $input ) {
			$result = $this->service->update( $input );
			self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
		}
		$unavailable = new SeoUpdateService( new SeoProviderRegistry( array() ) );
		$result      = $unavailable->update(
			array(
				'id'                   => 7,
				'expected_state_token' => str_repeat( '0', 64 ),
				'title'                => 'Changed',
			)
		);
		self::assertSame( 'wp_auto_seo_provider_unavailable', $result->get_error_code() );
	}

	/** A failed write with unchanged state is reported as proven-unapplied. */
	public function test_failed_write_returns_write_failed(): void {
		$GLOBALS['wp_auto_test_fail_update_meta'] = true;
		$read                                     = ( new SeoReadService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) ) )->get( array( 'id' => 7 ) );
		$result                                   = $this->service->update(
			array(
				'id'                   => 7,
				'expected_state_token' => $read['state_token'],
				'title'                => 'Changed',
			)
		);
		self::assertSame( 'wp_auto_seo_write_failed', $result->get_error_code() );
	}

	/**
	 * Build a deterministic draft fixture.
	 *
	 * @param int    $id Target ID.
	 * @param string $type Post type.
	 * @param string $status Post status.
	 */
	private function post( int $id, string $type = 'post', string $status = 'draft' ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => $status,
				'post_author' => 10,
			)
		);
	}
}
