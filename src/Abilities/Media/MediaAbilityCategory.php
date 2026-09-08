<?php
/**
 * Media Ability category registration.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the category required before media Abilities. */
final class MediaAbilityCategory {
	public const SLUG = 'wp-auto-media';

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
				'label'       => __( 'WP-Auto Media', 'wp-auto-connector' ),
				'description' => __( 'WP-Auto abilities for safe WordPress media access.', 'wp-auto-connector' ),
			)
		);
	}
}
