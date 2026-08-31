<?php
/**
 * Page-get ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PageGetAbility;

/**
 * Covers registration, schema, annotations, and entry permission.
 */
final class PageGetAbilityTest extends TestCase {
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
		$ability = new PageGetAbility();
		$ability->register();

		self::assertSame( 'wp-auto/page-get', PageGetAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the exact frozen input and output schemas.
	 */
	public function test_registration_has_the_frozen_contract(): void {
		( new PageGetAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];
		$fields       = array(
			'id',
			'type',
			'status',
			'slug',
			'title',
			'excerpt',
			'content',
			'link',
			'author_id',
			'date_gmt',
			'modified_gmt',
			'featured_media_id',
			'parent_id',
		);

		self::assertSame( PageGetAbility::NAME, $registration['name'] );
		self::assertSame( ContentAbilityCategory::SLUG, $args['category'] );
		self::assertSame( 'object', $input['type'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'id' ), array_keys( $input['properties'] ) );
		self::assertSame( 'integer', $input['properties']['id']['type'] );
		self::assertSame( 1, $input['properties']['id']['minimum'] );
		self::assertSame( array( 'id' ), $input['required'] );
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( $fields, array_keys( $output['properties'] ) );
		self::assertSame( $fields, $output['required'] );
		self::assertSame(
			array( 'integer', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'integer', 'string', 'string', 'integer', 'integer' ),
			array_column( $output['properties'], 'type' )
		);
		self::assertSame( array( 'page' ), $output['properties']['type']['enum'] );
		self::assertSame( 'integer', $output['properties']['parent_id']['type'] );
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/**
	 * Verify the ability-entry check requires read.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new PageGetAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
