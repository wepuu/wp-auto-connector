<?php
/**
 * Registers the dedicated WP-Auto MCP server with the official adapter.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Mcp;

use WPAuto\Connector\Abilities\Content\PageGetAbility;
use WPAuto\Connector\Abilities\Content\PageCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PagesSearchAbility;
use WPAuto\Connector\Abilities\Content\PageUpdateAbility;
use WPAuto\Connector\Abilities\Content\PostGetAbility;
use WPAuto\Connector\Abilities\Content\PostCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PostsSearchAbility;
use WPAuto\Connector\Abilities\Content\PostUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaGetAbility;
use WPAuto\Connector\Abilities\Media\MediaSearchAbility;
use WPAuto\Connector\Abilities\Media\MediaUploadAbility;
use WPAuto\Connector\Abilities\Media\MediaImportUrlAbility;
use WPAuto\Connector\Abilities\Media\MediaUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaSetFeaturedAbility;
use WPAuto\Connector\Abilities\Site\SiteHealthAbility;
use WPAuto\Connector\Abilities\Site\SiteInfoAbility;
use WPAuto\Connector\Abilities\Taxonomy\CategoriesListAbility;
use WPAuto\Connector\Abilities\Taxonomy\CategoryCreateAbility;
use WPAuto\Connector\Abilities\Taxonomy\TagCreateAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAssignAbility;
use WPAuto\Connector\Abilities\Taxonomy\TagsListAbility;
use WPAuto\Connector\PrivateMcp\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WPAuto\Connector\PrivateMcp\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WPAuto\Connector\PrivateMcp\WP\MCP\Transport\HttpTransport;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the isolated official Adapter custom-server integration.
 */
final class McpServerRegistrar {
	public const SERVER_ID       = 'wp-auto-direct';
	public const ROUTE_NAMESPACE = 'wp-auto';
	public const ROUTE           = 'mcp';
	public const ADAPTER_HOOK    = 'wp_auto_connector_mcp_adapter_init';

	/**
	 * Register the custom-server hook.
	 */
	public function register(): void {
		add_action( self::ADAPTER_HOOK, array( $this, 'register_server' ) );
	}

	/**
	 * Register the server using the v0.6.x custom-server API.
	 *
	 * @param object $adapter Official MCP Adapter instance.
	 */
	public function register_server( object $adapter ): void {
		if ( ! McpAdapterLoader::is_compatible() || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'WP-Auto Direct MCP',
			'Direct access to explicitly exposed WP-Auto WordPress abilities.',
			WP_AUTO_CONNECTOR_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
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
				MediaSearchAbility::NAME,
				MediaGetAbility::NAME,
				MediaUploadAbility::NAME,
				MediaUpdateAbility::NAME,
				MediaSetFeaturedAbility::NAME,
				MediaImportUrlAbility::NAME,
				CategoryCreateAbility::NAME,
				TagCreateAbility::NAME,
				TaxonomyAssignAbility::NAME,
			),
			array(),
			array(),
			array( $this, 'check_transport_permission' )
		);
	}

	/**
	 * Require a WordPress identity with the narrow direct-MCP capability.
	 *
	 * @return bool|WP_Error
	 */
	public function check_transport_permission() {
		if ( ! $this->is_supported_transport() || ! function_exists( 'wp_is_application_passwords_supported' ) || ! wp_is_application_passwords_supported() ) {
			return new WP_Error(
				'wp_auto_connector_authentication_required',
				__( 'WordPress authentication is required for the WP-Auto MCP server.', 'wepuu-auto-connector' ),
				array( 'status' => 401 )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wp_auto_connector_authentication_required',
				__( 'WordPress authentication is required for the WP-Auto MCP server.', 'wepuu-auto-connector' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'wp_auto_connector_insufficient_capability',
				__( 'The authenticated WordPress user cannot read site data.', 'wepuu-auto-connector' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Require HTTPS except in WordPress's explicit local environment.
	 */
	private function is_supported_transport(): bool {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}

		return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
	}
}
