<?php
/**
 * Phase 1.3.2 post-update ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Content;

use WPAuto\Connector\Content\ContentMutationService;
use WPAuto\Connector\Content\UpdateDraftContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the capability-aware Post Update ability.
 */
final class PostUpdateAbility {
	public const NAME = 'wp-auto/post-update';

	/** Register the ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the ability with WordPress Core. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Post Update', 'wp-auto-connector' ),
				'description'         => __( 'Updates allowlisted fields on one existing WordPress post draft.', 'wp-auto-connector' ),
				'category'            => ContentAbilityCategory::SLUG,
				'input_schema'        => UpdateDraftContract::input_schema(),
				'output_schema'       => UpdateDraftContract::output_schema( 'post' ),
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
	 * Execute the shared mutation service.
	 *
	 * @param mixed $input Validated ability input.
	 */
	public function execute( $input ) {
		return ( new ContentMutationService() )->update_post_draft( $input );
	}

	/** Require the actual Post edit baseline capability. */
	public function check_permission(): bool {
		return ( new ContentMutationService() )->can_update( 'post' );
	}
}
