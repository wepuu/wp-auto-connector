<?php
/**
 * Media Get Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaGetAbility;
use WPAuto\Connector\Media\MediaReadContract;

/** Covers registration, strict schemas, annotations, and entry permission. */
final class MediaGetAbilityTest extends TestCase {
	/** Reset shared Ability state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact frozen Get contract. */
	public function test_registers_the_frozen_get_contract(): void {
		$ability = new MediaGetAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );

		$ability->register_ability();
		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];

		self::assertSame( 'wp-auto/media-get', MediaGetAbility::NAME );
		self::assertSame( MediaGetAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertSame( array( 'id' ), $input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array_keys( $output['properties'] ), $output['required'] );
		self::assertSame( array_keys( MediaReadContract::item_properties() ), array_slice( $output['required'], 0, 8 ) );
		self::assertSame( array( 'alt_text', 'caption', 'description', 'width', 'height' ), array_slice( $output['required'], 8 ) );
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
	}

	/** Verify the Ability requires upload permission. */
	public function test_permission_requires_upload_files(): void {
		self::assertFalse( ( new MediaGetAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaGetAbility() )->check_permission() );
	}
}
