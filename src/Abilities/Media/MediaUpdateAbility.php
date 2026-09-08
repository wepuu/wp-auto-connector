<?php
/**
 * Phase 1.4.3 Media Metadata Update Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaUpdateContract;
use WPAuto\Connector\Media\MediaUpdateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers one narrowly bounded attachment presentation update. */
final class MediaUpdateAbility {
	public const NAME = 'wp-auto/media-update';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the strict media update Ability. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Media Metadata Update', 'wp-auto-connector' ),
				'description'         => __( 'Updates allowlisted presentation fields on one authorized image.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaUpdateContract::input_schema(),
				'output_schema'       => MediaUpdateContract::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Execute the metadata update service.
	 *
	 * @param mixed $input Ability input.
	 */
	public function execute( $input ) {
		return ( new MediaUpdateService() )->update( $input );
	}

	/** Require the fixed media-library baseline capability. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
