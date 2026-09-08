<?php
/**
 * Media Metadata Update Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaUpdateAbility;
use WPAuto\Connector\Media\MediaUpdateContract;

/** Covers registration, exact schemas, annotations, and entry permission. */
final class MediaUpdateAbilityTest extends TestCase {
	/** Reset shared Ability fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact frozen metadata update contract. */
	public function test_registers_the_frozen_update_contract(): void {
		$ability = new MediaUpdateAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
		$ability->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		self::assertSame( 'wp-auto/media-update', MediaUpdateAbility::NAME );
		self::assertSame( MediaUpdateAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertSame( MediaUpdateContract::input_schema(), $input );
		self::assertSame( array( 'id', 'expected_modified_gmt' ), $input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( MediaUpdateContract::output_schema(), $args['output_schema'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			$args['meta']['annotations']
		);
	}

	/** Verify the Ability requires the upload baseline. */
	public function test_permission_requires_upload_files(): void {
		self::assertFalse( ( new MediaUpdateAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaUpdateAbility() )->check_permission() );
	}
}
