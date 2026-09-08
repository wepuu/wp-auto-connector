<?php
/**
 * Frozen Phase 1.4.1 media read schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the shared public contract for Media Search/Get. */
final class MediaReadContract {
	/**
	 * Fixed supported image MIME types.
	 *
	 * @var array<int, string>
	 */
	public const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/**
	 * Return the strict Search input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function search_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'search'    => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 200,
				),
				'mime_type' => array(
					'type'    => 'string',
					'default' => 'all',
					'enum'    => array_merge( array( 'all' ), self::MIME_TYPES ),
				),
				'page'      => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
				'orderby'   => array(
					'type'    => 'string',
					'default' => 'modified',
					'enum'    => array( 'date', 'modified', 'title', 'id' ),
				),
				'order'     => array(
					'type'    => 'string',
					'default' => 'desc',
					'enum'    => array( 'asc', 'desc' ),
				),
			),
		);
	}

	/**
	 * Return the strict Get input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
		);
	}

	/**
	 * Return the exact lightweight media properties.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_properties(): array {
		return array(
			'id'           => array( 'type' => 'integer' ),
			'title'        => array( 'type' => 'string' ),
			'filename'     => array( 'type' => 'string' ),
			'mime_type'    => array(
				'type' => 'string',
				'enum' => self::MIME_TYPES,
			),
			'source_url'   => array( 'type' => 'string' ),
			'parent_id'    => array( 'type' => 'integer' ),
			'date_gmt'     => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Return the strict Search output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function search_output_schema(): array {
		$item_properties = self::item_properties();
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'items'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => $item_properties,
						'required'             => array_keys( $item_properties ),
					),
				),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
				'returned' => array( 'type' => 'integer' ),
				'has_more' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'items', 'page', 'per_page', 'returned', 'has_more' ),
		);
	}

	/**
	 * Return the strict Get output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_output_schema(): array {
		$properties = self::item_properties() + array(
			'alt_text'    => array( 'type' => 'string' ),
			'caption'     => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'width'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'height'      => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}
}
