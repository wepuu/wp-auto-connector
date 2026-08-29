<?php
/**
 * Phase 1.1 site-health ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Site;

use WPAuto\Connector\Diagnostics\EnvironmentDiagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and executes the read-only Phase 1.1 proof ability.
 */
final class SiteHealthAbility {
	public const NAME = 'wp-auto/site-health';

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
				'label'               => __( 'WP-Auto Site Health', 'wp-auto-connector' ),
				'description'         => __( 'Returns a safe, read-only connector and WordPress runtime status summary.', 'wp-auto-connector' ),
				'category'            => 'site',
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
	 * Execute the site-health ability.
	 *
	 * @return array<string, bool|string>
	 */
	public function execute(): array {
		return EnvironmentDiagnostics::site_health();
	}

	/**
	 * Require the narrow read capability.
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
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
				'wordpress_version'       => array( 'type' => 'string' ),
				'php_version'             => array( 'type' => 'string' ),
				'connector_version'       => array( 'type' => 'string' ),
				'abilities_api_available' => array( 'type' => 'boolean' ),
				'mcp_adapter_available'   => array( 'type' => 'boolean' ),
				'mcp_adapter_version'     => array( 'type' => 'string' ),
				'rest_api_available'      => array( 'type' => 'boolean' ),
				'https'                   => array( 'type' => 'boolean' ),
			),
			'required'             => array(
				'wordpress_version',
				'php_version',
				'connector_version',
				'abilities_api_available',
				'mcp_adapter_available',
				'mcp_adapter_version',
				'rest_api_available',
				'https',
			),
		);
	}
}
