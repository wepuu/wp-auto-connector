<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector;

use WPAuto\Connector\Abilities\Content\ContentAbilityCategory;
use WPAuto\Connector\Abilities\Content\PageGetAbility;
use WPAuto\Connector\Abilities\Content\PageCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PagesSearchAbility;
use WPAuto\Connector\Abilities\Content\PageUpdateAbility;
use WPAuto\Connector\Abilities\Content\PostGetAbility;
use WPAuto\Connector\Abilities\Content\PostCreateDraftAbility;
use WPAuto\Connector\Abilities\Content\PostsSearchAbility;
use WPAuto\Connector\Abilities\Content\PostUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaAbilityCategory;
use WPAuto\Connector\Abilities\Media\MediaGetAbility;
use WPAuto\Connector\Abilities\Media\MediaSearchAbility;
use WPAuto\Connector\Abilities\Media\MediaUploadAbility;
use WPAuto\Connector\Abilities\Media\MediaImportUrlAbility;
use WPAuto\Connector\Abilities\Media\MediaUpdateAbility;
use WPAuto\Connector\Abilities\Media\MediaSetFeaturedAbility;
use WPAuto\Connector\Abilities\Site\SiteHealthAbility;
use WPAuto\Connector\Abilities\Site\SiteInfoAbility;
use WPAuto\Connector\Abilities\Seo\SeoAbilityCategory;
use WPAuto\Connector\Abilities\Seo\SeoGetAbility;
use WPAuto\Connector\Abilities\Taxonomy\CategoriesListAbility;
use WPAuto\Connector\Abilities\Taxonomy\CategoryCreateAbility;
use WPAuto\Connector\Abilities\Taxonomy\TagCreateAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAssignAbility;
use WPAuto\Connector\Abilities\Taxonomy\TagsListAbility;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAbilityCategory;
use WPAuto\Connector\Admin\AdminPage;
use WPAuto\Connector\Mcp\McpAdapterLoader;
use WPAuto\Connector\Mcp\McpServerRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services and lifecycle checks.
 */
final class Plugin {
	/**
	 * Singleton plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Return the singleton plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enforce minimum runtime versions during activation.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_AUTO_CONNECTOR_FILE ) );
			wp_die(
				esc_html__( 'WePuu Auto Connector requires PHP 8.1 or later.', 'wepuu-auto-connector' ),
				esc_html__( 'Plugin activation failed', 'wepuu-auto-connector' ),
				array( 'back_link' => true )
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.9', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_AUTO_CONNECTOR_FILE ) );
			wp_die(
				esc_html__( 'WePuu Auto Connector requires WordPress 6.9 or later.', 'wepuu-auto-connector' ),
				esc_html__( 'Plugin activation failed', 'wepuu-auto-connector' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Register the direct MCP services.
	 */
	public function boot(): void {
		( new ContentAbilityCategory() )->register();
		( new MediaAbilityCategory() )->register();
		( new TaxonomyAbilityCategory() )->register();
		( new SeoAbilityCategory() )->register();
		( new SiteHealthAbility() )->register();
		( new SiteInfoAbility() )->register();
		( new PostsSearchAbility() )->register();
		( new PostGetAbility() )->register();
		( new PagesSearchAbility() )->register();
		( new PageGetAbility() )->register();
		( new PostCreateDraftAbility() )->register();
		( new PageCreateDraftAbility() )->register();
		( new PostUpdateAbility() )->register();
		( new PageUpdateAbility() )->register();
		( new CategoriesListAbility() )->register();
		( new TagsListAbility() )->register();
		( new CategoryCreateAbility() )->register();
		( new TagCreateAbility() )->register();
		( new TaxonomyAssignAbility() )->register();
		( new MediaSearchAbility() )->register();
		( new MediaGetAbility() )->register();
		( new MediaUploadAbility() )->register();
		( new MediaUpdateAbility() )->register();
		( new MediaSetFeaturedAbility() )->register();
		( new MediaImportUrlAbility() )->register();
		( new SeoGetAbility() )->register();
		( new McpServerRegistrar() )->register();
		McpAdapterLoader::initialize();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
