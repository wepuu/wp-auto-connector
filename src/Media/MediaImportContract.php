<?php
/**
 * Frozen Phase 1.4.5 remote image import schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the public remote URL import contract. */
final class MediaImportContract {
	/** Return the strict remote import input schema. */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'url'             => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2048,
				),
				'filename'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'idempotency_key' => array(
					'type'      => 'string',
					'minLength' => 16,
					'maxLength' => 128,
				),
				'parent_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'url', 'filename', 'idempotency_key' ),
		);
	}

	/** Return the strict remote import output schema. */
	public static function output_schema(): array {
		$properties = MediaReadContract::get_output_schema()['properties'] + array(
			'idempotency_replayed' => array( 'type' => 'boolean' ),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}
}
