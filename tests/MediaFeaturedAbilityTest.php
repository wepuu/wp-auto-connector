<?php
/**
 * Featured Image Assignment Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaSetFeaturedAbility;
use WPAuto\Connector\Media\MediaFeaturedContract;

/** Covers registration, exact schemas, annotations, and entry permission. */
final class MediaFeaturedAbilityTest extends TestCase {
	/** Reset shared Ability fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact frozen featured-image contract. */
	public function test_registers_the_frozen_featured_contract(): void {
		$ability = new MediaSetFeaturedAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );
		$ability->register_ability();

		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		self::assertSame( 'wp-auto/media-set-featured', MediaSetFeaturedAbility::NAME );
		self::assertSame( MediaSetFeaturedAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertSame( MediaFeaturedContract::input_schema(), $args['input_schema'] );
		self::assertSame( array( 'target_id', 'media_id', 'expected_featured_media_id' ), $args['input_schema']['required'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertSame( MediaFeaturedContract::output_schema(), $args['output_schema'] );
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
	}

	/** Verify the Ability requires the upload baseline. */
	public function test_permission_requires_upload_files(): void {
		self::assertFalse( ( new MediaSetFeaturedAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaSetFeaturedAbility() )->check_permission() );
	}
}
