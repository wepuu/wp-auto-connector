# Phase 1.2.4 Categories/Tags List Validation

## Public contract

- Abilities: `wp-auto/categories-list` and `wp-auto/tags-list`
- MCP tools: `wp-auto-categories-list` and `wp-auto-tags-list`
- Endpoint: `/wp-json/wp-auto/mcp`
- Fixed taxonomies: `category` and `post_tag`
- Ability and transport permission: `current_user_can( 'read' )`
- Type: read-only, non-destructive, idempotent

The dedicated server allowlist now contains exactly eight abilities: the existing six Site/Content abilities plus the two taxonomy list abilities. No Adapter default tools, third-party abilities, taxonomy mutations, generic REST routes, or arbitrary registered abilities are exposed.

## Implementation and security behavior

The taxonomy abilities use a dedicated `TaxonomyReadService`; they do not reuse the content object's authorization scanner or accept arbitrary `WP_Term_Query` arguments. The service independently validates the six frozen inputs, fixes the taxonomy internally, rejects extra parameters, and issues one query with `number = per_page + 1`, `per_page <= 50`, and a safely calculated offset.

Taxonomy queries use the internal `MAX_TERM_QUERY_WINDOW = 1000` bound. The window measures `offset + number`, including the extra lookahead row used for `has_more`, and limits pathological deep SQL `OFFSET` traversal while retaining ordinary interactive term browsing. Integer multiplication and lookahead addition are checked for representability first; invalid arithmetic returns `wp_auto_invalid_request`. Representable requests outside the operational window return `wp_auto_pagination_window_exceeded` with semantic status 400. Rejection occurs before the stable-order filter is installed or `get_terms()` is called, and the limit is not exposed as an MCP input.

`hierarchical` is always false so WordPress applies the SQL limit even for Categories. `pad_counts` and term-meta cache priming are disabled. `hide_empty` therefore uses direct stored term counts rather than retaining an empty parent because of a non-empty descendant. `name`, `count`, and `slug` orderings receive a same-direction `term_id` tie-breaker through a query-scoped filter that is removed in `finally`; `id` maps directly to `term_id`.

Category output contains exactly `id`, `name`, `slug`, `description`, `count`, and `parent_id`. Tag output omits `parent_id`. Neither result exposes term meta, taxonomy, links, totals, or total pages. WordPress query errors become the generic `wp_auto_taxonomy_query_failed` error with semantic status 500.

## Automated validation

Executed on 2026-08-31:

- PHPUnit: 132 tests, 719 assertions passed.
- WordPress Coding Standards: 34 files passed.
- All 93 pre-Phase-1.2.4 tests passed as a regression gate.

Coverage includes both canonical abilities, hooks, taxonomy category, strict schemas, annotations, baseline `read`, defaults/enums/bounds, search length, strict `hide_empty`, rejected extra/query-injection inputs, offset and lookahead overflow, pre-query deep-page rejection for Categories and Tags, exact supported/unsupported window boundaries, fixed taxonomy, one bounded query, direct-count hide-empty semantics, exact Category/Tag outputs, raw stored descriptions, no totals/meta, `per_page + 1` pagination, deterministic ID tie-breaking, filter cleanup, generic query errors, production loading, and the exact eight-ability allowlist.

## Live WordPress and MCP validation

Validated against a disposable WordPress 6.9 / PHP 8.1.34 / MariaDB 11 environment with bundled MCP Adapter 0.6.1. Authentication used temporary WordPress Application Passwords over HTTP only after setting `WP_ENVIRONMENT_TYPE` to `local`.

| Check | Result |
| --- | --- |
| Authenticated `initialize` | HTTP 200 |
| `notifications/initialized` | HTTP 202 |
| Negotiated MCP protocol | `2025-06-18` |
| `tools/list` | Exactly eight approved tools |
| Taxonomy input schemas | Exactly `search`, `page`, `per_page`, `orderby`, `order`, `hide_empty`; extra properties rejected |
| Categories list | Exact six fields; stored description and parent/child relationship verified |
| Tags list | Exact five fields; no `parent_id` |
| Bounded hide-empty behavior | Empty parent excluded while its directly non-empty child remained visible |
| Stable pagination | Equal-count pages returned distinct, deterministic term records |
| Subscriber-like identity | Authenticated `read` identity listed Categories successfully |
| Invalid taxonomy override | Rejected by the strict tool schema |
| Existing six-tool regression | Site Health/Info, Posts Search/Get, and Pages Search/Get all succeeded |
| Read-only guarantee | Category/tag IDs, parents, and counts were identical before and after MCP calls |
| Anonymous initialize | HTTP 401 |
| Authenticated identity without `read` | HTTP 403 |

A focused follow-up validation of the deep-pagination guard used the same disposable WordPress 6.9 / PHP 8.1.34 stack. With MCP protocol `2025-11-25`, `tools/list` still returned exactly eight tools, `wp-auto-categories-list` accepted the maximum supported request (`page=999`, `per_page=1`), and rejected the first unsupported request (`page=1000`, `per_page=1`) as an MCP application error. Both tool calls completed through the authenticated HTTP endpoint; the out-of-window request was rejected by the service before any term query.

All containers, database and WordPress volumes, isolated network, fixtures, users, and Application Passwords were deleted after validation. No credential, temporary script, or Docker artifact remains in the repository.

## Quality gates

Executed on 2026-08-31 after implementation and documentation updates:

- `composer validate --strict`: passed.
- `composer test`: passed; 132 tests and 719 assertions.
- `composer lint`: passed; 34 files checked.
- `composer audit --locked`: passed; no security vulnerability advisories found.
- `git diff --check`: passed.

Plugin Check was not installed in the disposable environment. It was not downloaded for this task and remains an explicit pre-release check.
