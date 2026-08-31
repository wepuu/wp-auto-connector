# Phase 1.2.3 Pages Search/Get Validation

## Public contract

- Abilities: `wp-auto/pages-search` and `wp-auto/page-get`
- MCP tools: `wp-auto-pages-search` and `wp-auto-page-get`
- Endpoint: `/wp-json/wp-auto/mcp`
- Ability entry permission: `current_user_can( 'read' )`
- Object permission: `current_user_can( 'read_post', $page_id )`; password-protected pages additionally require `current_user_can( 'edit_post', $page_id )`
- Type: read-only, non-destructive, idempotent

The dedicated server allowlist contains exactly six abilities: `wp-auto/site-health`, `wp-auto/site-info`, `wp-auto/posts-search`, `wp-auto/post-get`, `wp-auto/pages-search`, and `wp-auto/page-get`. No Adapter default tools, third-party abilities, taxonomy tools, mutations, generic REST routes, or arbitrary registered abilities are exposed.

## Implementation and security behavior

Pages Search and Posts Search use the same bounded service path while fixing `post_type` internally to `page` or `post`. The service independently validates the frozen six search inputs, applies stable ordering with an ID tie-breaker, scans at most 100 raw candidates per query and 1,000 per request, and applies the logical offset only after final object authorization.

Query-level visibility scope uses the actual post type capability object. Page searches therefore use `read_private_pages`, `edit_others_pages`, and `edit_published_pages`, while Post behavior continues to use the corresponding Post primitives. This scope is only an optimization and defense-in-depth measure: every candidate must pass `current_user_can( 'read_post', $id )`, and a password-protected candidate must also pass `current_user_can( 'edit_post', $id )`.

Page Get accepts exactly one positive integer `id`, verifies `post_type === 'page'`, applies the same final eligibility rule, and only then returns stored content. Its exact fields are `id`, `type`, `status`, `slug`, `title`, `excerpt`, `content`, `link`, `author_id`, `date_gmt`, `modified_gmt`, `featured_media_id`, and `parent_id`. It returns no password, taxonomy, template, menu order, metadata, custom fields, or rendered front-end content.

Missing, wrong-type, unauthorized, and inaccessible password-protected targets all use `wp_auto_content_not_found` with semantic status 404 and the same generic message. MCP Adapter 0.6.1 serializes that application error as a tool result with `isError: true` without exposing the WordPress error code or status.

## Automated validation

Executed on 2026-08-29:

- PHPUnit: 93 tests, 514 assertions passed.
- WordPress Coding Standards: 26 files passed.
- All Phase 1.2.2 Posts tests passed unchanged as a regression gate.

Coverage includes the two canonical abilities, hooks, category, strict schemas, annotations, baseline `read`, all search defaults/enums/bounds and rejected extra/password inputs, fixed Page queries, Page capability primitives, lightweight search output, published and own/other non-public visibility, shared Search/Get eligibility, protected Pages, exact Page Get fields, root/child parent IDs, stored block content, cross-type and missing existence hiding, logical pagination after interleaved authorization failures, the 100/1,000 scan bounds, stable window errors, production loading, and the exact six-ability allowlist.

## Live WordPress and MCP validation

Validated against a disposable WordPress 6.9 / PHP 8.1.34 / MariaDB 11 environment with bundled MCP Adapter 0.6.1. Authentication used temporary WordPress Application Passwords over HTTP only after setting `WP_ENVIRONMENT_TYPE` to `local`.

The Editor role's actual primitive capabilities were recorded from Core rather than inferred from its name. Relevant values included `read`, `edit_pages`, `edit_others_pages`, `edit_published_pages`, `edit_private_pages`, and `read_private_pages`.

| Check | Result |
| --- | --- |
| Authenticated `initialize` | HTTP 200 |
| `notifications/initialized` | HTTP 202 |
| MCP protocol used after negotiation | `2025-11-25` |
| `tools/list` | Exactly six expected tools |
| Pages Search schema | Exactly `search`, `status`, `page`, `per_page`, `orderby`, `order` |
| Page Get schema | Exactly `id` |
| Author-like identity | Own draft/pending/future/private Page visible; another author's equivalents hidden |
| Editor-like identity | Both authors' draft/pending/future/private Pages visible through actual Page capabilities |
| Subscriber-like identity | Published Page readable; protected Page absent from Search and hidden by Get |
| Protected Page, administrator | Search/Get permitted through object-level `edit_post`; no password field exposed |
| Page Get output | Exact 13 fields, stored block content, root `parent_id = 0`, child points to parent |
| Search output | Exact lightweight eight fields; no content, parent, totals, or metadata |
| Missing and wrong type | Same generic tool error with `isError: true` |
| Permission-aware pagination | Temporary `map_meta_cap` denial hid A; page 1 returned B/true and page 2 returned C/false |
| Posts regression | Published Post Search -> Get succeeded with stored content |
| Anonymous initialize | HTTP 401 |
| Authenticated identity without `read` | HTTP 403 |

The disposable `map_meta_cap` file was removed immediately after its pagination check. All containers, database and WordPress volumes, network, fixtures, users, and Application Passwords were deleted after validation; no credentials or Docker artifacts remain in the repository.

## Quality gates

Executed on 2026-08-29 after implementation and documentation updates:

- `composer validate --strict`: passed.
- `composer test`: passed; 93 tests and 514 assertions.
- `composer lint`: passed; 26 files checked.
- `composer audit --locked`: passed; no security vulnerability advisories found.
- `git diff --check`: passed.

Plugin Check was not already installed in the local disposable environment. It was not downloaded for this task and remains an explicit pre-release check.
