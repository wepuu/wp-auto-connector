# Phase 1.2.2 Posts Search/Get Validation

## Public contract

- Abilities: `wp-auto/posts-search` and `wp-auto/post-get`
- MCP tools: `wp-auto-posts-search` and `wp-auto-post-get`
- Endpoint: `/wp-json/wp-auto/mcp`
- Ability entry permission: `current_user_can( 'read' )`
- Object permission: `current_user_can( 'read_post', $post_id )`
- Type: read-only, non-destructive, idempotent

The dedicated server allowlist is exactly:

1. `wp-auto/site-health` -> `wp-auto-site-health`
2. `wp-auto/site-info` -> `wp-auto-site-info`
3. `wp-auto/posts-search` -> `wp-auto-posts-search`
4. `wp-auto/post-get` -> `wp-auto-post-get`

`tools/list` therefore returns exactly four tools. No Adapter default tools, third-party abilities, Pages, taxonomy, mutation, Cloud, or generic REST/query proxy is exposed.

## Search contract and query strategy

Search accepts only `search`, `status`, `page`, `per_page`, `orderby`, and `order`. PHP applies the defaults `""`, `publish`, `1`, `10`, `modified`, and `desc` independently of JSON Schema. Status, ordering, and direction use explicit allowlists. The post type is fixed internally to `post`; `per_page` is enforced as 1 through 50 in both schema and service code.

If the service is called outside normal schema validation with malformed or out-of-range input, it returns the stable application error `wp_auto_invalid_request` with semantic status 400 and the generic message `The request parameters are invalid.` The Adapter does not expose the internal error code/status in its MCP tool result.

The query uses `posts_per_page = per_page + 1`, `no_found_rows = true`, `perm = readable`, a safely calculated logical offset, and no unlimited query. Public ordering maps only to `date`, `modified`, `title`, or `ID`.

WordPress 6.9 Core inspection established that `perm = readable` limits private posts but does not limit another author's draft, pending, or future posts. The service therefore derives query-level scope from the `post` post-type capability object:

- private: constrain to the current author unless the identity has `read_private_posts`;
- draft/pending: constrain to the current author unless it has `edit_others_posts`;
- future: constrain to the current author unless it has both `edit_others_posts` and `edit_published_posts`;
- publish: use Core's readable public scope.

Every candidate then passes `current_user_can( 'read_post', $id )` as defense-in-depth. Search returns only `id`, `title`, `slug`, `status`, `link`, `author_id`, `date_gmt`, and `modified_gmt`. It returns no content, excerpt, metadata, totals, or total pages.

### `has_more`

The logical offset is `(page - 1) * per_page`, rejected before multiplication if it would overflow. The capability-aware query requests at most `per_page + 1` candidates. After the object-level check, the first `per_page` records are returned and `has_more` is true only if another authorized record exists. Because inaccessible statuses/authors are excluded before offset/limit and the extra candidate must also pass `read_post`, inaccessible objects cannot change logical pages or `has_more`.

## Post-get contract and errors

Post Get accepts exactly one integer `id` greater than zero. It retrieves through `get_post()`, verifies `post_type === 'post'`, checks `read_post`, and only then returns stored content. The exact output is `id`, `type`, `status`, `slug`, `title`, `excerpt`, `content`, `link`, `author_id`, `date_gmt`, `modified_gmt`, `featured_media_id`, `categories`, and `tags`.

Content and excerpt are unfiltered stored values. Links come from `get_permalink()`, featured media from `get_post_thumbnail_id()`, and category/tag arrays contain only Core term IDs. Term lookup errors become deterministic empty arrays. Arbitrary post metadata is never read or returned.

Missing IDs, wrong-type objects, and unauthorized objects all return `WP_Error( 'wp_auto_content_not_found', 'The requested content was not found.', array( 'status' => 404 ) )`. MCP Adapter 0.6.1 represents this semantic application 404 as an outer HTTP 200 tool result with `isError: true` and one text content block containing the generic message. Its serialized MCP payload omits `structuredContent` and does not expose the WordPress error code or semantic status.

Search and Get return the raw WordPress GMT database strings from `post_date_gmt` and `post_modified_gmt`, consistently for the same object. No request-time timestamp is generated.

## WordPress 6.9 registration prerequisite

Live startup validation confirmed that WordPress requires an ability category to be registered before an ability references it. The plugin registers the collision-resistant `wp-auto-content` category on `wp_abilities_api_categories_init`, then registers the two content abilities on `wp_abilities_api_init`. Production and test bootstraps explicitly load all three classes.

## Automated validation

Executed on 2026-08-29:

- PHPUnit: 52 tests, 291 assertions passed.
- WordPress Coding Standards: 22 files passed.

Coverage includes both strict schemas, server defaults, all allowed enums, rejected statuses/orderings/excess properties, search length, page bounds, `per_page` 1/50/51, offset overflow, fixed query arguments, no unlimited query, exact outputs, stored dates/content, taxonomy errors, ability entry permission, published/own/other/private/pending/future visibility, existence hiding, multiple pages, empty/final/beyond-final pages, interleaved hidden objects, permission-safe `has_more`, Core category registration, production class loading, and the exact four-ability allowlist.

## Live WordPress and MCP validation

Validated against a disposable WordPress 6.9 / PHP 8.1 / MariaDB environment with bundled MCP Adapter 0.6.1. Fixture creation used WP-CLI outside MCP; all exposed MCP tools remained read-only. Temporary Application Passwords used HTTP Basic Authentication in a local-only environment.

| Check | Result |
| --- | --- |
| Authenticated `initialize` | HTTP 200; server `WP-Auto Direct MCP` |
| `notifications/initialized` | HTTP 202 |
| `tools/list` | Exactly four expected tools |
| Posts search -> Post Get | Published search ID retrieved with exact full contract |
| Subscriber-like identity | Published visible/get succeeds; draft/private search empty; other draft get hidden |
| Author-like identity | Own draft/pending/future/private visible and gettable; other author's equivalents hidden |
| Editor-like identity | Core capabilities allowed other draft/pending/future/private search and get |
| Permission-aware pagination | Own draft IDs paginated across three logical pages despite interleaved hidden drafts; `has_more` true, true, false, then empty/false |
| Missing/wrong type/unauthorized | Same text result and `isError: true`; outer HTTP 200 |
| Anonymous initialize | HTTP 401 |
| Authenticated identity without `read` | HTTP 403 |

For every identity tested, every search result was gettable by that same identity, and objects rejected by Get were absent from Search. The Apache test environment used `/index.php?rest_route=/wp-auto/mcp`, the query-route form of the same registered `/wp-json/wp-auto/mcp` endpoint.

The disposable containers, database volume, users, posts, Application Passwords, and network were deleted after validation. No test credential was written to the repository.

## Quality gates

Executed on 2026-08-29 after implementation and documentation updates:

- `composer validate --strict`: passed; `composer.json` is valid.
- `composer test`: passed; 52 tests and 291 assertions.
- `composer lint`: passed; 22 files checked.
- `composer audit --locked`: passed; no security vulnerability advisories found.
- `git diff --check`: passed.

Plugin Check was not available in this disposable environment and is not a Phase 1.2.2 blocker.
