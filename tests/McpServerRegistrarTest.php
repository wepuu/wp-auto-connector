<?php
/**
 * Dedicated MCP server registration tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;
use WP_Error;
use WPAuto\Connector\Abilities\Site\SiteHealthAbility;
use WPAuto\Connector\Mcp\McpServerRegistrar;

/**
 * Covers custom-server registration and transport authorization.
 */
final class McpServerRegistrarTest extends TestCase {
	/**
	 * Reset WordPress stub state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']     = array();
		$GLOBALS['wp_auto_test_logged_in'] = false;
		$GLOBALS['wp_auto_test_can_read']  = false;
	}

	/**
	 * Verify custom-server registration timing.
	 */
	public function test_registers_on_the_adapter_hook(): void {
		$registrar = new McpServerRegistrar();
		$registrar->register();

		self::assertArrayHasKey( 'mcp_adapter_init', $GLOBALS['wp_auto_test_hooks'] );
		self::assertSame( array( $registrar, 'register_server' ), $GLOBALS['wp_auto_test_hooks']['mcp_adapter_init'] );
	}

	/**
	 * Verify the exact endpoint parameters and tool allowlist.
	 */
	public function test_registers_a_custom_http_server_with_only_site_health(): void {
		$adapter   = new class() {
			/**
			 * Captured create_server arguments.
			 *
			 * @var array<int, mixed>
			 */
			public array $arguments = array();

			/**
			 * Capture custom-server arguments.
			 *
			 * @param mixed ...$arguments Server registration arguments.
			 */
			public function create_server( ...$arguments ): void {
				$this->arguments = $arguments;
			}
		};
		$registrar = new McpServerRegistrar();

		$registrar->register_server( $adapter );

		self::assertSame( McpServerRegistrar::SERVER_ID, $adapter->arguments[0] );
		self::assertSame( 'wp-auto', $adapter->arguments[1] );
		self::assertSame( 'mcp', $adapter->arguments[2] );
		self::assertSame( array( HttpTransport::class ), $adapter->arguments[6] );
		self::assertSame( ErrorLogMcpErrorHandler::class, $adapter->arguments[7] );
		self::assertSame( NullMcpObservabilityHandler::class, $adapter->arguments[8] );
		self::assertSame( array( SiteHealthAbility::NAME ), $adapter->arguments[9] );
		self::assertSame( array(), $adapter->arguments[10] );
		self::assertSame( array(), $adapter->arguments[11] );
		self::assertIsCallable( $adapter->arguments[12] );
	}

	/**
	 * Verify both transport authorization layers.
	 */
	public function test_transport_rejects_unauthenticated_and_unauthorized_users(): void {
		$registrar = new McpServerRegistrar();
		$result    = $registrar->check_transport_permission();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );

		$GLOBALS['wp_auto_test_logged_in'] = true;
		$result                            = $registrar->check_transport_permission();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );

		$GLOBALS['wp_auto_test_can_read'] = true;
		self::assertTrue( $registrar->check_transport_permission() );
	}
}
