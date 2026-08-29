<?php
/**
 * Content ability category tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;

/**
 * Covers the WordPress 6.9 category prerequisite.
 */
final class ContentAbilityCategoryTest extends TestCase {
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
		$category = new ContentAbilityCategory();
		$category->register();

		self::assertArrayHasKey( 'wp_abilities_api_categories_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $category, 'register_category' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_categories_init'] );
	}

	/**
	 * Verify the collision-resistant category contract.
	 */
	public function test_registers_the_wp_auto_content_category(): void {
		( new ContentAbilityCategory() )->register_category();

		self::assertSame( 'wp-auto-content', ContentAbilityCategory::SLUG );
		self::assertSame( ContentAbilityCategory::SLUG, $GLOBALS['wp_auto_test_registered_category']['slug'] );
		self::assertSame( 'WP-Auto Content', $GLOBALS['wp_auto_test_registered_category']['args']['label'] );
		self::assertNotSame( '', $GLOBALS['wp_auto_test_registered_category']['args']['description'] );
	}
}
