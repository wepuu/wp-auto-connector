<?php
/**
 * Categories-list ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Taxonomy\CategoriesListAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAbilityCategory;

/**
 * Covers registration, schema, annotations, and entry permission.
 */
final class CategoriesListAbilityTest extends TestCase {
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
		$ability = new CategoriesListAbility();
		$ability->register();

		self::assertSame( 'wp-auto/categories-list', CategoriesListAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the frozen strict schemas and semantic annotations.
	 */
	public function test_registration_has_the_frozen_contract(): void {
		( new CategoriesListAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];
		$properties   = $input['properties'];

		self::assertSame( CategoriesListAbility::NAME, $registration['name'] );
		self::assertSame( TaxonomyAbilityCategory::SLUG, $args['category'] );
		self::assertSame( 'object', $input['type'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'search', 'page', 'per_page', 'orderby', 'order', 'hide_empty' ), array_keys( $properties ) );
		self::assertSame( array( 'string', 'integer', 'integer', 'string', 'string', 'boolean' ), array_column( $properties, 'type' ) );
		self::assertArrayNotHasKey( 'required', $input );
		self::assertSame( '', $properties['search']['default'] );
		self::assertSame( 200, $properties['search']['maxLength'] );
		self::assertSame( 1, $properties['page']['default'] );
		self::assertSame( 1, $properties['page']['minimum'] );
		self::assertSame( 20, $properties['per_page']['default'] );
		self::assertSame( 1, $properties['per_page']['minimum'] );
		self::assertSame( 50, $properties['per_page']['maximum'] );
		self::assertSame( 'name', $properties['orderby']['default'] );
		self::assertSame( array( 'name', 'count', 'id', 'slug' ), $properties['orderby']['enum'] );
		self::assertSame( 'asc', $properties['order']['default'] );
		self::assertSame( array( 'asc', 'desc' ), $properties['order']['enum'] );
		self::assertFalse( $properties['hide_empty']['default'] );
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), array_keys( $output['properties'] ) );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), $output['required'] );
		self::assertFalse( $output['properties']['items']['items']['additionalProperties'] );
		self::assertSame(
			array( 'id', 'name', 'slug', 'description', 'count', 'parent_id' ),
			array_keys( $output['properties']['items']['items']['properties'] )
		);
		self::assertSame(
			array( 'id', 'name', 'slug', 'description', 'count', 'parent_id' ),
			$output['properties']['items']['items']['required']
		);
		self::assertSame(
			array( 'integer', 'string', 'string', 'string', 'integer', 'integer' ),
			array_column( $output['properties']['items']['items']['properties'], 'type' )
		);
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/**
	 * Verify the ability-entry permission is the narrow read primitive.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new CategoriesListAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
