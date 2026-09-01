<?php
/**
 * Phase 1.3.1 post-create-draft ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Content;

use WPAuto\Connector\Content\ContentMutationService;
use WPAuto\Connector\Content\CreateDraftContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the capability-aware Post Create Draft ability.
 */
final class PostCreateDraftAbility {
	public const NAME = 'wp-auto/post-create-draft';

	/**
	 * Register the ability hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Register the ability with WordPress Core.
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Post Create Draft', 'wp-auto-connector' ),
				'description'         => __( 'Creates one WordPress post draft with a persistent idempotency key.', 'wp-auto-connector' ),
				'category'            => ContentAbilityCategory::SLUG,
				'input_schema'        => CreateDraftContract::input_schema(),
				'output_schema'       => CreateDraftContract::output_schema( 'post' ),
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
	 * Execute the shared mutation service.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		return ( new ContentMutationService() )->create_post_draft( $input );
	}

	/**
	 * Require the actual Post create capability.
	 */
	public function check_permission(): bool {
		return ( new ContentMutationService() )->can_create( 'post' );
	}
}
