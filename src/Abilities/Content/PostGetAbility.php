<?php
/**
 * Phase 1.2.2 post-get ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Content;

use WPAuto\Connector\Content\ContentReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the object-authorized post-get ability.
 */
final class PostGetAbility {
	public const NAME = 'wp-auto/post-get';

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
				'label'               => __( 'WP-Auto Post Get', 'wp-auto-connector' ),
				'description'         => __( 'Returns one readable WordPress post using the frozen content contract.', 'wp-auto-connector' ),
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
	public function execute( $input ) {
		return ( new ContentReadService() )->get_post( $input );
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
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
		);
	}

	/**
	 * Return the exact post-get output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function output_schema(): array {
		$properties = array(
			'id'                => array( 'type' => 'integer' ),
			'type'              => array(
				'type' => 'string',
				'enum' => array( 'post' ),
			),
			'status'            => array( 'type' => 'string' ),
			'slug'              => array( 'type' => 'string' ),
			'title'             => array( 'type' => 'string' ),
			'excerpt'           => array( 'type' => 'string' ),
			'content'           => array( 'type' => 'string' ),
			'link'              => array( 'type' => 'string' ),
			'author_id'         => array( 'type' => 'integer' ),
			'date_gmt'          => array( 'type' => 'string' ),
			'modified_gmt'      => array( 'type' => 'string' ),
			'featured_media_id' => array( 'type' => 'integer' ),
			'categories'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'tags'              => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
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
