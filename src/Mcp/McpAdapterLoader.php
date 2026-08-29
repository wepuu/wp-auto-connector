<?php
/**
 * Isolated loader for the official WordPress MCP Adapter dependency.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and initializes a compatible official MCP Adapter instance.
 */
final class McpAdapterLoader {
	private const MINIMUM_VERSION       = '0.6.1';
	private const NEXT_BREAKING_VERSION = '0.7.0';

	/**
	 * Register the collision-safe Composer package autoloader when needed.
	 */
	public static function load(): bool {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) {
			return true;
		}

		$autoload_file = WP_AUTO_CONNECTOR_DIR . 'vendor/autoload_packages.php';
		if ( is_readable( $autoload_file ) ) {
			require_once $autoload_file;

			// Defer class resolution until plugins_loaded so every plugin's
			// Jetpack package manifest can participate in version selection.
			return true;
		}

		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Initialize the official adapter singleton after all plugins have loaded.
	 */
	public static function initialize(): bool {
		if ( ! self::load() || ! self::is_compatible() ) {
			return false;
		}

		\WP\MCP\Core\McpAdapter::instance();

		return true;
	}

	/**
	 * Check that the loaded Adapter provides the verified v0.6.x API.
	 */
	public static function is_compatible(): bool {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return false;
		}

		$version = (string) \WP\MCP\Core\McpAdapter::VERSION;

		return version_compare( $version, self::MINIMUM_VERSION, '>=' )
			&& version_compare( $version, self::NEXT_BREAKING_VERSION, '<' );
	}
}
