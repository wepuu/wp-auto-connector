<?php
/**
 * Frozen Phase 1.4.3 media metadata update schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the public Media Metadata Update contract. */
final class MediaUpdateContract {
	/** Return the strict update input schema. */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'                    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_modified_gmt' => array(
					'type'      => 'string',
					'minLength' => 19,
					'maxLength' => 19,
					'pattern'   => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
				),
				'title'                 => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt_text'              => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'caption'               => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
				'description'           => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
			),
			'required'             => array( 'id', 'expected_modified_gmt' ),
		);
	}

	/** Return the exact full-record output schema. */
	public static function output_schema(): array {
		return MediaReadContract::get_output_schema();
	}
}
