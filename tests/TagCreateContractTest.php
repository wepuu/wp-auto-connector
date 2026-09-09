<?php
/**
 * Tag Create schema contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TagCreateContract;

/** Covers the strict public Tag Create schemas. */
final class TagCreateContractTest extends TestCase {
	/** The input contract is closed and excludes hierarchical controls. */
	public function test_input_schema_is_closed_and_bounded(): void {
		$schema = TagCreateContract::input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'name', 'idempotency_key' ), $schema['required'] );
		self::assertSame( array( 'name', 'idempotency_key', 'slug', 'description' ), array_keys( $schema['properties'] ) );
		self::assertSame( 200, $schema['properties']['name']['maxLength'] );
		self::assertSame( 8, $schema['properties']['idempotency_key']['minLength'] );
		self::assertSame( 128, $schema['properties']['idempotency_key']['maxLength'] );
		self::assertSame( '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$', $schema['properties']['idempotency_key']['pattern'] );
		self::assertSame( 50000, $schema['properties']['description']['maxLength'] );
		self::assertArrayNotHasKey( 'parent_id', $schema['properties'] );
	}

	/** The output contract exposes only the safe tag projection. */
	public function test_output_schema_is_closed_and_excludes_parent(): void {
		$schema = TagCreateContract::output_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'id', 'name', 'slug', 'description', 'count', 'idempotency_replayed' ), $schema['required'] );
		self::assertSame( 'boolean', $schema['properties']['idempotency_replayed']['type'] );
		self::assertArrayNotHasKey( 'parent_id', $schema['properties'] );
	}
}
