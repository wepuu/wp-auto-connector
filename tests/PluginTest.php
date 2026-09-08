<?php
/**
 * Main plugin boot tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\PageUpdateAbility;
use WPAuto\Connector\Abilities\Content\PostUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaGetAbility;
use WPAuto\Connector\Abilities\Media\MediaSearchAbility;
use WPAuto\Connector\Abilities\Media\MediaUploadAbility;
use WPAuto\Connector\Abilities\Media\MediaUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaSetFeaturedAbility;
use WPAuto\Connector\Plugin;

/** Covers production registration of the Phase 1.4.1 abilities. */
final class PluginTest extends TestCase {
	/** The boot path registers Update and Media read abilities. */
	public function test_boot_registers_current_abilities(): void {
		$GLOBALS['wp_auto_test_hook_history'] = array();

		Plugin::instance()->boot();

		$callbacks = $GLOBALS['wp_auto_test_hook_history']['wp_abilities_api_init'];
		$classes   = array_map(
			static fn( array $callback ): string => get_class( $callback[0] ),
			$callbacks
		);

		self::assertContains( PostUpdateAbility::class, $classes );
		self::assertContains( PageUpdateAbility::class, $classes );
		self::assertContains( MediaSearchAbility::class, $classes );
		self::assertContains( MediaGetAbility::class, $classes );
		self::assertContains( MediaUploadAbility::class, $classes );
		self::assertContains( MediaUpdateAbility::class, $classes );
		self::assertContains( MediaSetFeaturedAbility::class, $classes );
		self::assertCount( 17, $callbacks );
	}
}
