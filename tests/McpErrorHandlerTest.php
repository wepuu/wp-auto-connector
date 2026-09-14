<?php
/**
 * Dedicated MCP error-handler tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Mcp\McpErrorHandler;

/**
 * Covers expected rejection suppression and unexpected-failure redaction.
 */
final class McpErrorHandlerTest extends TestCase {
	/** Reset captured PHP error-log messages. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_error_log'] = array();
	}

	/** Expected connector 4xx results are client control flow, not server errors. */
	public function test_suppresses_expected_tool_rejections(): void {
		$handler = new McpErrorHandler();

		$handler->log(
			'Tool execution returned WP_Error',
			array(
				'tool_name'     => 'wp-auto-post-update',
				'error_code'    => 'wp_auto_content_not_found',
				'error_message' => 'The requested content could not be found.',
				'error_data'    => array( 'status' => 404 ),
			)
		);
		$handler->log(
			'Tool execution returned WP_Error',
			array(
				'tool_name'  => 'wp-auto-seo-update',
				'error_code' => 'wp_auto_seo_conflict',
				'error_data' => array( 'status' => '409' ),
			)
		);
		$handler->log(
			'Tool permission check failed',
			array(
				'tool_name'  => 'wp-auto-taxonomy-assign',
				'error_code' => 'wp_auto_permission_denied',
				'error_data' => array( 'status' => 403 ),
			)
		);

		self::assertSame( array(), $GLOBALS['wp_auto_test_error_log'] );
	}

	/** Unknown tools and normal transport denials must not pollute PHP stderr. */
	public function test_suppresses_expected_protocol_and_transport_denials(): void {
		$handler = new McpErrorHandler();

		$handler->log( 'Tool not found', array( 'tool_name' => "unknown\r\nforged" ), 'warning' );
		$handler->log( 'Permission callback returned WP_Error: WordPress authentication is required.', array( 'HttpTransport::check_permission' ) );
		$handler->log( 'Permission denied for MCP API access. User ID 7 does not have capability "read"', array( 'HttpTransport::check_permission' ) );

		self::assertSame( array(), $GLOBALS['wp_auto_test_error_log'] );
	}

	/** Internal tool failures remain visible with only bounded diagnostic fields. */
	public function test_logs_redacted_internal_tool_failure(): void {
		$handler = new McpErrorHandler();

		$handler->log(
			'Tool execution returned WP_Error',
			array(
				'tool_name'     => "wp-auto-seo-update\r\nFORGED",
				'error_code'    => 'wp_auto_seo_state_uncertain',
				'error_message' => 'Authorization: Basic secret-value',
				'error_data'    => array(
					'status'  => 500,
					'private' => 'secret-value',
				),
				'arguments'     => array( 'password' => 'secret-value' ),
			)
		);

		self::assertCount( 1, $GLOBALS['wp_auto_test_error_log'] );
		$log = $GLOBALS['wp_auto_test_error_log'][0];
		self::assertStringContainsString( '[ERROR] WP-Auto MCP event', $log );
		self::assertStringContainsString( '"event":"tool_execution_error"', $log );
		self::assertStringContainsString( '"status":500', $log );
		self::assertStringContainsString( '"error_code":"wp_auto_seo_state_uncertain"', $log );
		self::assertStringContainsString( '"tool":"wp-auto-seo-update__FORGED"', $log );
		self::assertStringNotContainsString( 'secret-value', $log );
		self::assertStringNotContainsString( 'Authorization', $log );
		self::assertStringNotContainsString( "\r", $log );
		self::assertStringNotContainsString( "\n", $log );
	}

	/** Exception text and arbitrary context never enter the server log. */
	public function test_logs_fixed_event_for_exception_without_raw_context(): void {
		$handler = new McpErrorHandler();

		$handler->log(
			'Error calling tool',
			array(
				'tool'      => 'wp-auto-post-update',
				'exception' => "token=secret-value\r\n[INFO] forged",
				'arguments' => array( 'content' => 'private draft' ),
			),
			'not-a-level'
		);

		self::assertCount( 1, $GLOBALS['wp_auto_test_error_log'] );
		$log = $GLOBALS['wp_auto_test_error_log'][0];
		self::assertStringContainsString( '[ERROR] WP-Auto MCP event', $log );
		self::assertStringContainsString( '"event":"tool_execution_exception"', $log );
		self::assertStringContainsString( '"tool":"wp-auto-post-update"', $log );
		self::assertStringNotContainsString( 'secret-value', $log );
		self::assertStringNotContainsString( 'private draft', $log );
	}

	/** Non-connector 4xx results remain visible as unexpected integration errors. */
	public function test_does_not_suppress_unknown_error_family(): void {
		$handler = new McpErrorHandler();

		$handler->log(
			'Tool execution returned WP_Error',
			array(
				'tool_name'  => 'wp-auto-site-health',
				'error_code' => 'provider_unexpected_error',
				'error_data' => array( 'status' => 404 ),
			),
			'warning'
		);

		self::assertCount( 1, $GLOBALS['wp_auto_test_error_log'] );
		self::assertStringContainsString( '[WARNING] WP-Auto MCP event', $GLOBALS['wp_auto_test_error_log'][0] );
	}
}
