<?php
/**
 * Frozen Phase 1.4.2 upload schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the public Authenticated Image Upload contract. */
final class MediaUploadContract {
	public const MAX_ENCODED_LENGTH = 13981016;

	/** Return the strict upload input schema. */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'filename'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'content_base64'  => array(
					'type'      => 'string',
					'minLength' => 4,
					'maxLength' => self::MAX_ENCODED_LENGTH,
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
			'required'             => array( 'filename', 'content_base64', 'idempotency_key' ),
		);
	}

	/** Return the strict upload output schema. */
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
