<?php
/**
 * WP-Auto content ability category registration.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the category required by WordPress before content abilities.
 */
final class ContentAbilityCategory {
	public const SLUG = 'wp-auto-content';

	/**
	 * Register the category hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
	}

	/**
	 * Register the content category with WordPress core.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::SLUG,
			array(
				'label'       => __( 'WP-Auto Content', 'wp-auto-connector' ),
				'description' => __( 'Read-only WP-Auto abilities for WordPress content.', 'wp-auto-connector' ),
			)
		);
	}
}
