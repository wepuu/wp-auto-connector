<?php
/**
 * Update Draft shared contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Content\UpdateDraftContract;

/** Covers the frozen shared Update schemas. */
final class UpdateDraftContractTest extends TestCase {
	/** The exact strict input shape and bounds remain frozen. */
	public function test_input_schema_is_strict_and_exact(): void {
		$schema = UpdateDraftContract::input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'id', 'expected_modified_gmt' ), $schema['required'] );
		self::assertSame( array( 'id', 'expected_modified_gmt', 'title', 'content', 'excerpt', 'slug' ), array_keys( $schema['properties'] ) );
		self::assertSame(
			array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			$schema['properties']['id']
		);
		self::assertSame( 19, $schema['properties']['expected_modified_gmt']['minLength'] );
		self::assertSame( 19, $schema['properties']['expected_modified_gmt']['maxLength'] );
		self::assertSame( '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$', $schema['properties']['expected_modified_gmt']['pattern'] );
		self::assertSame( 500, $schema['properties']['title']['maxLength'] );
		self::assertSame( 1000000, $schema['properties']['content']['maxLength'] );
		self::assertSame( 50000, $schema['properties']['excerpt']['maxLength'] );
		self::assertSame( 1, $schema['properties']['slug']['minLength'] );
		self::assertSame( 200, $schema['properties']['slug']['maxLength'] );
	}

	/** The exact seven-field output is fixed per post type. */
	public function test_output_schema_is_strict_and_type_specific(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$schema = UpdateDraftContract::output_schema( $post_type );
			self::assertFalse( $schema['additionalProperties'] );
			self::assertSame( array( 'id', 'type', 'status', 'slug', 'link', 'edit_url', 'modified_gmt' ), array_keys( $schema['properties'] ) );
			self::assertSame( array_keys( $schema['properties'] ), $schema['required'] );
			self::assertSame( array( $post_type ), $schema['properties']['type']['enum'] );
			self::assertSame( array( 'draft' ), $schema['properties']['status']['enum'] );
		}
	}
}
