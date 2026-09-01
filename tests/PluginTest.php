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
use WPAuto\Connector\Plugin;

/** Covers production registration of the Phase 1.3.2 abilities. */
final class PluginTest extends TestCase {
	/** The boot path registers both Update abilities. */
	public function test_boot_registers_both_update_abilities(): void {
		$GLOBALS['wp_auto_test_hook_history'] = array();

		Plugin::instance()->boot();

		$callbacks = $GLOBALS['wp_auto_test_hook_history']['wp_abilities_api_init'];
		$classes   = array_map(
			static fn( array $callback ): string => get_class( $callback[0] ),
			$callbacks
		);

		self::assertContains( PostUpdateAbility::class, $classes );
		self::assertContains( PageUpdateAbility::class, $classes );
		self::assertCount( 12, $callbacks );
	}
}
