# Phase 1.2 Read-Only Tool Contracts

## Status and authority

Status: Phase 1.2.0 contract frozen.

This document is the authoritative public contract for Phase 1.2 read-only abilities and their MCP representations. Implementations, tests, and validation evidence must conform to it. A contract change requires an explicit documentation review before the affected implementation checkpoint proceeds.

## Goal

An authenticated MCP client can:

- understand basic site metadata;
- discover posts;
- read a specific post;
- discover pages;
- read a specific page;
- list categories;
- list tags;
- perform all of these operations without modifying WordPress state.

Phase 1.2 does not include content mutations, publishing, deletion, media operations, taxonomy mutations, SEO operations, Cloud MCP, Skills, automation, telemetry, or external WP-Auto requests.

## Dedicated server contract

The endpoint remains:

```text
/wp-json/wp-auto/mcp
```

The final Phase 1.2 server allowlist contains exactly these eight abilities, producing exactly these eight MCP tools:

| Ability | MCP tool |
| --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` |
| `wp-auto/site-info` | `wp-auto-site-info` |
| `wp-auto/posts-search` | `wp-auto-posts-search` |
| `wp-auto/post-get` | `wp-auto-post-get` |
| `wp-auto/pages-search` | `wp-auto-pages-search` |
| `wp-auto/page-get` | `wp-auto-page-get` |
| `wp-auto/categories-list` | `wp-auto-categories-list` |
| `wp-auto/tags-list` | `wp-auto-tags-list` |

No third-party ability, Adapter default ability, generic WordPress REST route, or additional registered ability may be exposed through this server. The existing `wp-auto/site-health` contract remains unchanged.

## Shared schema rules

Every input and output schema is a strict object schema: `type` is `object`, `additionalProperties` is `false`, and every returned field documented for that object is required. Clients cannot supply arbitrary `WP_Query` or `WP_Term_Query` arguments.

All date fields are strings containing WordPress GMT values. Phase 1.3 will use `modified_gmt` as the stable basis for optimistic concurrency.

### Search result envelope

Posts and pages search return exactly:

| Field | Type | Meaning |
| --- | --- | --- |
| `items` | array | Visible lightweight records for this page. |
| `page` | integer | Requested page, minimum 1. |
| `per_page` | integer | Effective page size, from 1 through 50. |
| `returned` | integer | Exact number of records in `items`. |
| `has_more` | boolean | Whether another page of records visible to this identity exists. |

`returned` must equal the length of `items`. The envelope must not include `total` or `total_pages`. `has_more` must be derived only from visible records and must not reveal inaccessible-object counts or existence.

Each search item contains exactly:

| Field | Type |
| --- | --- |
| `id` | integer |
| `title` | string |
| `slug` | string |
| `status` | string |
| `link` | string |
| `author_id` | integer |
| `date_gmt` | string |
| `modified_gmt` | string |

Search results never include full content, excerpts, arbitrary post meta, or custom fields.

### Taxonomy result envelope

Category and tag lists use the same exact envelope fields and semantics: `items`, `page`, `per_page`, `returned`, and `has_more`. Their default `per_page` is 20 and their enforced maximum is 50. They do not return `total` or `total_pages`.

## `wp-auto/site-health`

- MCP tool: `wp-auto-site-health`
- Type: read-only
- Permission baseline: `read`
- Input: an empty strict object with `properties: {}`

The output contains exactly:

| Field | Type |
| --- | --- |
| `wordpress_version` | string |
| `php_version` | string |
| `connector_version` | string |
| `abilities_api_available` | boolean |
| `mcp_adapter_available` | boolean |
| `mcp_adapter_version` | string |
| `rest_api_available` | boolean |
| `https` | boolean |

It must not return administrator email addresses, usernames, credentials, database information, filesystem paths, configuration values, or environment secrets.

## `wp-auto/site-info`

- MCP tool: `wp-auto-site-info`
- Type: read-only
- Permission baseline: `read`
- Input: an empty strict object with `properties: {}`

The output contains exactly:

| Field | Type | Source meaning |
| --- | --- | --- |
| `site_name` | string | WordPress site name. |
| `site_description` | string | WordPress site description. |
| `site_url` | string | WordPress site URL. |
| `home_url` | string | WordPress home URL. |
| `language` | string | Site language. |
| `timezone` | string | Canonical WordPress timezone string, equivalent to `wp_timezone_string()`. |
| `permalink_structure` | string | Configured permalink structure. |
| `multisite` | boolean | Whether WordPress multisite is active. |

It must not return administrator email addresses, usernames, account or Application Passwords, API keys, database or filesystem details, server secrets, `wp-config.php` values, plugin secrets, or private environment values.

## Content search contracts

`wp-auto/posts-search` and `wp-auto/pages-search` accept exactly these inputs:

| Field | Type | Constraints | Default |
| --- | --- | --- | --- |
| `search` | string | Maximum length 200. | `""` |
| `status` | string | One of `publish`, `draft`, `pending`, `private`, `future`. | `publish` |
| `page` | integer | Minimum 1. | 1 |
| `per_page` | integer | Minimum 1, maximum 50. | 10 |
| `orderby` | string | One of `date`, `modified`, `title`, `id`. | `modified` |
| `order` | string | One of `asc`, `desc`. | `desc` |

The schema and service layer must both enforce `per_page <= 50`; `-1` and other unlimited values are invalid. The statuses `any`, `inherit`, `trash`, and `auto-draft` are not accepted. The client cannot override the fixed post type.

### `wp-auto/posts-search`

- MCP tool: `wp-auto-posts-search`
- Fixed post type: `post`
- Permission baseline: authenticated identity with `read`, plus visibility enforcement for every returned object

Published posts may be returned when readable. A user may discover their own draft when WordPress permits it, but must not discover another user's draft without the relevant capability. Private posts require the WordPress capability for that object. Every returned post must independently pass the same effective read authorization used by `wp-auto/post-get`.

The shared password and logical-pagination rules described below apply identically to Posts Search/Get and Pages Search/Get.

### `wp-auto/pages-search`

- MCP tool: `wp-auto-pages-search`
- Fixed post type: `page`
- Permission baseline: authenticated identity with `read`, plus visibility enforcement for every returned object

WordPress page visibility and ownership/capability rules apply to every result. Every returned page must independently pass the same effective read authorization used by `wp-auto/page-get`.

### Shared final eligibility and logical pagination

Password-protected posts and pages accept no password input and expose no password field. An object is eligible only when the authenticated identity passes `current_user_can( 'read_post', $id )`; a password-protected object additionally requires `current_user_can( 'edit_post', $id )`. Search and Get apply this same final eligibility rule.

Logical pagination is applied after final object authorization. The implementation scans fixed raw chunks of at most 100 objects and examines at most 1,000 raw candidates per request. It collects at most `per_page + 1` authorized records after the requested logical offset. If the requested page or the density of rejected candidates prevents the service from proving that page within this bound, it returns `wp_auto_pagination_window_exceeded` with semantic status 400 instead of returning an incorrect page or `has_more` value. This limit permits normal interactive MCP pagination while bounding a request to at most ten WordPress queries and preventing unlimited scans.

## Content get contracts

Both get abilities accept only one required input:

| Field | Type | Constraints |
| --- | --- | --- |
| `id` | integer | Minimum 1. |

The target must exist, have the required fixed post type, and be readable by the authenticated WordPress identity. The implementation must perform target-object authorization equivalent to `current_user_can( 'read_post', $id )` before returning stored content.

The shared final eligibility rule above applies. Failure uses the same existence-hiding response as every other inaccessible target. Password values are never accepted or returned.

A missing object, wrong post type, or inaccessible object must produce the same public error code, `wp_auto_content_not_found`, with semantic HTTP status 404. The response must not reveal which condition occurred or disclose object existence.

### `wp-auto/post-get`

- MCP tool: `wp-auto-post-get`
- Required post type: `post`

The output contains exactly:

| Field | Type |
| --- | --- |
| `id` | integer |
| `type` | string, always `post` |
| `status` | string |
| `slug` | string |
| `title` | string |
| `excerpt` | string |
| `content` | string |
| `link` | string |
| `author_id` | integer |
| `date_gmt` | string |
| `modified_gmt` | string |
| `featured_media_id` | integer |
| `categories` | array of integers |
| `tags` | array of integers |

`content` and `excerpt` are stored WordPress content returned only after authorization. Arbitrary post meta and custom fields are excluded.

### `wp-auto/page-get`

- MCP tool: `wp-auto-page-get`
- Required post type: `page`

The output contains exactly:

| Field | Type |
| --- | --- |
| `id` | integer |
| `type` | string, always `page` |
| `status` | string |
| `slug` | string |
| `title` | string |
| `excerpt` | string |
| `content` | string |
| `link` | string |
| `author_id` | integer |
| `date_gmt` | string |
| `modified_gmt` | string |
| `featured_media_id` | integer |
| `parent_id` | integer |

Page results do not include categories, tags, arbitrary post meta, or custom fields.

## Taxonomy list contracts

Both taxonomy list abilities accept exactly:

| Field | Type | Constraints | Default |
| --- | --- | --- | --- |
| `search` | string | Maximum length 200. | `""` |
| `page` | integer | Minimum 1. | 1 |
| `per_page` | integer | Minimum 1, maximum 50. | 20 |
| `orderby` | string | One of `name`, `count`, `id`, `slug`. | `name` |
| `order` | string | One of `asc`, `desc`. | `asc` |
| `hide_empty` | boolean | No additional values accepted. | `false` |

The schema and service layer must both enforce `per_page <= 50`; unlimited term queries are prohibited.

### `wp-auto/categories-list`

- MCP tool: `wp-auto-categories-list`
- Fixed taxonomy: `category`
- Permission baseline: `read`

The client cannot override the taxonomy.

Each item contains exactly `id` (integer), `name` (string), `slug` (string), `description` (string), `count` (integer), and `parent_id` (integer).

### `wp-auto/tags-list`

- MCP tool: `wp-auto-tags-list`
- Fixed taxonomy: `post_tag`
- Permission baseline: `read`

The client cannot override the taxonomy.

Each item contains exactly `id` (integer), `name` (string), `slug` (string), `description` (string), and `count` (integer). Tags have no `parent_id` field.

Neither ability can create, edit, assign, or delete terms.

Both lists use one bounded `WP_Term_Query` through `get_terms()`. The taxonomy is fixed server-side, `number` is `per_page + 1`, and `hierarchical` is `false` so Core applies the SQL limit instead of loading an entire category hierarchy before slicing. Consequently, `hide_empty` uses each term's direct stored count and does not retain an empty parent solely because a descendant is non-empty. `pad_counts` and term-meta cache priming are disabled.

Taxonomy pagination also has an internal 1,000-position query window that includes the requested offset and the `per_page + 1` rows used to establish `has_more`. A valid integer page outside this operational window returns `wp_auto_pagination_window_exceeded` with semantic status 400 before a Core term query or stable-order filter is created. The public schema remains unchanged: `page` still has a minimum of 1, but pathological deep pages are intentionally unsupported.

For `name`, `count`, and `slug`, the requested primary order has a same-direction `term_id` tie-breaker scoped only to the WP-Auto query. `id` maps directly to `term_id`. This deterministic ordering prevents equal primary values from causing duplicates or gaps between pages. `count` is the value maintained by WordPress Core for the taxonomy; WP-Auto does not calculate a separate private-content or per-identity total.

## Authentication and authorization

The existing transport contract remains: remote clients use a WordPress Application Password with HTTP Basic authentication over HTTPS; anonymous requests are denied; and the transport requires `read`. Local development may use HTTP only as already documented.

Transport authorization and ability authorization remain separate defenses. Every ability has an explicit `permission_callback`. Site info, category list, and tag list require `read`. Content get abilities perform object-level authorization. Content search abilities must never return an object that would fail the corresponding get authorization.

Authentication is not authorization, and MCP never grants more authority than the authenticated WordPress identity. A generic `read` check alone is insufficient for non-public content.

Phase 1.2 validation must cover at least a published post, the caller's own draft, another author's draft, a private post, a published page, a private page, a missing ID, and a wrong-type ID. Search pagination metadata must not leak inaccessible content.

## Errors and validation

- Missing WordPress authentication keeps the existing transport response: HTTP 401.
- An authenticated identity lacking the transport `read` capability keeps the existing transport response: HTTP 403.
- Missing, wrong-type, and inaccessible content lookups all use `wp_auto_content_not_found` with semantic status 404.
- A content search page that cannot be established within the documented bounded authorization scan uses `wp_auto_pagination_window_exceeded` with semantic status 400.
- A taxonomy page outside the internal safe query window uses `wp_auto_pagination_window_exceeded` with semantic status 400 before querying Core.
- A fixed taxonomy query failure uses `wp_auto_taxonomy_query_failed` with semantic status 500 and a generic message that does not expose internal errors.
- Input rejection is driven through WordPress Abilities API and official MCP Adapter schema validation; Adapter protocol errors are distinct from the documented WP-Auto service error codes and Phase 1.2 does not add a separate protocol implementation.
- Any future application-specific error must use a documented `wp_auto_`-prefixed code.

## Read-only annotations

All Phase 1.2 abilities register WordPress Ability annotations `readonly=true`, `destructive=false`, and `idempotent=true`. Pinned MCP Adapter 0.6.1 maps these to the MCP wire annotations `readOnlyHint=true`, `destructiveHint=false`, and `idempotentHint=true`; the Ability keys and MCP keys are separate representations of the same frozen semantics.

## Implementation checkpoints

Implement and review Phase 1.2 in this order without combining checkpoints:

1. Phase 1.2.0 - freeze this contract.
2. Phase 1.2.1 - implement site info.
3. Phase 1.2.2 - implement posts search/get.
4. Phase 1.2.3 - implement pages search/get.
5. Phase 1.2.4 - implement categories/tags list and extend the dedicated server to the eight approved abilities.
6. Phase 1.2.5 - audit and freeze the exact eight-ability MCP allowlist, schemas, and security boundaries.
7. Phase 1.2.6 - complete integration and security validation.

## Definition of done

Phase 1.2 is complete only when all of the following are true:

- the endpoint remains `/wp-json/wp-auto/mcp`;
- `tools/list` returns exactly the eight tools in this contract;
- Application Password authentication over HTTPS is verified;
- transport authorization requires `read`;
- content retrieval and search enforce object visibility and applicable meta capabilities;
- every search/list operation is bounded, paginated, and enforces a maximum `per_page` of 50 in both schema and service code;
- inaccessible private content and its existence are not disclosed, including through pagination metadata;
- arbitrary post meta, custom fields, arbitrary queries, and generic REST routes are not exposed;
- no mutation, Cloud MCP, telemetry, automation, or external WP-Auto request is added;
- automated ability, schema, authorization, privacy, allowlist, and integration tests pass;
- `composer validate --strict`, PHPUnit, WordPress Coding Standards, `composer audit --locked`, and GitHub Actions pass;
- live MCP discovery and invocation pass for all seven new tools using the documented identities and negative scenarios;
- `docs/PHASE_1_2_VALIDATION.md` records the completed automated and live evidence.

## Explicit non-goals

Phase 1.2 does not implement or design in detail post creation, post update, page creation, page update, publishing, scheduling, deletion, media, remote media import, taxonomy creation, taxonomy assignment, SEO, Cloud MCP, SaaS pairing, OAuth, Skills, automation, Google Search Console integration, analytics, telemetry, billing, arbitrary post meta, arbitrary WordPress queries, generic REST proxying, or administrative operations.

## Next implementation checkpoint

Phase 1.2.6 only: complete the full read-only integration, permission, privacy, schema, security, and client validation required to close Phase 1.2. Do not add tools or begin Phase 1.3 mutation work in that task.
