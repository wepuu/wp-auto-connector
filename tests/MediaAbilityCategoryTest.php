<?php
/**
 * Media Ability category tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;

/** Covers the WordPress 6.9 Media category prerequisite. */
final class MediaAbilityCategoryTest extends TestCase {
	/** Reset shared registration state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']               = array();
		$GLOBALS['wp_auto_test_registered_category'] = null;
	}

	/** Verify the frozen Media category registration. */
	public function test_registers_the_frozen_media_category(): void {
		$category = new MediaAbilityCategory();
		$category->register();

		self::assertSame( array( $category, 'register_category' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_categories_init'] );
		$category->register_category();
		self::assertSame( 'wp-auto-media', MediaAbilityCategory::SLUG );
		self::assertSame( MediaAbilityCategory::SLUG, $GLOBALS['wp_auto_test_registered_category']['slug'] );
		self::assertSame( 'WP-Auto Media', $GLOBALS['wp_auto_test_registered_category']['args']['label'] );
		self::assertNotSame( '', $GLOBALS['wp_auto_test_registered_category']['args']['description'] );
	}
}
