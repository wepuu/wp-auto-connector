<?php
/**
 * Site-health ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Site\SiteHealthAbility;

/**
 * Covers ability registration, permissions, and output safety.
 */
final class SiteHealthAbilityTest extends TestCase {
	/**
	 * Reset WordPress stub state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_can_read']           = false;
		$GLOBALS['wp_auto_test_is_ssl']             = true;
	}

	/**
	 * Verify registration timing.
	 */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new SiteHealthAbility();
		$ability->register();

		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the public schema and annotations.
	 */
	public function test_registration_has_a_strict_read_only_contract(): void {
		$ability = new SiteHealthAbility();
		$ability->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];

		self::assertSame( SiteHealthAbility::NAME, $registration['name'] );
		self::assertSame( 'site', $args['category'] );
		self::assertFalse( $args['output_schema']['additionalProperties'] );
		self::assertSame( array_keys( $args['output_schema']['properties'] ), $args['output_schema']['required'] );
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
		self::assertArrayNotHasKey( 'public', $args['meta'] );
		self::assertArrayNotHasKey( 'mcp', $args['meta'] );
	}

	/**
	 * Verify permission denial and success.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new SiteHealthAbility();

		self::assertFalse( $ability->check_permission() );

		$GLOBALS['wp_auto_test_can_read'] = true;
		self::assertTrue( $ability->check_permission() );
	}

	/**
	 * Verify that no sensitive or unrelated fields are returned.
	 */
	public function test_output_contains_only_the_safe_diagnostic_shape(): void {
		$result = ( new SiteHealthAbility() )->execute();

		self::assertSame(
			array(
				'wordpress_version',
				'php_version',
				'connector_version',
				'abilities_api_available',
				'mcp_adapter_available',
				'mcp_adapter_version',
				'rest_api_available',
				'https',
			),
			array_keys( $result )
		);
		self::assertSame( '6.9-test', $result['wordpress_version'] );
		self::assertSame( '0.6.1', $result['mcp_adapter_version'] );
		self::assertTrue( $result['abilities_api_available'] );
		self::assertTrue( $result['rest_api_available'] );
		self::assertTrue( $result['https'] );
	}
}
