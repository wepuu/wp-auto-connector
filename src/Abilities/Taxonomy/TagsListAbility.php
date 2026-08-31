<?php
/**
 * Phase 1.2.4 tags-list ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Taxonomy;

use WPAuto\Connector\Taxonomy\TaxonomyReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the bounded, read-only tags-list ability.
 */
final class TagsListAbility {
	public const NAME = 'wp-auto/tags-list';

	/**
	 * Register the ability hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Register the ability with WordPress core.
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Tags List', 'wp-auto-connector' ),
				'description'         => __( 'Lists WordPress tags using bounded pagination.', 'wp-auto-connector' ),
				'category'            => TaxonomyAbilityCategory::SLUG,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the read through the taxonomy service.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input = array() ) {
		return ( new TaxonomyReadService() )->list_tags( $input );
	}

	/**
	 * Require the baseline read capability.
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Return the strict frozen input contract.
	 *
	 * @return array<string, mixed>
	 */
	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'search'     => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 200,
				),
				'page'       => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				),
				'orderby'    => array(
					'type'    => 'string',
					'default' => 'name',
					'enum'    => array( 'name', 'count', 'id', 'slug' ),
				),
				'order'      => array(
					'type'    => 'string',
					'default' => 'asc',
					'enum'    => array( 'asc', 'desc' ),
				),
				'hide_empty' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		);
	}

	/**
	 * Return the exact tag-list output contract.
	 *
	 * @return array<string, mixed>
	 */
	private function output_schema(): array {
		$item_properties = array(
			'id'          => array( 'type' => 'integer' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'count'       => array( 'type' => 'integer' ),
		);

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
}
