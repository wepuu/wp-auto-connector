<?php
/**
 * Shared Phase 1.3.1 Create Draft contract definitions.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the strict schemas shared by the Post and Page Create Draft abilities.
 */
final class CreateDraftContract {
	/**
	 * Return the exact Create Draft input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
				'content'         => array(
					'type'      => 'string',
					'maxLength' => 1000000,
				),
				'excerpt'         => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
				'slug'            => array(
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
			),
			'required'             => array( 'title', 'idempotency_key' ),
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
			'id'                   => array( 'type' => 'integer' ),
			'type'                 => array(
				'type' => 'string',
				'enum' => array( $type ),
			),
			'status'               => array(
				'type' => 'string',
				'enum' => array( 'draft' ),
			),
			'slug'                 => array( 'type' => 'string' ),
			'link'                 => array( 'type' => 'string' ),
			'edit_url'             => array( 'type' => 'string' ),
			'modified_gmt'         => array( 'type' => 'string' ),
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
