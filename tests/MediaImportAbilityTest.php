<?php
/**
 * Remote media import Ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaImportUrlAbility;
use WPAuto\Connector\Media\MediaImportContract;

/** Covers registration, schema, annotations, and permission. */
final class MediaImportAbilityTest extends TestCase {
	/** Reset shared Ability state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact Import URL contract and annotations. */
	public function test_registers_the_frozen_import_contract(): void {
		$ability = new MediaImportUrlAbility();
		$ability->register();
		$ability->register_ability();
		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];

		self::assertSame( 'wp-auto/media-import-url', MediaImportUrlAbility::NAME );
		self::assertSame( MediaImportUrlAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertSame( MediaImportContract::input_schema(), $args['input_schema'] );
		self::assertSame( array( 'url', 'filename', 'idempotency_key' ), $args['input_schema']['required'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( MediaImportContract::output_schema(), $args['output_schema'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
	}

	/** Verify the Ability requires upload_files. */
	public function test_permission_requires_upload_files(): void {
		self::assertFalse( ( new MediaImportUrlAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaImportUrlAbility() )->check_permission() );
	}
}
