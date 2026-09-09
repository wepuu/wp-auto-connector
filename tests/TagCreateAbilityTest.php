<?php
/**
 * Tag Create ability registration tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Taxonomy\TagCreateAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAbilityCategory;

/** Covers Tag Create registration and capability boundaries. */
final class TagCreateAbilityTest extends TestCase {
	/** Reset shared ability fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']                  = array();
		$GLOBALS['wp_auto_test_registered_ability']     = null;
		$GLOBALS['wp_auto_test_capabilities']           = array();
		$GLOBALS['wp_auto_test_current_user_id']        = 7;
		$GLOBALS['wp_auto_test_taxonomies']['post_tag'] = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
	}

	/** Registration is deferred until the Abilities API hook. */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new TagCreateAbility();
		$ability->register();

		self::assertSame( 'wp-auto/tag-create', TagCreateAbility::NAME );
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/** Registration carries the exact mutation contract and annotations. */
	public function test_registration_has_tag_contract_and_mutation_annotations(): void {
		( new TagCreateAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];

		self::assertSame( TagCreateAbility::NAME, $registration['name'] );
		self::assertSame( TaxonomyAbilityCategory::SLUG, $args['category'] );
		self::assertSame( array( 'name', 'idempotency_key', 'slug', 'description' ), array_keys( $args['input_schema']['properties'] ) );
		self::assertFalse( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
		self::assertIsCallable( $args['execute_callback'] );
		self::assertIsCallable( $args['permission_callback'] );
	}

	/** Permission uses the built-in Tag taxonomy's actual manage_terms capability. */
	public function test_permission_requires_manage_terms(): void {
		$ability = new TagCreateAbility();

		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['manage_categories'] = true;
		self::assertTrue( $ability->check_permission() );

		$GLOBALS['wp_auto_test_taxonomies']['post_tag']->cap->manage_terms = 'manage_custom_tags';
		self::assertFalse( $ability->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['manage_custom_tags'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
