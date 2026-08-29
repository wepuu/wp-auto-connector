<?php
/**
 * Isolated WordPress function stubs for unit tests.
 *
 * @package WPAutoConnector
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_AUTO_CONNECTOR_VERSION', '0.1.0-test' );
	define( 'WP_AUTO_CONNECTOR_DIR', dirname( __DIR__ ) . '/' );

	$GLOBALS['wp_auto_test_hooks']              = array();
	$GLOBALS['wp_auto_test_registered_ability'] = null;
	$GLOBALS['wp_auto_test_can_read']           = false;
	$GLOBALS['wp_auto_test_logged_in']          = false;
	$GLOBALS['wp_auto_test_is_ssl']             = true;

	class WP_Ability {}
	class WP_REST_Server {}

	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code, string $message, array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}

	function wp_register_ability(): void {}
	function rest_get_server(): void {}
}

namespace WP\MCP\Core {
	final class McpAdapter {
		public const VERSION = '0.6.1';
		public static int $instance_calls = 0;

		public static function instance(): self {
			++self::$instance_calls;
			return new self();
		}
	}
}

namespace WPAuto\Connector\Abilities\Site {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
	}

	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['wp_auto_test_registered_ability'] = array(
			'name' => $name,
			'args' => $args,
		);
	}

	function current_user_can( string $capability ): bool {
		return 'read' === $capability && $GLOBALS['wp_auto_test_can_read'];
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace WPAuto\Connector\Diagnostics {
	function get_bloginfo( string $show ): string {
		return 'version' === $show ? '6.9-test' : '';
	}

	function is_ssl(): bool {
		return $GLOBALS['wp_auto_test_is_ssl'];
	}
}

namespace WPAuto\Connector\Mcp {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
	}

	function is_user_logged_in(): bool {
		return $GLOBALS['wp_auto_test_logged_in'];
	}

	function current_user_can( string $capability ): bool {
		return 'read' === $capability && $GLOBALS['wp_auto_test_can_read'];
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Diagnostics/EnvironmentDiagnostics.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Site/SiteHealthAbility.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpAdapterLoader.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpServerRegistrar.php';
}
