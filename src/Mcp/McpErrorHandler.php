<?php
/**
 * Redacted error handling for the dedicated WP-Auto MCP server.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Mcp;

use WPAuto\Connector\PrivateMcp\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suppresses expected client rejections and redacts unexpected MCP failures.
 */
final class McpErrorHandler implements McpErrorHandlerInterface {
	private const TOOL_NOT_FOUND               = 'Tool not found';
	private const TOOL_PERMISSION_FAILED       = 'Tool permission check failed';
	private const TOOL_EXECUTION_ERROR         = 'Tool execution returned WP_Error';
	private const TRANSPORT_PERMISSION_CONTEXT = 'HttpTransport::check_permission';

	/**
	 * Record an unexpected MCP failure without request or content data.
	 *
	 * @param string $message Adapter log message.
	 * @param array  $context Adapter log context.
	 * @param string $type    Requested severity.
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		if ( $this->is_expected_client_rejection( $message, $context ) ) {
			return;
		}

		$safe_context = array( 'event' => $this->event_name( $message ) );
		$status       = $this->status_from_context( $context );

		if ( null !== $status ) {
			$safe_context['status'] = $status;
		}

		$allowed_fields = array(
			'tool_name'     => 'tool',
			'tool'          => 'tool',
			'error_code'    => 'error_code',
			'method'        => 'method',
			'actual_type'   => 'actual_type',
			'returned_type' => 'returned_type',
			'filter'        => 'filter',
			'component'     => 'component',
			'server_id'     => 'server_id',
		);

		foreach ( $allowed_fields as $source => $destination ) {
			if ( isset( $safe_context[ $destination ] ) || ! isset( $context[ $source ] ) || ! is_scalar( $context[ $source ] ) ) {
				continue;
			}

			$value = $this->sanitize_identifier( (string) $context[ $source ] );
			if ( '' !== $value ) {
				$safe_context[ $destination ] = $value;
			}
		}

		$encoded = wp_json_encode( $safe_context, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{"event":"encoding_failure"}';
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Unexpected MCP failures need a redacted server-side diagnostic.
			sprintf(
				'[%s] WP-Auto MCP event | Context: %s',
				strtoupper( $this->normalize_type( $type ) ),
				$encoded
			)
		);
	}

	/**
	 * Decide whether an Adapter log call represents expected client control flow.
	 *
	 * @param string $message Adapter log message.
	 * @param array  $context Adapter log context.
	 */
	private function is_expected_client_rejection( string $message, array $context ): bool {
		if ( self::TOOL_NOT_FOUND === $message ) {
			return true;
		}

		if ( self::TOOL_PERMISSION_FAILED === $message || self::TOOL_EXECUTION_ERROR === $message ) {
			$error_code = isset( $context['error_code'] ) && is_string( $context['error_code'] ) ? $context['error_code'] : '';
			$status     = $this->status_from_context( $context );

			return str_starts_with( $error_code, 'wp_auto_' )
				&& null !== $status
				&& $status >= 400
				&& $status < 500;
		}

		if ( ! $this->has_transport_permission_context( $context ) ) {
			return false;
		}

		return str_starts_with( $message, 'Permission callback returned WP_Error:' )
			|| str_starts_with( $message, 'Permission denied for MCP API access.' );
	}

	/**
	 * Extract a valid HTTP-style status from Adapter context.
	 *
	 * @param array $context Adapter log context.
	 */
	private function status_from_context( array $context ): ?int {
		$status = $context['status'] ?? null;
		if ( isset( $context['error_data'] ) && is_array( $context['error_data'] ) ) {
			$status = $context['error_data']['status'] ?? $status;
		}

		if ( ! is_int( $status ) && ! ( is_string( $status ) && ctype_digit( $status ) ) ) {
			return null;
		}

		$status = (int) $status;
		return $status >= 100 && $status <= 599 ? $status : null;
	}

	/**
	 * Check for the Adapter's fixed transport-permission context marker.
	 *
	 * @param array $context Adapter log context.
	 */
	private function has_transport_permission_context( array $context ): bool {
		return isset( $context[0] )
			&& is_string( $context[0] )
			&& self::TRANSPORT_PERMISSION_CONTEXT === $context[0];
	}

	/**
	 * Convert an Adapter message into a fixed, non-sensitive event name.
	 *
	 * @param string $message Adapter log message.
	 */
	private function event_name( string $message ): string {
		if ( self::TOOL_EXECUTION_ERROR === $message ) {
			return 'tool_execution_error';
		}

		if ( 'Error calling tool' === $message ) {
			return 'tool_execution_exception';
		}

		if ( 'Unexpected error in handle_mcp_request' === $message ) {
			return 'transport_request_exception';
		}

		if ( str_starts_with( $message, 'Error in transport permission callback:' ) ) {
			return 'transport_permission_exception';
		}

		if ( 'Failed to persist MCP sessions after exhausting update retries.' === $message ) {
			return 'session_persistence_failure';
		}

		return 'adapter_error';
	}

	/**
	 * Restrict diagnostic identifiers to printable, bounded ASCII.
	 *
	 * @param string $value Diagnostic identifier.
	 */
	private function sanitize_identifier( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.:\\/\-]/', '_', $value );
		return substr( is_string( $value ) ? $value : '', 0, 128 );
	}

	/**
	 * Restrict severity labels to the Adapter contract's documented set.
	 *
	 * @param string $type Requested severity.
	 */
	private function normalize_type( string $type ): string {
		$type = strtolower( $type );
		return in_array( $type, array( 'error', 'warning', 'info', 'debug' ), true ) ? $type : 'error';
	}
}
