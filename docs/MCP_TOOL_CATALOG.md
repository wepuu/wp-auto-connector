# MCP Tool Catalog

This file is the public contract catalog for Phase 1. Canonical WordPress ability names remain the domain contract; MCP tool names are the official Adapter's protocol-safe representation.

## Phase 1.1

| Ability | MCP tool | Type | WordPress capability | Status |
| --- | --- | --- | --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` | Read-only | `read` | Implemented |

Dedicated server endpoint: `/wp-json/wp-auto/mcp`.

The server explicitly allowlists WP-Auto abilities. It does not expose other registered abilities or proxy WordPress REST routes. The ability is not marked public for the MCP Adapter default server.

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

Phase 1.2 retains the implemented Phase 1.1 tool and adds seven read-only tools. All eight approved tools are implemented, contract-audited, and integration/security validated. Full schemas, authorization rules, privacy behavior, and errors are frozen in `docs/PHASE_1_2_READ_TOOLS.md`; completion evidence is indexed in `docs/PHASE_1_2_VALIDATION.md`.

| Ability | MCP tool | Type | WordPress capability baseline | Status |
| --- | --- | --- | --- | --- |
| `wp-auto/site-health` | `wp-auto-site-health` | Read-only | `read` | Implemented and validated |
| `wp-auto/site-info` | `wp-auto-site-info` | Read-only | `read` | Implemented and validated |
| `wp-auto/posts-search` | `wp-auto-posts-search` | Read-only | `read` plus final per-object eligibility; protected posts also require `edit_post` | Implemented and validated |
| `wp-auto/post-get` | `wp-auto-post-get` | Read-only | `read_post`; protected posts also require `edit_post` | Implemented and validated |
| `wp-auto/pages-search` | `wp-auto-pages-search` | Read-only | `read` plus final per-object eligibility; protected pages also require `edit_post` | Implemented and validated |
| `wp-auto/page-get` | `wp-auto-page-get` | Read-only | `read_post`; protected pages also require `edit_post` | Implemented and validated |
| `wp-auto/categories-list` | `wp-auto-categories-list` | Read-only | `read` | Implemented and validated |
| `wp-auto/tags-list` | `wp-auto-tags-list` | Read-only | `read` | Implemented and validated |

The dedicated server explicitly allowlists only these eight read-only abilities in the Phase 1.2 baseline; Phase 1.3 adds only the two validated Create Draft abilities below.

## Phase 1.3

Phase 1.3.0 froze the contracts in `docs/PHASE_1_3_MUTATION_CONTRACTS.md`. Phase 1.3.1 implemented and validated the two Create Draft abilities; the dedicated server now contains exactly ten tools. Phase 1.3.2.0 is formally sealed on `main`; Update abilities remain contract-frozen and unimplemented, with implementation next in Phase 1.3.2.

| Ability | MCP tool | Type | WordPress capability baseline | Status |
| --- | --- | --- | --- | --- |
| `wp-auto/post-create-draft` | `wp-auto-post-create-draft` | Draft mutation | fixed Post type object's `cap->create_posts` | Implemented and validated — Phase 1.3.1 |
| `wp-auto/page-create-draft` | `wp-auto-page-create-draft` | Draft mutation | fixed Page type object's `cap->create_posts` | Implemented and validated — Phase 1.3.1 |
| `wp-auto/post-update` | `wp-auto-post-update` | Draft mutation | fixed Post type object's `cap->edit_posts`, then `edit_post` for the target | Contract frozen and Phase 1.3.2.0 sealed; not implemented — Phase 1.3.2 |
| `wp-auto/page-update` | `wp-auto-page-update` | Draft mutation | fixed Page type object's `cap->edit_posts`, then `edit_post` for the target | Contract frozen and Phase 1.3.2.0 sealed; not implemented — Phase 1.3.2 |

Publishing, deletion, arbitrary status changes, and generic WordPress mutation are intentionally not part of these abilities. Phase 1.3.2 is the next implementation checkpoint for the two Update abilities; only after implementation and validation may the explicit allowlist expand to twelve.

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
