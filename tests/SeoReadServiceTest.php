<?php
/**
 * SEO read service tests.
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

/** Covers provider resolution, authorization, existence hiding, and state tokens. */
final class SeoReadServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var SeoReadService
	 */
	private SeoReadService $service;

	/** Reset shared Core state. */
	protected function setUp(): void {
		$this->service                               = new SeoReadService( new SeoProviderRegistry( array( new RankMathSeoProvider( '1.0.278' ) ) ) );
		$GLOBALS['wp_auto_test_current_blog_id']     = 1;
		$GLOBALS['wp_auto_test_current_user_id']     = 10;
		$GLOBALS['wp_auto_test_capabilities']        = array(
			'read'                     => true,
			'rank_math_onpage_general' => true,
		);
		$GLOBALS['wp_auto_test_object_capabilities'] = array();
		$GLOBALS['wp_auto_test_posts']               = array();
		$GLOBALS['wp_auto_test_post_meta']           = array();
		$GLOBALS['wp_auto_test_post_meta_values']    = array();
		$GLOBALS['wp_auto_test_get_post_calls']      = 0;
	}

	/** Verify the exact public record and opaque token for an authorized Post. */
	public function test_returns_only_the_frozen_public_record(): void {
		$GLOBALS['wp_auto_test_posts'][]      = $this->post( 7 );
		$GLOBALS['wp_auto_test_post_meta'][7] = array(
			'rank_math_title'         => 'Title',
			'rank_math_description'   => 'Description',
			'rank_math_canonical_url' => 'https://example.test/post',
			'rank_math_focus_keyword' => 'one,two',
			'rank_math_robots'        => array( 'index', 'nofollow', 'noarchive' ),
		);

		$result = $this->service->get( array( 'id' => 7 ) );

		self::assertSame( array( 'id', 'type', 'status', 'title', 'description', 'canonical_url', 'focus_keywords', 'robots', 'state_token' ), array_keys( $result ) );
		self::assertSame( 7, $result['id'] );
		self::assertSame( 'post', $result['type'] );
		self::assertSame( 'draft', $result['status'] );
		self::assertSame( array( 'one', 'two' ), $result['focus_keywords'] );
		self::assertSame(
			array(
				'index'  => 'index',
				'follow' => 'nofollow',
			),
			$result['robots']
		);
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['state_token'] );
		self::assertArrayNotHasKey( 'provider', $result );
		self::assertArrayNotHasKey( 'protected', $result );
	}

	/** Verify Page is supported and the token covers protected provider state. */
	public function test_supports_page_and_token_changes_with_protected_state(): void {
		$GLOBALS['wp_auto_test_posts'][]                          = $this->post( 8, 'page' );
		$GLOBALS['wp_auto_test_post_meta'][8]['rank_math_robots'] = array( 'index', 'noarchive' );
		$first = $this->service->get( array( 'id' => 8 ) );

		$GLOBALS['wp_auto_test_post_meta'][8]['rank_math_robots'] = array( 'index', 'nosnippet' );
		$second = $this->service->get( array( 'id' => 8 ) );

		self::assertSame( 'page', $first['type'] );
		self::assertSame( $first['robots'], $second['robots'] );
		self::assertNotSame( $first['state_token'], $second['state_token'] );
	}

	/**
	 * Verify strict direct-service input validation.
	 *
	 * @dataProvider invalidInputProvider
	 * @param mixed $input Invalid input.
	 */
	public function test_rejects_invalid_input( $input ): void {
		$result = $this->service->get( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_invalid_request', $result->get_error_code() );
	}

	/** Return invalid inputs. */
	public function invalidInputProvider(): array {
		return array(
			'non-array'      => array( '7' ),
			'missing ID'     => array( array() ),
			'extra property' => array(
				array(
					'id'       => 7,
					'provider' => 'rank-math',
				),
			),
			'string ID'      => array( array( 'id' => '7' ) ),
			'zero ID'        => array( array( 'id' => 0 ) ),
		);
	}

	/** Verify missing, unsupported, and unauthorized targets share one hidden error. */
	public function test_hides_missing_unsupported_and_unauthorized_targets(): void {
		$targets                       = array(
			$this->post( 1, 'attachment' ),
			$this->post( 2, 'product' ),
			$this->post( 3, 'post', 'trash' ),
			$this->post( 4, 'post', 'draft', 'secret' ),
			$this->post( 5 ),
		);
		$GLOBALS['wp_auto_test_posts'] = $targets;
		$GLOBALS['wp_auto_test_object_capabilities']['read_post'][5] = false;

		foreach ( array( 1, 2, 3, 4, 5, 999 ) as $id ) {
			$result = $this->service->get( array( 'id' => $id ) );
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'wp_auto_seo_not_found', $result->get_error_code() );
		}
	}

	/** Verify provider absence and provider-capability denial fail closed. */
	public function test_provider_resolution_and_permission_fail_closed(): void {
		$unavailable = new SeoReadService( new SeoProviderRegistry( array() ) );
		$result      = $unavailable->get( array( 'id' => 7 ) );
		self::assertSame( 'wp_auto_seo_provider_unavailable', $result->get_error_code() );

		$conflict = new SeoReadService(
			new SeoProviderRegistry(
				array(
					new RankMathSeoProvider( '1.0.278' ),
					new RankMathSeoProvider( '1.0.278' ),
				)
			)
		);
		$result   = $conflict->get( array( 'id' => 7 ) );
		self::assertSame( 'wp_auto_seo_provider_conflict', $result->get_error_code() );

		$GLOBALS['wp_auto_test_posts'][]                                  = $this->post( 7 );
		$GLOBALS['wp_auto_test_capabilities']['rank_math_onpage_general'] = false;
		$result = $this->service->get( array( 'id' => 7 ) );
		self::assertSame( 'wp_auto_seo_not_found', $result->get_error_code() );
		self::assertFalse( $this->service->can_read() );
	}

	/**
	 * Build a deterministic Core-like fixture.
	 *
	 * @param int    $id Target object ID.
	 * @param string $type Post type.
	 * @param string $status Post status.
	 * @param string $password Stored password.
	 */
	private function post( int $id, string $type = 'post', string $status = 'draft', string $password = '' ): WP_Post {
		return new WP_Post(
			array(
				'ID'            => $id,
				'post_type'     => $type,
				'post_status'   => $status,
				'post_password' => $password,
				'post_author'   => 10,
			)
		);
	}
}
