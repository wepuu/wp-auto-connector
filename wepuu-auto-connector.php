<?php
/**
 * Plugin Name:       WePuu Auto Connector
 * Plugin URI:        https://wp-auto.com/
 * Description:       Connect WordPress to compatible AI clients through secure, permission-aware capabilities.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            WePuu
 * Author URI:        https://wp-auto.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wepuu-auto-connector
 *
 * @package WPAutoConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AUTO_CONNECTOR_VERSION', '0.1.0' );
define( 'WP_AUTO_CONNECTOR_FILE', __FILE__ );
define( 'WP_AUTO_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AUTO_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

require_once WP_AUTO_CONNECTOR_DIR . 'src/Diagnostics/EnvironmentDiagnostics.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/ContentReadService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/CreateDraftContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/UpdateDraftContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/AtomicOwnershipStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/CreateIdempotencyStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/MutationAuditStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Content/ContentMutationService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaReadContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaReadService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaUploadContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaIngestionIdempotencyStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaMutationAuditStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaUploadService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaImportContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteDnsResolverInterface.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteHttpClientInterface.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteClockInterface.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteUrlPolicy.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/WordPressRemoteDnsResolver.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/WordPressRemoteHttpClient.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/SystemRemoteClock.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteMediaDownloaderInterface.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/RemoteMediaDownloader.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaImportService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaUpdateContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaUpdateService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaFeaturedContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Media/MediaFeaturedService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyReadService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyCreateIdempotencyStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyMutationAuditStore.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/CategoryCreateContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TagCreateContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyMutationService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyAssignContract.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Taxonomy/TaxonomyAssignmentService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Seo/SeoProviderInterface.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Seo/RankMathSeoProvider.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Seo/SeoProviderRegistry.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Seo/SeoReadService.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Site/SiteHealthAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Site/SiteInfoAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/ContentAbilityCategory.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PostsSearchAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PostGetAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PagesSearchAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PageGetAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PostCreateDraftAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PageCreateDraftAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PostUpdateAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Content/PageUpdateAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaAbilityCategory.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaSearchAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaGetAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaUploadAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaImportUrlAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaUpdateAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Media/MediaSetFeaturedAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/TaxonomyAbilityCategory.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/CategoriesListAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/TagsListAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/CategoryCreateAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/TagCreateAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Taxonomy/TaxonomyAssignAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Seo/SeoAbilityCategory.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Seo/SeoGetAbility.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Mcp/McpAdapterLoader.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Mcp/McpServerRegistrar.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Admin/AdminPage.php';
require_once WP_AUTO_CONNECTOR_DIR . 'src/Plugin.php';

WPAuto\Connector\Mcp\McpAdapterLoader::load();

register_activation_hook( __FILE__, array( 'WPAuto\\Connector\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		WPAuto\Connector\Plugin::instance()->boot();
	}
);
