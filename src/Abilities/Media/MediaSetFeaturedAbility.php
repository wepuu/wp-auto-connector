<?php
/**
 * Phase 1.4.4 Featured Image Assignment Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaFeaturedContract;
use WPAuto\Connector\Media\MediaFeaturedService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers one narrowly bounded draft featured-image assignment. */
final class MediaSetFeaturedAbility {
	public const NAME = 'wp-auto/media-set-featured';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the strict featured-image Ability. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Set Featured Image', 'wp-auto-connector' ),
				'description'         => __( 'Sets one authorized image as the featured image of a draft post or page.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaFeaturedContract::input_schema(),
				'output_schema'       => MediaFeaturedContract::output_schema(),
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
	 * Execute the featured-image assignment service.
	 *
	 * @param mixed $input Ability input.
	 */
	public function execute( $input ) {
		return ( new MediaFeaturedService() )->set_featured( $input );
	}

	/** Require the fixed media-library baseline capability. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
