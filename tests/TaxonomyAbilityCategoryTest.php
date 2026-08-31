<?php
/**
 * Taxonomy ability category tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAbilityCategory;

/**
 * Covers the WordPress 6.9 taxonomy category prerequisite.
 */
final class TaxonomyAbilityCategoryTest extends TestCase {
	/**
	 * Reset shared WordPress test state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']               = array();
		$GLOBALS['wp_auto_test_registered_category'] = null;
	}

	/**
	 * Verify registration uses the category-specific Core hook.
	 */
	public function test_registers_on_the_abilities_category_hook(): void {
		$category = new TaxonomyAbilityCategory();
		$category->register();

		self::assertArrayHasKey( 'wp_abilities_api_categories_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $category, 'register_category' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_categories_init'] );
	}

	/**
	 * Verify the collision-resistant category contract.
	 */
	public function test_registers_the_wp_auto_taxonomy_category(): void {
		( new TaxonomyAbilityCategory() )->register_category();

		self::assertSame( 'wp-auto-taxonomy', TaxonomyAbilityCategory::SLUG );
		self::assertSame( TaxonomyAbilityCategory::SLUG, $GLOBALS['wp_auto_test_registered_category']['slug'] );
		self::assertSame( 'WP-Auto Taxonomy', $GLOBALS['wp_auto_test_registered_category']['args']['label'] );
		self::assertNotSame( '', $GLOBALS['wp_auto_test_registered_category']['args']['description'] );
	}
}
