<?php
/**
 * Phase 1.4.1 Media Get Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaReadContract;
use WPAuto\Connector\Media\MediaReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers object-authorized image retrieval. */
final class MediaGetAbility {
	public const NAME = 'wp-auto/media-get';

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
				'label'               => __( 'WP-Auto Media Get', 'wp-auto-connector' ),
				'description'         => __( 'Returns one readable WordPress image using the frozen media contract.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaReadContract::get_input_schema(),
				'output_schema'       => MediaReadContract::get_output_schema(),
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
	public function execute( $input ) {
		return ( new MediaReadService() )->get( $input );
	}

	/** Require the frozen media-library baseline. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
