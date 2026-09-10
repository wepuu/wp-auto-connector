# ADR-008: MCP Adapter Coexistence With SEO Providers

Status: **Accepted, implemented, and locally validated; integration seal pending**

Date: 2026-09-10

## Context

WePuu Auto Connector bundles the official `wordpress/mcp-adapter` 0.6.1 and
uses a dedicated custom server with an exact Ability allowlist. Rank Math SEO
1.0.278 bundles Adapter 0.5.0 and initializes its global `WP\MCP` singleton
during normal plugin bootstrap. The result is load-order dependent: when Rank
Math loads first, WePuu cannot replace that class, cannot accept its version,
and can miss the one-time `mcp_adapter_init` registration window.

Yoast SEO 28.4 and All in One SEO 5.0.1.1 also register provider-owned
Abilities. They do not bundle this Adapter package, but their public Abilities
must never enter WePuu's dedicated MCP server.

## Decision

1. WePuu continues to require the official MCP Adapter 0.6.1 behavior and does
   not widen compatibility to 0.5.x.
2. Phase 1.6.0.1 builds the locked Adapter and its protocol dependencies
   into a private WePuu namespace. Its class identity and initialization entry
   point must not depend on the global `WP\MCP` singleton or the public
   `mcp_adapter_init` hook.
3. The private instance creates only the `wp-auto-direct` custom server at
   `/wp-json/wp-auto/mcp`. Its default server is disabled and its allowlist
   remains the ordered 21-tool baseline until Phase 1.6.1.
4. Provider-native Abilities, tools, resources, and prompts are never copied,
   discovered, or forwarded into that server. The provider plugins retain
   ownership of any endpoints they independently expose.
5. The private build must be reproducible from `composer.lock`, keep upstream
   license/source notices, exclude standalone plugin bootstraps, and document
   every namespace/hook transformation for WordPress.org review.
6. If the private runtime cannot be loaded or its exact version cannot be
   proven, WePuu fails closed: no Direct MCP route is registered. It must not
   fall back to an incompatible provider-bundled Adapter.

## Required validation

The live matrix covers WePuu alone; WePuu with each admitted provider; all
three providers together; both activation/load orders; and single-site plus
Multisite. WordPress 6.9 and 7.1 on PHP 8.1 must prove:

- no fatal error, duplicate route, or PHP warning;
- authenticated discovery exposes exactly the ordered 21 WePuu tools;
- no provider-native tool, resource, or prompt is exposed by `/wp-auto/mcp`;
- anonymous transport is rejected and existing permission checks remain intact;
- sessions are isolated by user and site;
- provider endpoints and normal WordPress behavior are not modified;
- Plugin Check 2.1.0 reports zero errors and zero warnings for the release-like
  WePuu package.

## Rejected alternatives

- Accept Rank Math's Adapter 0.5.0: it is outside the verified dependency
  range and would weaken the accepted session and Multisite baseline.
- Replay `mcp_adapter_init`: this re-enters unrelated callbacks and may create
  duplicate/default servers.
- Depend on activation order: WordPress installations cannot guarantee it.
- Modify or disable provider code: WePuu does not own third-party plugins.
- Implement a second hand-written MCP protocol stack: ADR-001 continues to
  require the official Adapter architecture.

## Consequences

Phase 1.6.1 was blocked until this coexistence gate passed. The private build
adds packaging and dependency-review work but removes provider load order from
the public Direct MCP contract. The private Adapter retains its normal,
per-user MCP session metadata under the isolated exact key
`wp_auto_connector_mcp_adapter_sessions` (with the Adapter's per-site suffix on
Multisite). Explicit uninstall removes and independently verifies only these
exact WePuu-owned keys through WordPress metadata APIs; provider-owned
`mcp_adapter_sessions*` keys remain untouched. No SEO Ability, SEO persistence,
outbound request, or new tool is authorized by this ADR.
