<?php
/**
 * Page Create Draft ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PageCreateDraftAbility;

/**
 * Covers the Page Create Draft public contract.
 */
final class PageCreateDraftAbilityTest extends TestCase {
	/**
	 * Reset ability fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array( 'edit_pages' => true );
		$GLOBALS['wp_auto_test_current_user_id']    = 7;
	}

	/**
	 * The Page Create Draft contract is registered exactly.
	 */
	public function test_registers_the_frozen_page_create_contract(): void {
		$ability = new PageCreateDraftAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );

		$ability->register_ability();
		$args = $GLOBALS['wp_auto_test_registered_ability']['args'];
		self::assertSame( PageCreateDraftAbility::NAME, $GLOBALS['wp_auto_test_registered_ability']['name'] );
		self::assertSame( ContentAbilityCategory::SLUG, $args['category'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( 'page', $args['output_schema']['properties']['type']['enum'][0] );
		self::assertSame( array_keys( $args['output_schema']['properties'] ), $args['output_schema']['required'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
	}

	/**
	 * Page permission uses the post type create capability.
	 */
	public function test_permission_uses_page_type_create_capability(): void {
		self::assertTrue( ( new PageCreateDraftAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['edit_pages'] = false;
		self::assertFalse( ( new PageCreateDraftAbility() )->check_permission() );
	}
}
