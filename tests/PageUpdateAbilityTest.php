<?php
/**
 * Page Update ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PageUpdateAbility;

/** Covers the Page Update public contract. */
final class PageUpdateAbilityTest extends TestCase {
	/** Reset ability fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array( 'edit_pages' => true );
		$GLOBALS['wp_auto_test_current_user_id']    = 7;
	}

	/** The ability registers the frozen contract and annotations. */
	public function test_registers_the_frozen_page_update_contract(): void {
		$ability = new PageUpdateAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );

		$ability->register_ability();
		$args = $GLOBALS['wp_auto_test_registered_ability']['args'];
		self::assertSame( PageUpdateAbility::NAME, $GLOBALS['wp_auto_test_registered_ability']['name'] );
		self::assertSame( ContentAbilityCategory::SLUG, $args['category'] );
		self::assertSame( array( 'id', 'expected_modified_gmt' ), $args['input_schema']['required'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( array( 'page' ), $args['output_schema']['properties']['type']['enum'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			$args['meta']['annotations']
		);
	}

	/** Permission uses the fixed Page type edit baseline. */
	public function test_permission_uses_page_type_edit_baseline(): void {
		self::assertTrue( ( new PageUpdateAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['edit_pages'] = false;
		self::assertFalse( ( new PageUpdateAbility() )->check_permission() );
	}
}
