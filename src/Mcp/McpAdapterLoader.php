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
	private const ADAPTER_CLASS         = '\\WPAuto\\Connector\\PrivateMcp\\WP\\MCP\\Core\\McpAdapter';
	private const DEFAULT_SERVER_FILTER = 'wp_auto_connector_mcp_adapter_create_default_server';

	/**
	 * Register the private locked Adapter autoloader when needed.
	 */
	public static function load(): bool {
		if ( class_exists( self::ADAPTER_CLASS, false ) ) {
			return true;
		}

		$autoload_file = WP_AUTO_CONNECTOR_DIR . 'vendor/wp-auto-mcp-runtime/autoload.php';
		if ( is_readable( $autoload_file ) ) {
			require_once $autoload_file;
		}

		return class_exists( self::ADAPTER_CLASS );
	}

	/**
	 * Initialize the private official Adapter singleton after all plugins load.
	 */
	public static function initialize(): bool {
		if ( ! self::load() || ! self::is_compatible() ) {
			return false;
		}

		add_filter(
			self::DEFAULT_SERVER_FILTER,
			static function (): bool {
				return false;
			}
		);
		$adapter_class = self::ADAPTER_CLASS;
		$adapter_class::instance();

		return true;
	}

	/**
	 * Check that the private Adapter provides the verified v0.6.x API.
	 */
	public static function is_compatible(): bool {
		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			return false;
		}

		$adapter_class = self::ADAPTER_CLASS;
		$version       = (string) $adapter_class::VERSION;

		return version_compare( $version, self::MINIMUM_VERSION, '>=' )
			&& version_compare( $version, self::NEXT_BREAKING_VERSION, '<' );
	}

	/**
	 * Return the private Adapter version without resolving a global provider copy.
	 */
	public static function version(): string {
		if ( ! self::load() ) {
			return '';
		}

		$adapter_class = self::ADAPTER_CLASS;
		return (string) $adapter_class::VERSION;
	}
}
