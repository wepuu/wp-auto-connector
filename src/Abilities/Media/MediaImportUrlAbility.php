<?php
/**
 * Phase 1.4.5 Remote URL Import Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Media;

use WPAuto\Connector\Media\MediaImportContract;
use WPAuto\Connector\Media\MediaImportService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers one caller-triggered, bounded remote image import. */
final class MediaImportUrlAbility {
	public const NAME = 'wp-auto/media-import-url';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the strict remote import Ability. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Media Import', 'wp-auto-connector' ),
				'description'         => __( 'Imports one bounded, validated image from a caller-selected public URL.', 'wp-auto-connector' ),
				'category'            => MediaAbilityCategory::SLUG,
				'input_schema'        => MediaImportContract::input_schema(),
				'output_schema'       => MediaImportContract::output_schema(),
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
	 * Execute the import service.
	 *
	 * @param mixed $input Ability input.
	 */
	public function execute( $input ) {
		return ( new MediaImportService() )->import( $input );
	}

	/** Require the fixed media-library capability. */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
