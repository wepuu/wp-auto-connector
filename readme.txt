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

Phase 1.2 provides a validated authenticated direct MCP endpoint at `/wp-json/wp-auto/mcp` with read-only site-health, site-info, posts-search, post-get, pages-search, page-get, categories-list, and tags-list tools. It uses normal WordPress authentication and requires the authenticated user to have the `read` capability. Non-public posts and pages also require WordPress object-level authorization. Application Passwords over HTTPS are the remote access baseline.

Phase 1.3.0 mutation contracts are frozen and Phase 1.3.1 now provides authenticated Post/Page Create Draft tools: `wp-auto-post-create-draft` and `wp-auto-page-create-draft`. Create operations always produce drafts owned by the authenticated user and use persistent idempotency claims. Update, publishing, deletion, and other mutation tools remain out of scope; the next checkpoint is the narrow Phase 1.3.2.0 `modified_gmt` sentinel compatibility amendment before Draft Update implementation.

No publish, delete, arbitrary content write, cloud, telemetry, or automation operation is included in this version; the two Create Draft tools are the only scoped content mutations.

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
* Added bounded, permission-aware `wp-auto-pages-search` and `wp-auto-page-get` MCP tools.
* Added bounded `wp-auto-categories-list` and `wp-auto-tags-list` MCP tools.
* Added authenticated `wp-auto-post-create-draft` and `wp-auto-page-create-draft` MCP tools with capability checks, persistent idempotency, invariant guards, and local mutation attribution.
* Added authenticated transport and per-ability `read` capability checks.
