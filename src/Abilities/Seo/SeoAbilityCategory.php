<?php
/**
 * SEO Ability category.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the provider-neutral SEO category. */
final class SeoAbilityCategory {
	public const SLUG = 'wp-auto-seo';

	/** Register the category hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
	}

	/** Register the category with WordPress Core. */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::SLUG,
			array(
				'label'       => __( 'WP-Auto SEO', 'wepuu-auto-connector' ),
				'description' => __( 'WP-Auto abilities for explicit object SEO overrides.', 'wepuu-auto-connector' ),
			)
		);
	}
}
