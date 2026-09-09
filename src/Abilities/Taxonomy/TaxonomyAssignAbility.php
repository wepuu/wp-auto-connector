<?php
/**
 * Phase 1.5.3 draft Post taxonomy assignment ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Taxonomy;

use WPAuto\Connector\Taxonomy\TaxonomyAssignContract;
use WPAuto\Connector\Taxonomy\TaxonomyAssignmentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the destructive but bounded taxonomy assignment ability.
 */
final class TaxonomyAssignAbility {
	public const NAME = 'wp-auto/taxonomy-assign';

	/** Register the Ability API hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the fixed assignment Ability with WordPress Core. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Taxonomy Assign', 'wepuu-auto-connector' ),
				'description'         => __( 'Replaces one Category or Tag set on an authorized draft Post.', 'wepuu-auto-connector' ),
				'category'            => TaxonomyAbilityCategory::SLUG,
				'input_schema'        => TaxonomyAssignContract::input_schema(),
				'output_schema'       => TaxonomyAssignContract::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the shared taxonomy assignment service.
	 *
	 * @param mixed $input Validated Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input ) {
		return ( new TaxonomyAssignmentService() )->assign( $input );
	}

	/**
	 * Require the complete fixed taxonomy/Post capability baseline.
	 *
	 * @param mixed $input Ability input supplied by Core/Adapter.
	 */
	public function check_permission( $input = array() ): bool {
		return ( new TaxonomyAssignmentService() )->can_assign( $input );
	}
}
