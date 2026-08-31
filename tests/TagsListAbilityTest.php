<?php
/**
 * Tags-list ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Taxonomy\TagsListAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAbilityCategory;

/**
 * Covers registration, schema, annotations, and entry permission.
 */
final class TagsListAbilityTest extends TestCase {
	/**
	 * Reset shared WordPress test state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/**
	 * Verify canonical registration timing.
	 */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new TagsListAbility();
		$ability->register();

		self::assertSame( 'wp-auto/tags-list', TagsListAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify Tags share the strict input contract but omit category parent data.
	 */
	public function test_registration_has_the_frozen_contract(): void {
		( new TagsListAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output_item  = $args['output_schema']['properties']['items']['items'];

		self::assertSame( TagsListAbility::NAME, $registration['name'] );
		self::assertSame( TaxonomyAbilityCategory::SLUG, $args['category'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'search', 'page', 'per_page', 'orderby', 'order', 'hide_empty' ), array_keys( $input['properties'] ) );
		self::assertSame( 20, $input['properties']['per_page']['default'] );
		self::assertSame( 50, $input['properties']['per_page']['maximum'] );
		self::assertSame( array( 'name', 'count', 'id', 'slug' ), $input['properties']['orderby']['enum'] );
		self::assertSame( array( 'asc', 'desc' ), $input['properties']['order']['enum'] );
		self::assertSame( 'boolean', $input['properties']['hide_empty']['type'] );
		self::assertFalse( $output_item['additionalProperties'] );
		self::assertSame( array( 'id', 'name', 'slug', 'description', 'count' ), $output_item['required'] );
		self::assertArrayNotHasKey( 'parent_id', $output_item['properties'] );
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/**
	 * Verify the ability-entry permission is the narrow read primitive.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new TagsListAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
