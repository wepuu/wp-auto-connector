<?php
/**
 * MCP Adapter loader tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpAdapter;
use WPAuto\Connector\Mcp\McpAdapterLoader;

/**
 * Covers Adapter coexistence and initialization behavior.
 */
final class McpAdapterLoaderTest extends TestCase {
	/**
	 * Verify an existing compatible Adapter is reused and initialized.
	 */
	public function test_prefers_and_initializes_an_available_compatible_adapter(): void {
		McpAdapter::$instance_calls = 0;

		self::assertTrue( McpAdapterLoader::load() );
		self::assertTrue( McpAdapterLoader::is_compatible() );
		self::assertTrue( McpAdapterLoader::initialize() );
		self::assertSame( 1, McpAdapter::$instance_calls );
	}
}
