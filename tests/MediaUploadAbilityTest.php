<?php
/**
 * Media Upload Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaUploadAbility;
use WPAuto\Connector\Media\MediaUploadContract;

/** Covers registration, strict schemas, annotations, and entry permission. */
final class MediaUploadAbilityTest extends TestCase {
	/** Reset shared Ability state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact frozen Upload contract. */
	public function test_registers_the_frozen_upload_contract(): void {
		$ability = new MediaUploadAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );

		$ability->register_ability();
		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];

		self::assertSame( 'wp-auto/media-upload', MediaUploadAbility::NAME );
		self::assertSame( MediaUploadAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertSame( MediaUploadContract::input_schema(), $input );
		self::assertSame( array( 'filename', 'content_base64', 'idempotency_key' ), $input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( MediaUploadContract::output_schema(), $output );
		self::assertSame( array_keys( $output['properties'] ), $output['required'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
	}

	/** Verify the Ability requires upload permission. */
	public function test_permission_requires_upload_files(): void {
		self::assertFalse( ( new MediaUploadAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaUploadAbility() )->check_permission() );
	}
}
