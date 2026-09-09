<?php
/**
 * Category Create schema contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\CategoryCreateContract;

/** Covers the strict public Category Create schemas. */
final class CategoryCreateContractTest extends TestCase {
	/** The input contract is closed and bounded. */
	public function test_input_schema_is_closed_and_bounded(): void {
		$schema = CategoryCreateContract::input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'name', 'idempotency_key' ), $schema['required'] );
		self::assertSame( array( 'name', 'idempotency_key', 'slug', 'description', 'parent_id' ), array_keys( $schema['properties'] ) );
		self::assertSame( 200, $schema['properties']['name']['maxLength'] );
		self::assertSame( 8, $schema['properties']['idempotency_key']['minLength'] );
		self::assertSame( 128, $schema['properties']['idempotency_key']['maxLength'] );
		self::assertSame( '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$', $schema['properties']['idempotency_key']['pattern'] );
		self::assertSame( 50000, $schema['properties']['description']['maxLength'] );
		self::assertSame( 1, $schema['properties']['parent_id']['minimum'] );
	}

	/** The output contract exposes only the safe term projection. */
	public function test_output_schema_is_closed_and_requires_replay_marker(): void {
		$schema = CategoryCreateContract::output_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'id', 'name', 'slug', 'description', 'count', 'parent_id', 'idempotency_replayed' ), $schema['required'] );
		self::assertSame( 'boolean', $schema['properties']['idempotency_replayed']['type'] );
		self::assertSame( 0, $schema['properties']['parent_id']['minimum'] );
	}
}
