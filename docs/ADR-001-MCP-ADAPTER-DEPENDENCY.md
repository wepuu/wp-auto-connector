# ADR-001 - Official MCP Adapter Integration

Status: Accepted for Phase 1, re-check before WordPress.org release.
Date: 2026-08-29

## Context

WP-Auto Connector needs a standards-compliant direct MCP server. WordPress 6.9 includes Abilities API in core. The official `WordPress/mcp-adapter` project maps WordPress abilities to MCP tools/resources/prompts and provides HTTP/STDIO transports.

At initialization time, the official MCP Adapter documentation states that using it as a normal WordPress `Requires Plugins` dependency is not yet supported because MCP Adapter is not yet listed on WordPress.org. The same documentation permits bundling MCP Adapter as a Composer dependency and recommends collision-safe autoloading such as Jetpack Autoloader or dependency prefixing.

The latest stable MCP Adapter release observed during initialization is v0.6.1 (2026-08-13).

Phase 1.1 re-verified v0.6.1 as the latest stable release on 2026-08-29. The tagged release exposes custom servers through `mcp_adapter_init` and `McpAdapter::create_server()`. Version 0.6.1 contains only a release-ZIP repair over 0.6.0 and does not change the API, hooks, or protocol behavior.

## Decision

Phase 1 uses the official MCP Adapter architecture instead of implementing a new MCP protocol stack.

During development:
1. Codex may add `wordpress/mcp-adapter` as a pinned compatible Composer runtime dependency.
2. The plugin must prefer a separately loaded compatible MCP Adapter when present and avoid double initialization/conflicting versions.
3. The build must include the required runtime code for a standalone WP-Auto Connector installation while MCP Adapter remains unavailable as a WordPress.org dependency.
4. The exact loading/bundling approach must be covered by clean-install and coexistence tests.
5. Before WordPress.org submission, re-check whether MCP Adapter is now listed in the directory. If so, reconsider `Requires Plugins` and remove unnecessary bundling where migration is safe.

## Phase 1.1 implementation

- Runtime dependency is pinned to `wordpress/mcp-adapter:0.6.1`.
- `automattic/jetpack-autoloader` provides the upstream-recommended `vendor/autoload_packages.php` collision-safe loader.
- `McpAdapterLoader` loads bundled packages only when the Adapter class is not already available, then accepts compatible 0.6.x APIs.
- `McpServerRegistrar` contains all custom-server API coupling; WP-Auto ability classes do not depend on Adapter classes.
- Production builds must run `composer install --no-dev --optimize-autoloader` and include `vendor/`. The distribution ignore file intentionally no longer excludes `vendor/`.
- The distribution ignore file excludes `vendor/wordpress/mcp-adapter/mcp-adapter.php`; that standalone plugin bootstrap is not used by the Composer-library integration and would otherwise introduce a nested plugin header.

## Consequences

Positive:
- follows WordPress official AI/MCP direction;
- avoids maintaining a parallel MCP protocol implementation;
- allows WP-Auto to focus on abilities, safety, client UX, and SaaS value.

Risks:
- bundled dependency can create class/version collisions if loaded incorrectly;
- WordPress.org review requires dependency/license/source scrutiny;
- adapter is pre-1.0 and its API may change.

Mitigations:
- pin compatible versions;
- isolate integration behind `McpAdapterLoader`/`McpServerRegistrar` style components;
- test with and without separately installed MCP Adapter;
- document every adapter version bump;
- keep WP-Auto public ability contracts independent of adapter internals.

## Rejected alternative

Implement a hand-written JSON-RPC/MCP server directly in WP-Auto Connector.

Rejected for Phase 1 because it duplicates protocol/security work already maintained by the official WordPress MCP Adapter and creates a larger long-term compatibility burden.
