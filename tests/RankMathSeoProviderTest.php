<?php
/**
 * Rank Math SEO provider tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPAuto\Connector\Seo\RankMathSeoProvider;

/** Covers exact-version admission and bounded five-field normalization. */
final class RankMathSeoProviderTest extends TestCase {
	/** Reset provider state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_capabilities'] = array();
		$GLOBALS['wp_auto_test_post_meta']    = array();
	}

	/** Verify only the admitted provider runtime is available. */
	public function test_requires_the_exact_admitted_runtime_and_capability(): void {
		self::assertTrue( ( new RankMathSeoProvider( '1.0.278' ) )->is_available() );
		self::assertFalse( ( new RankMathSeoProvider( '1.0.279' ) )->is_available() );
		self::assertFalse( ( new RankMathSeoProvider( '' ) )->can_read() );
		$GLOBALS['wp_auto_test_capabilities']['rank_math_onpage_general'] = true;
		self::assertTrue( ( new RankMathSeoProvider( '1.0.278' ) )->can_read() );
	}

	/** Verify provider values map to the frozen public record and protected token state. */
	public function test_reads_the_five_explicit_fields_and_protected_robots_state(): void {
		$GLOBALS['wp_auto_test_post_meta'][7] = array(
			'rank_math_title'         => '%title% | Site',
			'rank_math_description'   => 'Description',
			'rank_math_canonical_url' => 'https://example.test/canonical',
			'rank_math_focus_keyword' => 'alpha, beta',
			'rank_math_robots'        => array( 'noindex', 'follow', 'noarchive' ),
		);

		$state = ( new RankMathSeoProvider( '1.0.278' ) )->read_state( 7 );

		self::assertSame( '%title% | Site', $state['title'] );
		self::assertSame( 'Description', $state['description'] );
		self::assertSame( 'https://example.test/canonical', $state['canonical_url'] );
		self::assertSame( array( 'alpha', 'beta' ), $state['focus_keywords'] );
		self::assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			),
			$state['robots']
		);
		self::assertSame( array( 'noindex', 'follow', 'noarchive' ), $state['protected']['raw_robots'] );
		self::assertNotContains( false, $state['protected']['exists'], true );
	}

	/** Verify absent metadata produces explicit inheritance values. */
	public function test_absent_metadata_returns_inherited_defaults(): void {
		$state = ( new RankMathSeoProvider( '1.0.278' ) )->read_state( 8 );

		self::assertSame( '', $state['title'] );
		self::assertSame( '', $state['description'] );
		self::assertSame( '', $state['canonical_url'] );
		self::assertSame( array(), $state['focus_keywords'] );
		self::assertSame(
			array(
				'index'  => 'default',
				'follow' => 'default',
			),
			$state['robots']
		);
		self::assertNotContains( true, $state['protected']['exists'], true );
	}

	/** Verify metadata API failures fail closed without leaking the exception. */
	public function test_metadata_api_exception_returns_unsupported_state(): void {
		$GLOBALS['wp_auto_test_post_meta'][11]           = array( 'rank_math_title' => 'present' );
		$GLOBALS['wp_auto_test_get_post_meta_exception'] = new \RuntimeException( 'secret metadata failure' );

		$result = ( new RankMathSeoProvider( '1.0.278' ) )->read_state( 11 );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_seo_state_unsupported', $result->get_error_code() );
		self::assertStringNotContainsString( 'secret', $result->get_error_message() );
	}

	/**
	 * Verify malformed provider state fails closed.
	 *
	 * @dataProvider unsupportedStateProvider
	 * @param string $key Provider meta key.
	 * @param mixed  $value Malformed stored value.
	 */
	public function test_rejects_unsupported_provider_state( string $key, $value ): void {
		$GLOBALS['wp_auto_test_post_meta'][9] = array( $key => $value );

		$result = ( new RankMathSeoProvider( '1.0.278' ) )->read_state( 9 );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_seo_state_unsupported', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	/** Return malformed provider fixtures. */
	public function unsupportedStateProvider(): array {
		return array(
			'array title'          => array( 'rank_math_title', array( 'bad' ) ),
			'long title'           => array( 'rank_math_title', str_repeat( 'a', 501 ) ),
			'HTML description'     => array( 'rank_math_description', '<b>bad</b>' ),
			'control description'  => array( 'rank_math_description', "bad\nvalue" ),
			'relative canonical'   => array( 'rank_math_canonical_url', '/relative' ),
			'credential canonical' => array( 'rank_math_canonical_url', 'https://user@example.test/' ),
			'fragment canonical'   => array( 'rank_math_canonical_url', 'https://example.test/#part' ),
			'too many keywords'    => array( 'rank_math_focus_keyword', 'a,b,c,d,e,f' ),
			'duplicate keywords'   => array( 'rank_math_focus_keyword', 'a,a' ),
			'empty keyword'        => array( 'rank_math_focus_keyword', 'a,,b' ),
			'scalar robots'        => array( 'rank_math_robots', 'noindex' ),
			'duplicate robots'     => array( 'rank_math_robots', array( 'index', 'index' ) ),
			'contradictory index'  => array( 'rank_math_robots', array( 'index', 'noindex' ) ),
			'contradictory follow' => array( 'rank_math_robots', array( 'follow', 'nofollow' ) ),
		);
	}
}
