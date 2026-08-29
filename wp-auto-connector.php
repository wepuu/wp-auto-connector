<?php
/**
 * Plugin Name:       WP-Auto Connector
 * Plugin URI:        https://wp-auto.com/
 * Description:       Connect WordPress to compatible AI clients through secure, permission-aware capabilities.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            WP-Auto
 * Author URI:        https://wp-auto.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-auto-connector
 * Domain Path:       /languages
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
require_once WP_AUTO_CONNECTOR_DIR . 'src/Abilities/Site/SiteHealthAbility.php';
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
