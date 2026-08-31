# MCP Tool Catalog

This file is the public contract catalog for Phase 1. Canonical WordPress ability names remain the domain contract; MCP tool names are the official Adapter's protocol-safe representation.

## Phase 1.1

| Ability | MCP tool | Type | WordPress capability | Status |
| --- | --- | --- | --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` | Read-only | `read` | Implemented |

Dedicated server endpoint: `/wp-json/wp-auto/mcp`.

The server explicitly allowlists this ability. It does not expose other registered abilities or proxy WordPress REST routes. The ability is not marked public for the MCP Adapter default server.

Output fields:

- `wordpress_version` (string)
- `php_version` (string)
- `connector_version` (string)
- `abilities_api_available` (boolean)
- `mcp_adapter_available` (boolean)
- `mcp_adapter_version` (string)
- `rest_api_available` (boolean)
- `https` (boolean)

## Phase 1.2

Phase 1.2 retains the implemented Phase 1.1 tool and adds seven read-only tools. All eight approved tools are implemented as of Phase 1.2.4. Full schemas, authorization rules, privacy behavior, and errors are frozen in `docs/PHASE_1_2_READ_TOOLS.md`.

| Ability | MCP tool | Type | WordPress capability baseline | Status |
| --- | --- | --- | --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` | Read-only | `read` | Implemented |
| `wp-auto/site-info` | `wp-auto-site-info` | Read-only | `read` | Implemented |
| `wp-auto/posts-search` | `wp-auto-posts-search` | Read-only | `read` plus final per-object eligibility; protected posts also require `edit_post` | Implemented |
| `wp-auto/post-get` | `wp-auto-post-get` | Read-only | `read_post`; protected posts also require `edit_post` | Implemented |
| `wp-auto/pages-search` | `wp-auto-pages-search` | Read-only | `read` plus final per-object eligibility; protected pages also require `edit_post` | Implemented |
| `wp-auto/page-get` | `wp-auto-page-get` | Read-only | `read_post`; protected pages also require `edit_post` | Implemented |
| `wp-auto/categories-list` | `wp-auto-categories-list` | Read-only | `read` | Implemented |
| `wp-auto/tags-list` | `wp-auto-tags-list` | Read-only | `read` | Implemented |

The dedicated server must explicitly allowlist only these abilities. It must not expose third-party/default abilities, generic WordPress REST routes, or any mutation tool.

## Phase 1.3

| Ability | Type | WordPress capability baseline |
| --- | --- | --- |
| `wp-auto/post-create-draft` | Mutation | `edit_posts` |
| `wp-auto/post-update` | Mutation | `edit_post` for target object |
| `wp-auto/page-create-draft` | Mutation | `edit_pages` |
| `wp-auto/page-update` | Mutation | `edit_post` for target object |

Publishing is intentionally not part of these abilities.

## Phase 1.4

| Ability | Type | WordPress capability baseline |
| --- | --- | --- |
| `wp-auto/media-search` | Read-only | `upload_files` or narrower read policy |
| `wp-auto/media-get` | Read-only | visibility policy |
| `wp-auto/media-upload` | Mutation | `upload_files` |
| `wp-auto/media-import-url` | Mutation/open-world | `upload_files` + SSRF/media policy |
| `wp-auto/media-update` | Mutation | attachment edit capability |
| `wp-auto/media-set-featured` | Mutation | target post edit capability |

## Phase 1.5

| Ability | Type | WordPress capability baseline |
| --- | --- | --- |
| `wp-auto/category-create` | Mutation | taxonomy management capability |
| `wp-auto/tag-create` | Mutation | taxonomy management capability |
| `wp-auto/taxonomy-assign` | Mutation | target + term assignment capabilities |

## Phase 1.6

| Ability | Type | WordPress capability baseline |
| --- | --- | --- |
| `wp-auto/seo-get` | Read-only | target object read/edit policy |
| `wp-auto/seo-update` | Mutation | target object edit capability |

## Explicit Phase 1 exclusions

Do not expose:
- publish;
- permanent delete;
- plugin/theme install, update, activate, edit;
- WordPress core update;
- user/role administration;
- arbitrary settings writes;
- arbitrary REST proxy;
- arbitrary SQL;
- arbitrary filesystem access;
- arbitrary WP-CLI/shell/PHP execution.
