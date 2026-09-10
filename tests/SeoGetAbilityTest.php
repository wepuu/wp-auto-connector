<?php
/**
 * SEO Get Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPAuto\Connector\Abilities\Seo\SeoAbilityCategory;
use WPAuto\Connector\Abilities\Seo\SeoGetAbility;

/** Covers registration, frozen schemas, annotations, and entry permission. */
final class SeoGetAbilityTest extends TestCase {
	/** Reset shared WordPress test state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify canonical registration timing. */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new SeoGetAbility();
		$ability->register();

		self::assertSame( 'wp-auto/seo-get', SeoGetAbility::NAME );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/** Verify the exact provider-neutral input and output contract. */
	public function test_registration_has_the_frozen_contract(): void {
		( new SeoGetAbility() )->register_ability();

		$args   = $GLOBALS['wp_auto_test_registered_ability']['args'];
		$input  = $args['input_schema'];
		$output = $args['output_schema'];
		$fields = array( 'id', 'type', 'status', 'title', 'description', 'canonical_url', 'focus_keywords', 'robots', 'state_token' );

		self::assertSame( SeoAbilityCategory::SLUG, $args['category'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'id' ), array_keys( $input['properties'] ) );
		self::assertSame( 1, $input['properties']['id']['minimum'] );
		self::assertSame( array( 'id' ), $input['required'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( $fields, array_keys( $output['properties'] ) );
		self::assertSame( $fields, $output['required'] );
		self::assertSame( 500, $output['properties']['title']['maxLength'] );
		self::assertSame( 2000, $output['properties']['description']['maxLength'] );
		self::assertSame( 2048, $output['properties']['canonical_url']['maxLength'] );
		self::assertSame( 5, $output['properties']['focus_keywords']['maxItems'] );
		self::assertTrue( $output['properties']['focus_keywords']['uniqueItems'] );
		self::assertFalse( $output['properties']['robots']['additionalProperties'] );
		self::assertSame( array( 'index', 'follow' ), $output['properties']['robots']['required'] );
		self::assertSame( '^[0-9a-f]{64}$', $output['properties']['state_token']['pattern'] );
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/** Verify absent providers remain a semantic tool response for read-capable users. */
	public function test_permission_allows_read_identity_to_receive_provider_unavailable(): void {
		$ability = new SeoGetAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
		$result = $ability->execute( array( 'id' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_seo_provider_unavailable', $result->get_error_code() );
	}
}
