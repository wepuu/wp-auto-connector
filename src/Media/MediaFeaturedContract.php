<?php
/**
 * Frozen Phase 1.4.4 featured image assignment schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the public Featured Image Assignment contract. */
final class MediaFeaturedContract {
	/** Return the strict assignment input schema. */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'target_id'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'media_id'                   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_featured_media_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'target_id', 'media_id', 'expected_featured_media_id' ),
		);
	}

	/** Return the exact assignment output schema. */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'target_id'         => array(
					'type' => 'integer',
				),
				'target_type'       => array(
					'type' => 'string',
					'enum' => array( 'post', 'page' ),
				),
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
				'featured_media_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'changed'           => array(
					'type' => 'boolean',
				),
			),
			'required'             => array( 'target_id', 'target_type', 'status', 'featured_media_id', 'changed' ),
		);
	}
}
