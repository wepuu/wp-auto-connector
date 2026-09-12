=== WePuu Auto Connector ===
Contributors: wpauto
Tags: mcp, ai, automation, remote management, developer tools
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to compatible AI clients through secure, permission-aware capabilities.

== Description ==

WePuu Auto Connector is the free WordPress-side connector for WP-Auto.

The project is designed to expose carefully scoped WordPress capabilities to compatible AI clients while preserving WordPress permissions and providing a path to optional WP-Auto cloud automation services.

Phase 1.2 provides a validated authenticated direct MCP endpoint at `/wp-json/wp-auto/mcp` with read-only site-health, site-info, posts-search, post-get, pages-search, page-get, categories-list, and tags-list tools. It uses normal WordPress authentication and requires the authenticated user to have the `read` capability. Non-public posts and pages also require WordPress object-level authorization. Application Passwords over HTTPS are the remote access baseline.

Phase 1.3 is formally sealed on `main` after contract, Create Draft, `modified_gmt` compatibility, Draft Update, mutation security, and full integration validation. Its twelve-tool baseline includes authenticated Post/Page Create Draft and draft-only Post/Page Update. Create operations always produce drafts owned by the authenticated user and use persistent idempotency claims.

Phase 1.4 adds bounded, permission-aware image Media Search/Get, authenticated image Upload, narrow metadata update, draft featured-image assignment, and caller-triggered remote image import. Upload and import accept only JPEG, PNG, GIF, WebP, or AVIF, enforce the smaller of the site's upload limit and 10 MiB, use WordPress Core media APIs, and support only an optional editable Post/Page draft parent. Remote import applies independent public-destination, DNS, redirect, timeout, byte, and real-file validation. Persistent idempotency prevents duplicate retries and private attribution is bounded.

Phase 1.5.1 through Phase 1.5.3 add authenticated Category Create, Tag Create, and draft-Post taxonomy assignment for the built-in `category` and `post_tag` taxonomies. Creation requires the current user's WordPress term-management capability, uses persistent idempotency, and records private bounded attribution. Assignment requires the taxonomy's actual assignment capability plus Post/object edit checks, replaces one bounded ID set with an expected-set precondition, and records private bounded attribution. Categories accept an optional validated parent; Tags are non-hierarchical. Custom taxonomies, implicit term creation, term update/delete, empty-set clearing, and publishing are not exposed.

Phase 1.6 adds provider-neutral `wp-auto-seo-get` and draft-only `wp-auto-seo-update`, extending the Direct MCP runtime to exactly 23 ordered tools. Get reads bounded explicit title, description, canonical URL, focus keywords, and index/follow data on authorized built-in Posts/Pages. Update is restricted to authorized drafts and uses a best-effort state token plus final-state verification. Rank Math 1.0.278 is the only admitted provider; provider-native MCP/REST, arbitrary metadata, published-content SEO mutation, and outbound requests remain unavailable.

No publish, delete, arbitrary content/file write, generic URL fetching, cloud, telemetry, or automation operation is included. Remote import runs only when an authenticated caller explicitly invokes it with a caller-selected URL; it is not a background or WP-Auto Cloud request.

= Privacy and external services =

The plugin does not contact WP-Auto or any other external service automatically. When an authenticated caller invokes the remote image import tool, the WordPress site makes a bounded HTTP(S) request to the caller-selected public destination to retrieve one image. The destination can observe the site's egress IP and WordPress HTTP user agent. The request does not include caller credentials, cookies, authorization headers, or arbitrary client headers. WP-Auto Cloud is not involved.

Future optional cloud features will require explicit administrator action before any site data is transmitted. Before those features are released, this section will document what data is sent, when it is sent, why it is required, and links to the applicable service terms and privacy policy.

== Installation ==

1. Upload the `wepuu-auto-connector` directory to `/wp-content/plugins/` or install the plugin ZIP through WordPress Admin.
2. Activate WePuu Auto Connector.
3. Open Settings > WePuu Auto Connector.
4. Confirm that Abilities API, MCP Adapter, REST API, and HTTPS diagnostics are available.
5. Create a WordPress Application Password for the account that will connect, then configure the MCP client with `https://example.com/wp-json/wp-auto/mcp`.

== Frequently Asked Questions ==

= Does this version connect to an external service? =

Only when an authenticated caller explicitly invokes remote image import. That request goes to the caller-selected destination and is subject to the fixed SSRF and image validation policy; there is no background request or WP-Auto Cloud connection.

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
* Added bounded, permission-aware `wp-auto-media-search`, `wp-auto-media-get`, and authenticated `wp-auto-media-upload` image tools.
* Added permission-aware `wp-auto-media-update` for narrowly allowlisted image presentation metadata with optimistic concurrency.
* Added authenticated transport and per-ability `read` capability checks.
* Added caller-triggered `wp-auto-media-import-url` with bounded public URL, DNS, redirect, byte, and image validation.
* Added authenticated `wp-auto-category-create` with capability checks, optional validated parents, persistent idempotency, and private bounded attribution.
* Added authenticated `wp-auto-tag-create` with capability checks, persistent idempotency, and private bounded attribution.
* Added authenticated `wp-auto-taxonomy-assign` for bounded exact Category/Tag replacement on draft Posts with expected-set concurrency, invariant checks, and private bounded attribution.
* Added provider-neutral `wp-auto-seo-get` for bounded Rank Math 1.0.278 SEO reads.
* Added draft-only `wp-auto-seo-update` with state-token concurrency, protected-state verification, and private bounded attribution.
