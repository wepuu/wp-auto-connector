<?php
/**
 * Phase 1.6.2 provider-neutral SEO Update Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Seo;

use WPAuto\Connector\Seo\SeoContract;
use WPAuto\Connector\Seo\SeoUpdateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the fixed draft-only SEO update contract. */
final class SeoUpdateAbility {
	public const NAME = 'wp-auto/seo-update';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the strict SEO Update Ability. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto SEO Update', 'wepuu-auto-connector' ),
				'description'         => __( 'Updates allowlisted SEO overrides on one authorized WordPress Post or Page draft.', 'wepuu-auto-connector' ),
				'category'            => SeoAbilityCategory::SLUG,
				'input_schema'        => SeoContract::update_input_schema(),
				'output_schema'       => SeoContract::update_output_schema(),
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
	 * Execute the provider-neutral SEO update service.
	 *
	 * @param mixed $input Validated Ability input.
	 */
	public function execute( $input ) {
		return ( new SeoUpdateService() )->update( $input );
	}

	/** Require the generic read identity and provider write capability. */
	public function check_permission(): bool {
		return ( new SeoUpdateService() )->can_update();
	}
}
