<?php
/**
 * Phase 1.4.1 Media Search Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaReadContract;
use WPAuto\Connector\Media\MediaReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers bounded, permission-aware image discovery. */
final class MediaSearchAbility {
	public const NAME = 'wp-auto/media-search';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the Ability with WordPress Core. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Media Search', 'wp-auto-connector' ),
				'description'         => __( 'Searches readable WordPress images using bounded pagination.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaReadContract::search_input_schema(),
				'output_schema'       => MediaReadContract::search_output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the media read service.
	 *
	 * @param mixed $input Validated Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input = array() ) {
		return ( new MediaReadService() )->search( $input );
	}

	/** Require the frozen media-library baseline. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
