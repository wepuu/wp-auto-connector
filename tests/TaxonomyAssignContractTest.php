<?php
/**
 * Taxonomy assignment contract tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Taxonomy\TaxonomyAssignContract;

/** Covers the strict Phase 1.5.3 public schemas. */
final class TaxonomyAssignContractTest extends TestCase {
	/** The input shape is closed and bounded. */
	public function test_input_schema_is_strict_and_bounded(): void {
		$schema = TaxonomyAssignContract::input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'target_id', 'taxonomy', 'term_ids', 'expected_term_ids' ), $schema['required'] );
		self::assertSame( array( 'category', 'post_tag' ), $schema['properties']['taxonomy']['enum'] );
		self::assertSame( 1, $schema['properties']['term_ids']['minItems'] );
		self::assertSame( 50, $schema['properties']['term_ids']['maxItems'] );
		self::assertTrue( $schema['properties']['term_ids']['uniqueItems'] );
		self::assertSame( 0, $schema['properties']['expected_term_ids']['minItems'] );
		self::assertSame( 50, $schema['properties']['expected_term_ids']['maxItems'] );
	}

	/** The output exposes only the fixed draft Post result. */
	public function test_output_schema_is_closed_and_fixed(): void {
		$schema = TaxonomyAssignContract::output_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'post' ), $schema['properties']['target_type']['enum'] );
		self::assertSame( array( 'draft' ), $schema['properties']['status']['enum'] );
		self::assertSame( array( 'category', 'post_tag' ), $schema['properties']['taxonomy']['enum'] );
		self::assertSame( 'boolean', $schema['properties']['changed']['type'] );
	}
}
