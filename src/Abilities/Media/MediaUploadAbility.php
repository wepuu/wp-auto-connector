<?php
/**
 * Phase 1.4.2 Authenticated Image Upload Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaUploadContract;
use WPAuto\Connector\Media\MediaUploadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers one bounded, authenticated image upload. */
final class MediaUploadAbility {
	public const NAME = 'wp-auto/media-upload';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the strict upload Ability. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Media Upload', 'wp-auto-connector' ),
				'description'         => __( 'Uploads one bounded, validated image to WordPress.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaUploadContract::input_schema(),
				'output_schema'       => MediaUploadContract::output_schema(),
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
	 * Execute the upload service.
	 *
	 * @param mixed $input Ability input.
	 */
	public function execute( $input ) {
		return ( new MediaUploadService() )->upload( $input );
	}

	/** Require the fixed media-library capability. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
