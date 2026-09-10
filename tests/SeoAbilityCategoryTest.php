<?php
/**
 * SEO ability category tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Seo\SeoAbilityCategory;

/** Covers the provider-neutral SEO category. */
final class SeoAbilityCategoryTest extends TestCase {
	/** Reset shared WordPress test state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']               = array();
		$GLOBALS['wp_auto_test_registered_category'] = null;
	}

	/** Verify registration uses the category-specific Core hook. */
	public function test_registers_on_the_abilities_category_hook(): void {
		$category = new SeoAbilityCategory();
		$category->register();

		self::assertSame( array( $category, 'register_category' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_categories_init'] );
	}

	/** Verify the collision-resistant category contract. */
	public function test_registers_the_wp_auto_seo_category(): void {
		( new SeoAbilityCategory() )->register_category();

		self::assertSame( 'wp-auto-seo', SeoAbilityCategory::SLUG );
		self::assertSame( SeoAbilityCategory::SLUG, $GLOBALS['wp_auto_test_registered_category']['slug'] );
		self::assertSame( 'WP-Auto SEO', $GLOBALS['wp_auto_test_registered_category']['args']['label'] );
		self::assertNotSame( '', $GLOBALS['wp_auto_test_registered_category']['args']['description'] );
	}
}
