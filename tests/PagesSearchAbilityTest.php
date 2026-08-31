<?php
/**
 * Pages-search ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PagesSearchAbility;

/**
 * Covers registration, schema, annotations, and entry permission.
 */
final class PagesSearchAbilityTest extends TestCase {
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
		$ability = new PagesSearchAbility();
		$ability->register();

		self::assertSame( 'wp-auto/pages-search', PagesSearchAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the frozen strict schemas and semantic annotations.
	 */
	public function test_registration_has_the_frozen_contract(): void {
		( new PagesSearchAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];
		$properties   = $input['properties'];

		self::assertSame( PagesSearchAbility::NAME, $registration['name'] );
		self::assertSame( ContentAbilityCategory::SLUG, $args['category'] );
		self::assertSame( 'object', $input['type'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'search', 'status', 'page', 'per_page', 'orderby', 'order' ), array_keys( $properties ) );
		self::assertSame( array( 'string', 'string', 'integer', 'integer', 'string', 'string' ), array_column( $properties, 'type' ) );
		self::assertArrayNotHasKey( 'required', $input );
		self::assertSame( '', $properties['search']['default'] );
		self::assertSame( 200, $properties['search']['maxLength'] );
		self::assertSame( 'publish', $properties['status']['default'] );
		self::assertSame( array( 'publish', 'draft', 'pending', 'private', 'future' ), $properties['status']['enum'] );
		self::assertSame( 1, $properties['page']['minimum'] );
		self::assertSame( 1, $properties['page']['default'] );
		self::assertSame( array( 1, 50, 10 ), array( $properties['per_page']['minimum'], $properties['per_page']['maximum'], $properties['per_page']['default'] ) );
		self::assertSame( array( 'date', 'modified', 'title', 'id' ), $properties['orderby']['enum'] );
		self::assertSame( 'modified', $properties['orderby']['default'] );
		self::assertSame( array( 'asc', 'desc' ), $properties['order']['enum'] );
		self::assertSame( 'desc', $properties['order']['default'] );
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), array_keys( $output['properties'] ) );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), $output['required'] );
		self::assertSame(
			array( 'id', 'title', 'slug', 'status', 'link', 'author_id', 'date_gmt', 'modified_gmt' ),
			array_keys( $output['properties']['items']['items']['properties'] )
		);
		self::assertSame(
			array( 'id', 'title', 'slug', 'status', 'link', 'author_id', 'date_gmt', 'modified_gmt' ),
			$output['properties']['items']['items']['required']
		);
		self::assertSame(
			array( 'integer', 'string', 'string', 'string', 'string', 'integer', 'string', 'string' ),
			array_column( $output['properties']['items']['items']['properties'], 'type' )
		);
		self::assertFalse( $output['properties']['items']['items']['additionalProperties'] );
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/**
	 * Verify the ability-entry check requires read.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new PagesSearchAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
