<?php
/**
 * Private MCP runtime build tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Proves that the generated private runtime matches the locked source trees.
 */
final class PrivateMcpRuntimeBuildTest extends TestCase {
	/** Verify manifest, namespace, hooks, session key, and every source copy. */
	public function test_generated_runtime_is_complete_and_current(): void {
		$runtime_root = dirname( __DIR__ ) . '/vendor/wp-auto-mcp-runtime';
		$manifest     = json_decode( $this->read_file( $runtime_root . '/manifest.json' ), true );

		self::assertSame(
			array(
				'wordpress/mcp-adapter'    => '0.6.1',
				'wordpress/php-mcp-schema' => '0.1.3',
			),
			$manifest['packages'] ?? null
		);
		self::assertSame( 'WPAuto\\Connector\\PrivateMcp', $manifest['namespace_prefix'] ?? null );
		self::assertSame( 'wp_auto_connector_mcp_adapter_', $manifest['private_hook_prefix'] ?? null );

		$trees = array(
			dirname( __DIR__ ) . '/vendor/wordpress/mcp-adapter/includes' => $runtime_root . '/mcp-adapter',
			dirname( __DIR__ ) . '/vendor/wordpress/php-mcp-schema/src'  => $runtime_root . '/php-mcp-schema',
		);

		foreach ( $trees as $source_root => $target_root ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source_root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $source ) {
				if ( 'php' !== strtolower( (string) $source->getExtension() ) ) {
					continue;
				}
				$relative = substr( $source->getPathname(), strlen( $source_root ) + 1 );
				$target   = $target_root . '/' . str_replace( '\\', '/', $relative );
				self::assertFileExists( $target );
				self::assertSame(
					$this->transform_source( $this->read_file( $source->getPathname() ) ),
					$this->read_file( $target ),
					$relative
				);
			}
		}

		$adapter = $this->read_file( $runtime_root . '/mcp-adapter/Core/McpAdapter.php' );
		self::assertStringContainsString( "do_action( 'wp_auto_connector_mcp_adapter_init'", $adapter );
		self::assertStringNotContainsString( "do_action( 'mcp_adapter_init'", $adapter );

		$sessions = $this->read_file( $runtime_root . '/mcp-adapter/Transport/Infrastructure/SessionManager.php' );
		self::assertStringContainsString( "'wp_auto_connector_mcp_adapter_sessions'", $sessions );
		self::assertStringNotContainsString( "'mcp_adapter_sessions'", $sessions );
	}

	/**
	 * Read one local fixture or generated source file.
	 *
	 * @param string $path Absolute local file path.
	 */
	private function read_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture; no URL or WordPress filesystem context.
		return (string) file_get_contents( $path );
	}

	/**
	 * Apply the deterministic source transformation used by the build script.
	 *
	 * @param string $contents Locked upstream PHP source.
	 */
	private function transform_source( string $contents ): string {
		$contents = str_replace(
			array( 'WP\\McpSchema', 'WP\\MCP' ),
			array( 'WPAuto\\Connector\\PrivateMcp\\WP\\McpSchema', 'WPAuto\\Connector\\PrivateMcp\\WP\\MCP' ),
			$contents
		);
		$contents = str_replace( 'mcp_adapter_', 'wp_auto_connector_mcp_adapter_', $contents );

		return preg_replace(
			"/(\\\\WP_CLI::add_command\\(\\s*)'mcp-adapter'/",
			"$1'wp-auto-mcp-adapter'",
			$contents
		) ?? $contents;
	}
}
