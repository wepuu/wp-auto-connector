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
use WPAuto\Connector\Abilities\Content\PageGetAbility;
use WPAuto\Connector\Abilities\Content\PageCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PageUpdateAbility;
use WPAuto\Connector\Abilities\Content\PagesSearchAbility;
use WPAuto\Connector\Abilities\Content\PostGetAbility;
use WPAuto\Connector\Abilities\Content\PostCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PostUpdateAbility;
use WPAuto\Connector\Abilities\Content\PostsSearchAbility;
use WPAuto\Connector\Abilities\Site\SiteHealthAbility;
use WPAuto\Connector\Abilities\Site\SiteInfoAbility;
use WPAuto\Connector\Abilities\Taxonomy\CategoriesListAbility;
use WPAuto\Connector\Abilities\Taxonomy\TagsListAbility;
use WPAuto\Connector\Mcp\McpServerRegistrar;

/**
 * Covers custom-server registration and transport authorization.
 */
final class McpServerRegistrarTest extends TestCase {
	/**
	 * Reset WordPress stub state.
	 */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']                           = array();
		$GLOBALS['wp_auto_test_logged_in']                       = false;
		$GLOBALS['wp_auto_test_can_read']                        = false;
		$GLOBALS['wp_auto_test_is_ssl']                          = true;
		$GLOBALS['wp_auto_test_environment_type']                = 'production';
		$GLOBALS['wp_auto_test_application_passwords_available'] = false;
		$GLOBALS['wp_auto_test_is_user_logged_in_calls']         = 0;
		$GLOBALS['wp_auto_test_mcp_current_user_can_calls']      = 0;
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
	public function test_registers_a_custom_http_server_with_exact_allowlist(): void {
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
		self::assertSame(
			array(
				SiteHealthAbility::NAME,
				SiteInfoAbility::NAME,
				PostsSearchAbility::NAME,
				PostGetAbility::NAME,
				PagesSearchAbility::NAME,
				PageGetAbility::NAME,
				CategoriesListAbility::NAME,
				TagsListAbility::NAME,
				PostCreateDraftAbility::NAME,
				PageCreateDraftAbility::NAME,
				PostUpdateAbility::NAME,
				PageUpdateAbility::NAME,
			),
			$adapter->arguments[9]
		);
		self::assertCount( 12, $adapter->arguments[9] );
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

	/** Unsupported non-local transport is denied before identity/capability checks. */
	public function test_transport_denies_unsupported_transport_even_when_availability_is_true(): void {
		$GLOBALS['wp_auto_test_is_ssl']                          = false;
		$GLOBALS['wp_auto_test_environment_type']                = 'production';
		$GLOBALS['wp_auto_test_application_passwords_available'] = true;
		$GLOBALS['wp_auto_test_logged_in']                       = true;
		$GLOBALS['wp_auto_test_can_read']                        = true;

		$result = ( new McpServerRegistrar() )->check_transport_permission();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'wp_auto_connector_authentication_required', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_is_user_logged_in_calls'] );
		self::assertSame( 0, $GLOBALS['wp_auto_test_mcp_current_user_can_calls'] );
	}

	/** Local plain HTTP remains usable for an authenticated read-capable identity. */
	public function test_transport_allows_local_plain_http(): void {
		$GLOBALS['wp_auto_test_is_ssl']           = false;
		$GLOBALS['wp_auto_test_environment_type'] = 'local';
		$GLOBALS['wp_auto_test_logged_in']        = true;
		$GLOBALS['wp_auto_test_can_read']         = true;

		self::assertTrue( ( new McpServerRegistrar() )->check_transport_permission() );
	}
}
