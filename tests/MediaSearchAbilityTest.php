<?php
/**
 * Media Search Ability contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaSearchAbility;
use WPAuto\Connector\Media\MediaReadContract;

/** Covers registration, strict schemas, annotations, and entry permission. */
final class MediaSearchAbilityTest extends TestCase {
	/** Reset shared Ability state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_capabilities']       = array();
	}

	/** Verify the exact frozen Search contract. */
	public function test_registers_the_frozen_search_contract(): void {
		$ability = new MediaSearchAbility();
		$ability->register();
		self::assertSame( array( $ability, 'register_ability' ), $GLOBALS['wp_auto_test_hooks']['wp_abilities_api_init'] );

		$ability->register_ability();
		$registration = $GLOBALS['wp_auto_test_registered_ability'];
		$args         = $registration['args'];
		$input        = $args['input_schema'];
		$output       = $args['output_schema'];

		self::assertSame( 'wp-auto/media-search', MediaSearchAbility::NAME );
		self::assertSame( MediaSearchAbility::NAME, $registration['name'] );
		self::assertSame( MediaAbilityCategory::SLUG, $args['category'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'search', 'mime_type', 'page', 'per_page', 'orderby', 'order' ), array_keys( $input['properties'] ) );
		self::assertSame( array_merge( array( 'all' ), MediaReadContract::MIME_TYPES ), $input['properties']['mime_type']['enum'] );
		self::assertSame( 50, $input['properties']['per_page']['maximum'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( array( 'items', 'page', 'per_page', 'returned', 'has_more' ), $output['required'] );
		self::assertSame( array_keys( MediaReadContract::item_properties() ), $output['properties']['items']['items']['required'] );
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
		self::assertFalse( ( new MediaSearchAbility() )->check_permission() );
		$GLOBALS['wp_auto_test_capabilities']['upload_files'] = true;
		self::assertTrue( ( new MediaSearchAbility() )->check_permission() );
	}
}
