<?php
/**
 * MCP Adapter loader tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\PrivateMcp\WP\MCP\Core\McpAdapter;
use WPAuto\Connector\Mcp\McpAdapterLoader;

/**
 * Covers Adapter coexistence and initialization behavior.
 */
final class McpAdapterLoaderTest extends TestCase {
	/** Reset Adapter and filter state. */
	protected function setUp(): void {
		McpAdapter::$instance_calls              = 0;
		\WP\MCP\Core\McpAdapter::$instance_calls = 0;
		$GLOBALS['wp_auto_test_filters']         = array();
	}

	/**
	 * Verify the private compatible Adapter is reused and initialized.
	 */
	public function test_prefers_and_initializes_an_available_compatible_adapter(): void {
		self::assertTrue( McpAdapterLoader::load() );
		self::assertTrue( McpAdapterLoader::is_compatible() );
		self::assertTrue( McpAdapterLoader::initialize() );
		self::assertSame( 1, McpAdapter::$instance_calls );
		self::assertSame( 0, \WP\MCP\Core\McpAdapter::$instance_calls );
		self::assertArrayHasKey(
			'wp_auto_connector_mcp_adapter_create_default_server',
			$GLOBALS['wp_auto_test_filters']
		);
	}

	/** The provider's incompatible global class must not influence diagnostics. */
	public function test_ignores_an_incompatible_provider_bundled_adapter(): void {
		self::assertSame( '0.5.0', \WP\MCP\Core\McpAdapter::VERSION );
		self::assertTrue( McpAdapterLoader::is_compatible() );
		self::assertSame( '0.6.1', McpAdapterLoader::version() );
	}
}
