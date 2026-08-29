<?php
/**
 * Posts-search ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PostsSearchAbility;

/**
 * Covers registration, schema, annotations, and entry permission.
 */
final class PostsSearchAbilityTest extends TestCase {
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
		$ability = new PostsSearchAbility();
		$ability->register();

		self::assertSame( 'wp-auto/posts-search', PostsSearchAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the frozen strict schemas and semantic annotations.
	 */
	public function test_registration_has_the_frozen_contract(): void {
		( new PostsSearchAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];
		$properties   = $input['properties'];

		self::assertSame( PostsSearchAbility::NAME, $registration['name'] );
		self::assertSame( ContentAbilityCategory::SLUG, $args['category'] );
		self::assertSame( 'object', $input['type'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'search', 'status', 'page', 'per_page', 'orderby', 'order' ), array_keys( $properties ) );
		self::assertSame( array( 'string', 'string', 'integer', 'integer', 'string', 'string' ), array_column( $properties, 'type' ) );
		self::assertSame( '', $properties['search']['default'] );
		self::assertSame( 200, $properties['search']['maxLength'] );
		self::assertSame( 'publish', $properties['status']['default'] );
		self::assertSame( array( 'publish', 'draft', 'pending', 'private', 'future' ), $properties['status']['enum'] );
		self::assertSame( 1, $properties['page']['minimum'] );
		self::assertSame( 1, $properties['page']['default'] );
		self::assertSame( 1, $properties['per_page']['minimum'] );
		self::assertSame( 50, $properties['per_page']['maximum'] );
		self::assertSame( 10, $properties['per_page']['default'] );
		self::assertSame( array( 'date', 'modified', 'title', 'id' ), $properties['orderby']['enum'] );
		self::assertSame( 'modified', $properties['orderby']['default'] );
		self::assertSame( array( 'asc', 'desc' ), $properties['order']['enum'] );
		self::assertSame( 'desc', $properties['order']['default'] );
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), $output['required'] );
		self::assertFalse( $output['properties']['items']['items']['additionalProperties'] );
		self::assertSame(
			array( 'id', 'title', 'slug', 'status', 'link', 'author_id', 'date_gmt', 'modified_gmt' ),
			$output['properties']['items']['items']['required']
		);
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/**
	 * Verify the ability-entry check is distinct from object authorization.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new PostsSearchAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
