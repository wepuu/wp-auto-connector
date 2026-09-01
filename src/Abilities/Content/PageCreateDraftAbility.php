<?php
/**
 * Phase 1.3.1 page-create-draft ability.
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
 * Registers the capability-aware Page Create Draft ability.
 */
final class PageCreateDraftAbility {
	public const NAME = 'wp-auto/page-create-draft';

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
				'label'               => __( 'WP-Auto Page Create Draft', 'wp-auto-connector' ),
				'description'         => __( 'Creates one root WordPress page draft with a persistent idempotency key.', 'wp-auto-connector' ),
				'category'            => ContentAbilityCategory::SLUG,
				'input_schema'        => CreateDraftContract::input_schema(),
				'output_schema'       => CreateDraftContract::output_schema( 'page' ),
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
		return ( new ContentMutationService() )->create_page_draft( $input );
	}

	/**
	 * Require the actual Page create capability.
	 */
	public function check_permission(): bool {
		return ( new ContentMutationService() )->can_create( 'page' );
	}
}
