<?php
/**
 * Draft Post taxonomy assignment schema contract.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the strict Phase 1.5.3 taxonomy assignment schemas.
 */
final class TaxonomyAssignContract {
	/**
	 * Return the strict input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'target_id', 'taxonomy', 'term_ids', 'expected_term_ids' ),
			'properties'           => array(
				'target_id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'taxonomy'          => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag' ),
				),
				'term_ids'          => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 50,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'expected_term_ids' => array(
					'type'        => 'array',
					'minItems'    => 0,
					'maxItems'    => 50,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
		);
	}

	/**
	 * Return the strict output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'target_id', 'target_type', 'status', 'taxonomy', 'term_ids', 'changed' ),
			'properties'           => array(
				'target_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'target_type' => array(
					'type' => 'string',
					'enum' => array( 'post' ),
				),
				'status'      => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
				'taxonomy'    => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag' ),
				),
				'term_ids'    => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 50,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'changed'     => array( 'type' => 'boolean' ),
			),
		);
	}
}
