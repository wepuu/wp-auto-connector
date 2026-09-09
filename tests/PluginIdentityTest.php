<?php
/**
 * Public plugin identity tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;

/** Locks the approved WordPress.org identity without changing runtime contracts. */
final class PluginIdentityTest extends TestCase {
	/** The distribution entrypoint and readme use the approved public identity. */
	public function test_distribution_identity_is_consistent(): void {
		$root       = dirname( __DIR__ );
		$entrypoint = $root . '/wepuu-auto-connector.php';

		self::assertFileExists( $entrypoint );
		self::assertFileDoesNotExist( $root . '/wp-auto-connector.php' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$plugin = file_get_contents( $entrypoint );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = file_get_contents( $root . '/readme.txt' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$composer = file_get_contents( $root . '/composer.json' );

		self::assertIsString( $plugin );
		self::assertIsString( $readme );
		self::assertIsString( $composer );
		self::assertStringContainsString( 'Plugin Name:       WePuu Auto Connector', $plugin );
		self::assertStringContainsString( 'Author:            WePuu', $plugin );
		self::assertStringContainsString( 'Text Domain:       wepuu-auto-connector', $plugin );
		self::assertStringStartsWith( '=== WePuu Auto Connector ===', $readme );
		self::assertStringContainsString( '`wepuu-auto-connector` directory', $readme );
		self::assertStringContainsString( '"name": "wepuu/wepuu-auto-connector"', $composer );
	}

	/** All production translations use the distribution text domain. */
	public function test_production_translation_domain_matches_slug(): void {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/wepuu-auto-connector.php' );
		$files = array_merge( $files, $this->php_files( $root . '/src' ) );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file );
			self::assertIsString( $content );
			self::assertStringNotContainsString( "'wp-auto-connector'", $content, $file );
		}
	}

	/** The identity migration preserves existing client and stored-data contracts. */
	public function test_runtime_compatibility_identifiers_remain_frozen(): void {
		$root = dirname( __DIR__ );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$mcp = file_get_contents( $root . '/src/Mcp/McpServerRegistrar.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ownership = file_get_contents( $root . '/src/Content/AtomicOwnershipStore.php' );

		self::assertIsString( $mcp );
		self::assertIsString( $ownership );
		self::assertStringContainsString( "SERVER_ID       = 'wp-auto-direct'", $mcp );
		self::assertStringContainsString( "ROUTE_NAMESPACE = 'wp-auto'", $mcp );
		self::assertStringContainsString( 'wp_auto_connector_idempotency_', $ownership );
		self::assertStringContainsString( "'wp-auto/post-create-draft'", $ownership );
		self::assertStringContainsString( "'wp-auto/media-import-url'", $ownership );
	}

	/**
	 * Return PHP files below one directory.
	 *
	 * @param string $directory Directory to scan.
	 * @return list<string>
	 */
	private function php_files( string $directory ): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );
		return $files;
	}
}
