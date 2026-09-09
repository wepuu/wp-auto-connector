<?php
/**
 * Tag Create schema contract.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the strict Phase 1.5.2 Tag Create schemas.
 */
final class TagCreateContract {
	/**
	 * Return the strict input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'idempotency_key' ),
			'properties'           => array(
				'name'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'idempotency_key' => array(
					'type'      => 'string',
					'minLength' => 8,
					'maxLength' => 128,
					'pattern'   => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
				),
				'slug'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'description'     => array(
					'type'      => 'string',
					'maxLength' => 50000,
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
			'required'             => array( 'id', 'name', 'slug', 'description', 'count', 'idempotency_replayed' ),
			'properties'           => array(
				'id'                   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'                 => array( 'type' => 'string' ),
				'slug'                 => array( 'type' => 'string' ),
				'description'          => array( 'type' => 'string' ),
				'count'                => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'idempotency_replayed' => array( 'type' => 'boolean' ),
			),
		);
	}
}
