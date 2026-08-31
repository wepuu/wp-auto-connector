<?php
/**
 * WP-Auto taxonomy ability category registration.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the category required by WordPress before taxonomy abilities.
 */
final class TaxonomyAbilityCategory {
	public const SLUG = 'wp-auto-taxonomy';

	/**
	 * Register the category hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
	}

	/**
	 * Register the taxonomy category with WordPress core.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::SLUG,
			array(
				'label'       => __( 'WP-Auto Taxonomy', 'wp-auto-connector' ),
				'description' => __( 'Read-only WP-Auto abilities for WordPress categories and tags.', 'wp-auto-connector' ),
			)
		);
	}
}
