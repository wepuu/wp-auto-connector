<?php
/**
 * Provider-neutral SEO Ability schemas.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Returns the frozen Phase 1.6 public input and output schemas. */
final class SeoContract {
	/** Return the strict SEO Get input schema. */
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

	/** Return the strict SEO Update input schema. */
	public static function update_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'                   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_state_token' => array(
					'type'    => 'string',
					'pattern' => '^[0-9a-f]{64}$',
				),
				'title'                => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'description'          => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'canonical_url'        => array(
					'type'      => 'string',
					'maxLength' => 2048,
				),
				'focus_keywords'       => array(
					'type'        => 'array',
					'maxItems'    => 5,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
				'robots'               => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'index'  => array(
							'type' => 'string',
							'enum' => array( 'default', 'index', 'noindex' ),
						),
						'follow' => array(
							'type' => 'string',
							'enum' => array( 'default', 'follow', 'nofollow' ),
						),
					),
					'required'             => array( 'index', 'follow' ),
				),
			),
			'required'             => array( 'id', 'expected_state_token' ),
		);
	}

	/** Return the provider-neutral SEO record schema. */
	public static function output_schema(): array {
		$properties = array(
			'id'             => array( 'type' => 'integer' ),
			'type'           => array(
				'type' => 'string',
				'enum' => array( 'post', 'page' ),
			),
			'status'         => array(
				'type' => 'string',
				'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			),
			'title'          => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
			'description'    => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'canonical_url'  => array(
				'type'      => 'string',
				'maxLength' => 2048,
			),
			'focus_keywords' => array(
				'type'        => 'array',
				'maxItems'    => 5,
				'uniqueItems' => true,
				'items'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'robots'         => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'index'  => array(
						'type' => 'string',
						'enum' => array( 'default', 'index', 'noindex' ),
					),
					'follow' => array(
						'type' => 'string',
						'enum' => array( 'default', 'follow', 'nofollow' ),
					),
				),
				'required'             => array( 'index', 'follow' ),
			),
			'state_token'    => array(
				'type'    => 'string',
				'pattern' => '^[0-9a-f]{64}$',
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}

	/** Return the SEO Update output schema. */
	public static function update_output_schema(): array {
		$schema                                 = self::output_schema();
		$schema['properties']['changed_fields'] = array(
			'type'        => 'array',
			'items'       => array(
				'type' => 'string',
				'enum' => array( 'title', 'description', 'canonical_url', 'focus_keywords', 'robots' ),
			),
			'uniqueItems' => true,
		);
		$schema['properties']['no_op']          = array( 'type' => 'boolean' );
		$schema['required']                     = array_merge( $schema['required'], array( 'changed_fields', 'no_op' ) );
		return $schema;
	}
}
