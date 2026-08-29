<?php
/**
 * Phase 1.2.2 posts-search ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Content;

use WPAuto\Connector\Content\ContentReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the bounded, permission-aware posts-search ability.
 */
final class PostsSearchAbility {
	public const NAME = 'wp-auto/posts-search';

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
				'label'               => __( 'WP-Auto Posts Search', 'wp-auto-connector' ),
				'description'         => __( 'Searches readable WordPress posts using bounded pagination.', 'wp-auto-connector' ),
				'category'            => ContentAbilityCategory::SLUG,
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
	 * Execute the read through the content service.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input = array() ) {
		return ( new ContentReadService() )->search_posts( $input );
	}

	/**
	 * Require the baseline read capability at ability entry.
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
				'search'   => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 200,
				),
				'status'   => array(
					'type'    => 'string',
					'default' => 'publish',
					'enum'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
				'orderby'  => array(
					'type'    => 'string',
					'default' => 'modified',
					'enum'    => array( 'date', 'modified', 'title', 'id' ),
				),
				'order'    => array(
					'type'    => 'string',
					'default' => 'desc',
					'enum'    => array( 'asc', 'desc' ),
				),
			),
		);
	}

	/**
	 * Return the exact search result schema.
	 *
	 * @return array<string, mixed>
	 */
	private function output_schema(): array {
		$item_properties = array(
			'id'           => array( 'type' => 'integer' ),
			'title'        => array( 'type' => 'string' ),
			'slug'         => array( 'type' => 'string' ),
			'status'       => array( 'type' => 'string' ),
			'link'         => array( 'type' => 'string' ),
			'author_id'    => array( 'type' => 'integer' ),
			'date_gmt'     => array( 'type' => 'string' ),
			'modified_gmt' => array( 'type' => 'string' ),
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
