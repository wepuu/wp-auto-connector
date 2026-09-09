<?php
/**
 * Phase 1.5.1 category-create ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Taxonomy;

use WPAuto\Connector\Taxonomy\CategoryCreateContract;
use WPAuto\Connector\Taxonomy\TaxonomyMutationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the capability-aware built-in Category Create ability.
 */
final class CategoryCreateAbility {
	public const NAME = 'wp-auto/category-create';

	/**
	 * Register the ability hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Register the ability with WordPress Core.
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Category Create', 'wepuu-auto-connector' ),
				'description'         => __( 'Creates one built-in WordPress category with a persistent idempotency key.', 'wepuu-auto-connector' ),
				'category'            => TaxonomyAbilityCategory::SLUG,
				'input_schema'        => CategoryCreateContract::input_schema(),
				'output_schema'       => CategoryCreateContract::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the shared taxonomy mutation service.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		return ( new TaxonomyMutationService() )->create_category( $input );
	}

	/**
	 * Require the actual Category management capability.
	 */
	public function check_permission(): bool {
		return ( new TaxonomyMutationService() )->can_create_category();
	}
}
