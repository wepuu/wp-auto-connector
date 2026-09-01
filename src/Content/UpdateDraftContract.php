<?php
/**
 * Shared Phase 1.3.2 Update Draft contract definitions.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the strict schemas shared by the Post and Page Update abilities.
 */
final class UpdateDraftContract {
	/**
	 * Return the exact Update Draft input schema.
	 *
	 * @return array<string, mixed>
	 */
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
					'maxLength' => 500,
				),
				'content'               => array(
					'type'      => 'string',
					'maxLength' => 1000000,
				),
				'excerpt'               => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
				'slug'                  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
			),
			'required'             => array( 'id', 'expected_modified_gmt' ),
		);
	}

	/**
	 * Return the exact output schema for one fixed post type.
	 *
	 * @param string $post_type Fixed post type.
	 * @return array<string, mixed>
	 */
	public static function output_schema( string $post_type ): array {
		$type       = in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : 'post';
		$properties = array(
			'id'           => array( 'type' => 'integer' ),
			'type'         => array(
				'type' => 'string',
				'enum' => array( $type ),
			),
			'status'       => array(
				'type' => 'string',
				'enum' => array( 'draft' ),
			),
			'slug'         => array( 'type' => 'string' ),
			'link'         => array( 'type' => 'string' ),
			'edit_url'     => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}
}
