<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector;

use WPAuto\Connector\Abilities\Site\SiteHealthAbility;
use WPAuto\Connector\Admin\AdminPage;
use WPAuto\Connector\Mcp\McpAdapterLoader;
use WPAuto\Connector\Mcp\McpServerRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services and lifecycle checks.
 */
final class Plugin {
	/**
	 * Singleton plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Return the singleton plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enforce minimum runtime versions during activation.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_AUTO_CONNECTOR_FILE ) );
			wp_die(
				esc_html__( 'WP-Auto Connector requires PHP 8.1 or later.', 'wp-auto-connector' ),
				esc_html__( 'Plugin activation failed', 'wp-auto-connector' ),
				array( 'back_link' => true )
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.9', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_AUTO_CONNECTOR_FILE ) );
			wp_die(
				esc_html__( 'WP-Auto Connector requires WordPress 6.9 or later.', 'wp-auto-connector' ),
				esc_html__( 'Plugin activation failed', 'wp-auto-connector' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Register the Phase 1.1 services.
	 */
	public function boot(): void {
		( new SiteHealthAbility() )->register();
		( new McpServerRegistrar() )->register();
		McpAdapterLoader::initialize();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
