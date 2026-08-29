<?php
/**
 * Site-info ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Site\SiteInfoAbility;

/**
 * Covers the frozen site-info schema, permissions, and safe output.
 */
final class SiteInfoAbilityTest extends TestCase {
	/**
	 * Reset WordPress stub state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_can_read']           = false;
		$GLOBALS['wp_auto_test_site_info']          = array(
			'name'                => 'WP-Auto Test Site',
			'description'         => 'A safe connector test site.',
			'site_url'            => 'https://example.test/wordpress',
			'home_url'            => 'https://example.test',
			'language'            => 'en-US',
			'timezone'            => 'Asia/Shanghai',
			'permalink_structure' => '/%postname%/',
			'multisite'           => false,
		);
	}

	/**
	 * Verify canonical registration timing.
	 */
	public function test_registers_on_the_abilities_api_hook(): void {
		$ability = new SiteInfoAbility();
		$ability->register();

		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
	}

	/**
	 * Verify the exact strict schemas and read-only annotations.
	 */
	public function test_registration_has_the_frozen_strict_contract(): void {
		( new SiteInfoAbility() )->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$fields       = array(
			'site_name',
			'site_description',
			'site_url',
			'home_url',
			'language',
			'timezone',
			'permalink_structure',
			'multisite',
		);

		self::assertSame( SiteInfoAbility::NAME, $registration['name'] );
		self::assertSame( 'object', $args['input_schema']['type'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( array(), $args['input_schema']['properties'] );
		self::assertSame( 'object', $args['output_schema']['type'] );
		self::assertFalse( $args['output_schema']['additionalProperties'] );
		self::assertSame( $fields, array_keys( $args['output_schema']['properties'] ) );
		self::assertSame( $fields, $args['output_schema']['required'] );
		self::assertSame(
			array(
				'site_name'           => 'string',
				'site_description'    => 'string',
				'site_url'            => 'string',
				'home_url'            => 'string',
				'language'            => 'string',
				'timezone'            => 'string',
				'permalink_structure' => 'string',
				'multisite'           => 'boolean',
			),
			array_map(
				static fn( array $property ): string => $property['type'],
				$args['output_schema']['properties']
			)
		);
		self::assertTrue( $args['meta']['annotations']['readonly'] );
		self::assertFalse( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
		self::assertArrayNotHasKey( 'public', $args['meta'] );
		self::assertArrayNotHasKey( 'mcp', $args['meta'] );
	}

	/**
	 * Verify exact Core-derived values, including a non-UTC timezone.
	 */
	public function test_output_contains_exactly_the_safe_site_fields(): void {
		$result = ( new SiteInfoAbility() )->execute();

		self::assertSame(
			array(
				'site_name',
				'site_description',
				'site_url',
				'home_url',
				'language',
				'timezone',
				'permalink_structure',
				'multisite',
			),
			array_keys( $result )
		);
		self::assertSame( 'WP-Auto Test Site', $result['site_name'] );
		self::assertSame( 'A safe connector test site.', $result['site_description'] );
		self::assertSame( 'https://example.test/wordpress', $result['site_url'] );
		self::assertSame( 'https://example.test', $result['home_url'] );
		self::assertSame( 'en-US', $result['language'] );
		self::assertSame( 'Asia/Shanghai', $result['timezone'] );
		self::assertSame( '/%postname%/', $result['permalink_structure'] );
		self::assertFalse( $result['multisite'] );
		self::assertArrayNotHasKey( 'admin_email', $result );
		self::assertArrayNotHasKey( 'users', $result );
		self::assertArrayNotHasKey( 'plugins', $result );
		self::assertArrayNotHasKey( 'ABSPATH', $result );
	}

	/**
	 * Verify permission denial and success independently of transport.
	 */
	public function test_permission_callback_requires_read_capability(): void {
		$ability = new SiteInfoAbility();

		self::assertFalse( $ability->check_permission() );

		$GLOBALS['wp_auto_test_can_read'] = true;
		self::assertTrue( $ability->check_permission() );
	}
}
