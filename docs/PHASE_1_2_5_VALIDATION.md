# Phase 1.2.5 Eight-Tool Public Contract Audit

## Baseline and verdict

- Starting baseline: clean, synchronized `main@ee2cf7d`
- Audit branch: `chore/phase-1-2-5-read-contract-audit`
- Audit date: 2026-08-31
- Endpoint: `/wp-json/wp-auto/mcp`
- Verdict: **PASS - ready for review/commit**

The Phase 1.2.5 audit identified one pre-existing schema drift: `wp-auto/site-health` did not explicitly register the strict empty-object input schema required by the frozen Phase 1.2 shared-schema contract, so MCP Adapter 0.6.1 produced a non-strict minimal object schema. The mismatch was subsequently reviewed and approved for remediation before Phase 1.2.5 was finalized.

The Ability now registers the same strict empty object used by Site Info. A valid empty-object call remains unchanged, while additional arguments are rejected. No Ability name, MCP tool, output field, capability, mutation surface, external request, telemetry behavior, or other runtime functionality was added.

## Exact eight-tool allowlist

| WordPress Ability | MCP tool | Ability permission | Semantics |
| --- | --- | --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` | `read` | Read-only |
| `wp-auto/site-info` | `wp-auto-site-info` | `read` | Read-only |
| `wp-auto/posts-search` | `wp-auto-posts-search` | `read`, then per-object eligibility | Read-only |
| `wp-auto/post-get` | `wp-auto-post-get` | `read`, then target-object eligibility | Read-only |
| `wp-auto/pages-search` | `wp-auto-pages-search` | `read`, then per-object eligibility | Read-only |
| `wp-auto/page-get` | `wp-auto-page-get` | `read`, then target-object eligibility | Read-only |
| `wp-auto/categories-list` | `wp-auto-categories-list` | `read` | Read-only |
| `wp-auto/tags-list` | `wp-auto-tags-list` | `read` | Read-only |

The registrar asserts exact ordered equality and `count === 8`. Its resources and prompts arrays are empty. There is no wildcard, namespace discovery, Adapter default exposure, third-party ability, or generic REST proxy.

## Public schema matrix

Every input and output is an object with `additionalProperties=false`. Search and list inputs are optional and receive server defaults; Get inputs require `id`.

| MCP tool | Exact input properties | Exact top-level output |
| --- | --- | --- |
| `wp-auto-site-health` | none | `wordpress_version`, `php_version`, `connector_version`, `abilities_api_available`, `mcp_adapter_available`, `mcp_adapter_version`, `rest_api_available`, `https` |
| `wp-auto-site-info` | none | `site_name`, `site_description`, `site_url`, `home_url`, `language`, `timezone`, `permalink_structure`, `multisite` |
| `wp-auto-posts-search` | `search`, `status`, `page`, `per_page`, `orderby`, `order` | `items`, `page`, `per_page`, `returned`, `has_more` |
| `wp-auto-post-get` | required `id` | `id`, `type`, `status`, `slug`, `title`, `excerpt`, `content`, `link`, `author_id`, `date_gmt`, `modified_gmt`, `featured_media_id`, `categories`, `tags` |
| `wp-auto-pages-search` | `search`, `status`, `page`, `per_page`, `orderby`, `order` | `items`, `page`, `per_page`, `returned`, `has_more` |
| `wp-auto-page-get` | required `id` | `id`, `type`, `status`, `slug`, `title`, `excerpt`, `content`, `link`, `author_id`, `date_gmt`, `modified_gmt`, `featured_media_id`, `parent_id` |
| `wp-auto-categories-list` | `search`, `page`, `per_page`, `orderby`, `order`, `hide_empty` | `items`, `page`, `per_page`, `returned`, `has_more` |
| `wp-auto-tags-list` | `search`, `page`, `per_page`, `orderby`, `order`, `hide_empty` | `items`, `page`, `per_page`, `returned`, `has_more` |

Search items contain exactly `id`, `title`, `slug`, `status`, `link`, `author_id`, `date_gmt`, and `modified_gmt`. Category items contain exactly `id`, `name`, `slug`, `description`, `count`, and `parent_id`; Tag items omit `parent_id`. Automated assertions freeze field types, defaults, enums, minimums, maximums, required fields, and nested `additionalProperties` values.

All Abilities register `readonly=true`, `destructive=false`, and `idempotent=true`. MCP Adapter 0.6.1 exposes the corresponding wire hints `readOnlyHint=true`, `destructiveHint=false`, and `idempotentHint=true` for all eight tools.

## Authentication and authorization boundaries

Remote Direct MCP uses WordPress Application Password HTTP Basic authentication over HTTPS. Local HTTP was used only with `WP_ENVIRONMENT_TYPE=local`. Transport permission and Ability permission remain separate checks:

- anonymous transport: `wp_auto_connector_authentication_required`, HTTP 401;
- authenticated identity without `read`: `wp_auto_connector_insufficient_capability`, HTTP 403;
- every Ability entry callback explicitly requires `current_user_can( 'read' )`.

Posts and Pages apply final target authorization through `current_user_can( 'read_post', $id )`. Password-protected content additionally requires `current_user_can( 'edit_post', $id )`. Search and Get share this eligibility path, so Search visibility is a subset of the corresponding Get visibility. Missing, wrong-type, unauthorized, and protected-but-not-editable targets share existence-hiding semantics.

## Pagination and taxonomy boundaries

Content Search applies the logical offset after final object authorization. It reads at most 100 raw candidates per query, at most 1,000 candidates and ten queries per request, uses a stable ID secondary order, and returns `wp_auto_pagination_window_exceeded` rather than an unproven page.

Taxonomy List fixes `category` or `post_tag` server-side and uses `per_page + 1`, `per_page <= 50`, `hierarchical=false`, `pad_counts=false`, and `update_term_meta_cache=false`. Its 1,000-position window rejects an unsafe deep page before installing `terms_clauses` or calling `get_terms()`. Stable `name`, `count`, and `slug` orders use a same-direction `term_id` tie-breaker scoped to the marked WP-Auto query and removed in `finally`; `id` uses native `term_id` ordering.

Term `count` is the WordPress-maintained `WP_Term->count`, not a per-user readable-content count. Flat `hide_empty` uses that direct count, so a parent is not retained solely because a descendant is non-empty. No term metadata is queried or returned.

## Error matrix

| Code/surface | Semantic status | Meaning |
| --- | --- | --- |
| Adapter schema/protocol error | Adapter-managed | MCP input fails the published JSON schema; it is not rewritten as a WP-Auto service code |
| `wp_auto_invalid_request` | 400 | Service input is malformed or arithmetic cannot be represented |
| `wp_auto_pagination_window_exceeded` | 400 | A bounded Content or Taxonomy page cannot be safely established |
| `wp_auto_content_not_found` | 404 | Content is missing, wrong type, unauthorized, or protected but not editable |
| `wp_auto_taxonomy_query_failed` | 500 | Fixed taxonomy query failed; internal details are hidden |
| `wp_auto_connector_authentication_required` | HTTP 401 transport | WordPress identity is absent |
| `wp_auto_connector_insufficient_capability` | HTTP 403 transport | Authenticated identity lacks `read` |

Adapter 0.6.1 can return Ability/service failures as MCP tool results with `isError=true` while the outer MCP HTTP exchange remains successful. The semantic status above belongs to the WordPress error contract and is not a promise that every MCP application error changes the outer HTTP status.

## Negative surface audit

Plugin-owned runtime code contains no MCP-reachable content/taxonomy/media/user/settings mutation, publishing, scheduling, deletion, arbitrary REST proxy, arbitrary `WP_Query`/`WP_Term_Query` input, SQL interface, filesystem operation, shell, WP-CLI, plugin/theme administration, or remote executable loading.

No plugin-owned runtime path calls WP-Auto Cloud, an AI provider, analytics, telemetry, tracking, or another outbound service. The Direct MCP HTTP response is between the configured client and the user's WordPress site.

## Production loading and dependency boundary

The production plugin entrypoint explicitly loads both read services, all eight Ability classes, categories, Adapter loader, registrar, admin page, and `Plugin`. `Plugin::boot()` registers all eight Abilities before initializing the dedicated Adapter integration. Tests use the same production classes and do not provide a separate production-only registration path.

The locked official `wordpress/mcp-adapter` dependency remains 0.6.1 and is isolated behind `McpAdapterLoader`/`McpServerRegistrar`. Jetpack Autoloader permits compatible shared dependency selection, an already loaded compatible Adapter is preferred, and no handwritten MCP protocol server exists. Composer files, dependency versions, plugin version, minimum versions, and CI are unchanged.

As re-checked on 2026-08-31, MCP Adapter is still not available as a WordPress.org `Requires Plugins` dependency. Composer bundling therefore remains a release consideration, not a Phase 1.2.5 contract blocker.

## Historical evidence correction

The two Phase 1.2.3 execution/quality-gate dates copied as `2026-08-29` were corrected to `2026-08-31`, matching commit and PR history. Historical MCP protocol versions were preserved because separate runs legitimately negotiated different supported versions.

## Automated verification

Executed on 2026-08-31:

- Focused Ability tests: 26 tests, 245 assertions passed.
- Focused Content service tests: 67 tests, 341 assertions passed.
- Focused Taxonomy service tests: 31 tests, 149 assertions passed.
- Focused registrar tests: 3 tests, 18 assertions passed.
- Full PHPUnit: 132 tests, 769 assertions passed.
- `composer validate --strict`: passed.
- WordPress Coding Standards: 34 files passed.
- `composer audit --locked`: passed; no security vulnerability advisories found.
- `git diff --check`: passed.

## Targeted live MCP audit

Validated with a disposable WordPress 6.9 / PHP 8.1.34 / MariaDB 11.8.9 environment and bundled MCP Adapter 0.6.1:

| Check | Result |
| --- | --- |
| Authenticated `initialize` | HTTP 200 |
| `notifications/initialized` | HTTP 202 |
| Negotiated protocol | `2025-11-25` |
| `tools/list` | HTTP 200; exact eight-name set (registrar unit tests freeze order) |
| Wire schemas | All eight exact input/output property sets and strict `additionalProperties=false` |
| Wire annotations | All eight exact read-only/destructive/idempotent Hint semantics |
| Happy-path invocation | All eight tools returned successful MCP tool results |
| Site Health strict input | `{}` succeeded; an unexpected property returned `isError=true` |
| Anonymous initialize | HTTP 401 |
| Authenticated identity without `read` | HTTP 403 |
| Session termination | HTTP 200 |

All temporary users, Application Passwords, fixtures, containers, database and WordPress volumes, network, and scripts were deleted. Plugin Check was not installed and was not downloaded for this checkpoint.

## Open release considerations

- Phase 1.2.6 full integration/security and client validation remains required before Phase 1.2 is complete.
- Plugin Check, clean-install/minimum-version testing, separately installed Adapter coexistence, distribution ZIP review, and final WordPress.org dependency review remain pre-release gates.
- No open item above blocks the frozen Phase 1.2 read-only public contract.
