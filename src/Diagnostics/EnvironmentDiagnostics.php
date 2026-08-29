<?php
/**
 * Runtime diagnostics shared by the site-health ability and admin screen.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a deliberately small and non-sensitive diagnostic set.
 */
final class EnvironmentDiagnostics {
	/**
	 * Return the safe Phase 1.1 site-health fields.
	 *
	 * @return array<string, bool|string>
	 */
	public static function site_health(): array {
		return array(
			'wordpress_version'       => get_bloginfo( 'version' ),
			'php_version'             => PHP_VERSION,
			'connector_version'       => WP_AUTO_CONNECTOR_VERSION,
			'abilities_api_available' => self::abilities_api_available(),
			'mcp_adapter_available'   => self::mcp_adapter_available(),
			'mcp_adapter_version'     => self::mcp_adapter_version(),
			'rest_api_available'      => self::rest_api_available(),
			'https'                   => is_ssl(),
		);
	}

	/**
	 * Check whether the WordPress Abilities API is loaded.
	 */
	public static function abilities_api_available(): bool {
		return function_exists( 'wp_register_ability' ) && class_exists( 'WP_Ability' );
	}

	/**
	 * Check whether the official MCP Adapter class is available.
	 */
	public static function mcp_adapter_available(): bool {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Return the loaded official MCP Adapter version.
	 */
	public static function mcp_adapter_version(): string {
		if ( ! self::mcp_adapter_available() ) {
			return '';
		}

		return (string) \WP\MCP\Core\McpAdapter::VERSION;
	}

	/**
	 * Check whether the WordPress REST API is loaded.
	 */
	public static function rest_api_available(): bool {
		return function_exists( 'rest_get_server' ) && class_exists( 'WP_REST_Server' );
	}
}
