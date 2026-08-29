<?php
/**
 * Phase 1.2.1 site-info ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and executes the safe, read-only site-info ability.
 */
final class SiteInfoAbility {
	public const NAME = 'wp-auto/site-info';

	/**
	 * Register the ability hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Register the ability with WordPress core.
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto Site Info', 'wp-auto-connector' ),
				'description'         => __( 'Returns safe, basic WordPress site configuration information.', 'wp-auto-connector' ),
				'category'            => 'site',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
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
	 * Return safe basic site information.
	 *
	 * @return array<string, bool|string>
	 */
	public function execute(): array {
		return array(
			'site_name'           => get_bloginfo( 'name' ),
			'site_description'    => get_bloginfo( 'description' ),
			'site_url'            => get_site_url(),
			'home_url'            => get_home_url(),
			'language'            => get_bloginfo( 'language' ),
			'timezone'            => wp_timezone_string(),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'multisite'           => is_multisite(),
		);
	}

	/**
	 * Require the narrow read capability.
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Return the strict empty input contract.
	 *
	 * @return array<string, mixed>
	 */
	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
	}

	/**
	 * Return the public output contract.
	 *
	 * @return array<string, mixed>
	 */
	private function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'site_name'           => array( 'type' => 'string' ),
				'site_description'    => array( 'type' => 'string' ),
				'site_url'            => array( 'type' => 'string' ),
				'home_url'            => array( 'type' => 'string' ),
				'language'            => array( 'type' => 'string' ),
				'timezone'            => array( 'type' => 'string' ),
				'permalink_structure' => array( 'type' => 'string' ),
				'multisite'           => array( 'type' => 'boolean' ),
			),
			'required'             => array(
				'site_name',
				'site_description',
				'site_url',
				'home_url',
				'language',
				'timezone',
				'permalink_structure',
				'multisite',
			),
		);
	}
}
