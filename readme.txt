=== WP-Auto Connector ===
Contributors: wpauto
Tags: mcp, ai, automation, remote management, developer tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to compatible AI clients through secure, permission-aware capabilities.

== Description ==

WP-Auto Connector is the free WordPress-side connector for WP-Auto.

The project is designed to expose carefully scoped WordPress capabilities to compatible AI clients while preserving WordPress permissions and providing a path to optional WP-Auto cloud automation services.

Phase 1.2.2 provides an authenticated direct MCP endpoint at `/wp-json/wp-auto/mcp` with read-only site-health, site-info, posts-search, and post-get tools. It uses normal WordPress authentication and requires the authenticated user to have the `read` capability. Non-public posts also require WordPress object-level authorization. Application Passwords over HTTPS are the remote access baseline.

No content write, publish, delete, cloud, telemetry, or automation operation is included in this version.

= Privacy and external services =

This version does not contact WP-Auto or any other external service automatically.

Future optional cloud features will require explicit administrator action before any site data is transmitted. Before those features are released, this section will document what data is sent, when it is sent, why it is required, and links to the applicable service terms and privacy policy.

== Installation ==

1. Upload the `wp-auto-connector` directory to `/wp-content/plugins/` or install the plugin ZIP through WordPress Admin.
2. Activate WP-Auto Connector.
3. Open Settings > WP-Auto Connector.
4. Confirm that Abilities API, MCP Adapter, REST API, and HTTPS diagnostics are available.
5. Create a WordPress Application Password for the account that will connect, then configure the MCP client with `https://example.com/wp-json/wp-auto/mcp`.

== Frequently Asked Questions ==

= Does this version connect to an external service? =

No. Version 0.1.0 does not make external service requests.

= Is the plugin free? =

The WordPress connector is distributed under GPLv2 or later. Optional hosted WP-Auto services may be offered separately when they provide substantive cloud functionality.

== Changelog ==

= 0.1.0 =
* Initial project skeleton.
* Added activation compatibility checks.
* Added the Phase 1.1 direct MCP server foundation.
* Added the read-only `wp-auto-site-health` MCP tool.
* Added the read-only `wp-auto-site-info` MCP tool.
* Added bounded, permission-aware `wp-auto-posts-search` and `wp-auto-post-get` MCP tools.
* Added authenticated transport and per-ability `read` capability checks.
