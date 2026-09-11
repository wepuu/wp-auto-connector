<?php
/**
 * SEO Update Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Seo\SeoAbilityCategory;
use WPAuto\Connector\Abilities\Seo\SeoUpdateAbility;

/** Covers registration and exact Phase 1.6.2 annotations/schema. */
final class SeoUpdateAbilityTest extends TestCase {
	/** Reset registration fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** The update ability is registered at the canonical hook. */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new SeoUpdateAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/** The public schema and annotations are provider neutral and strictly bounded. */
	public function test_registration_has_exact_update_contract(): void {
		( new SeoUpdateAbility() )->register_ability();
		$args = $GLOBALS['wp_auto_test_registered_ability']['args'];
		self::assertSame( SeoAbilityCategory::SLUG, $args['category'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( array( 'id', 'expected_state_token' ), $args['input_schema']['required'] );
		self::assertSame( '^[0-9a-f]{64}$', $args['input_schema']['properties']['expected_state_token']['pattern'] );
		self::assertFalse( $args['output_schema']['additionalProperties'] );
		self::assertSame( array( 'title', 'description', 'canonical_url', 'focus_keywords', 'robots' ), $args['output_schema']['properties']['changed_fields']['items']['enum'] );
		self::assertFalse( $args['meta']['annotations']['readonly'] );
		self::assertTrue( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}
}
