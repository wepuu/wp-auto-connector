# Phase 2.0.5 Bearer Integration Contract

Status: accepted and closed; automated, live HTTPS/KMS, direct client, privacy,
package and cleanup gates passed on 2026-09-29.

## Public behavior

The dedicated server remains `wp-auto-direct` at `/wp-json/wp-auto/mcp` with
the exact ordered tools and schemas in `MCP_TOOL_CATALOG.md`. OAuth adds a
second authentication method; it does not add an MCP server, tool, resource,
prompt or data-plane service.

The connector reads Bearer credentials only from `Authorization`. It never
reads access tokens from query parameters, cookies or MCP JSON. A Bearer token
is limited to 8192 header bytes and exactly three base64url JWT segments.

## Request identity

After signature and claim validation, the connector resolves `grant_id` against
the current active connection. The token `client_id` and each token scope must
match that immutable local record. The mapped WordPress user must still exist.
The user is installed only for the exact REST dispatch and the previous user is
restored after the response.

Token `sub` is validated but not persisted: Phase 2.0.3 deliberately stores no
platform subject locally. Subject-wide signed revocation remains a conservative
local deny-all event under ADR-010.

## Scope table

| Scope | Existing abilities |
|---|---|
| `mcp:read` | `site-health`, `site-info`, `posts-search`, `post-get`, `pages-search`, `page-get`, `categories-list`, `tags-list`, `media-search`, `media-get`, `seo-get` |
| `mcp:content.write` | `post-create-draft`, `page-create-draft`, `post-update`, `page-update` |
| `mcp:media.write` | `media-upload`, `media-update`, `media-set-featured`, `media-import-url` |
| `mcp:taxonomy.write` | `category-create`, `tag-create`, `taxonomy-assign` |
| `mcp:seo.write` | `seo-update` |

The scope decorator runs only when a verified Bearer context is installed. It
returns before the original callback when scope is absent; otherwise the exact
original callback and service checks execute unchanged.

## Data boundary

Token validation may fetch only the pinned issuer's public `/jwks`, subject to
the Phase 2.0.4 cache. MCP method names, parameters, bodies, results and
WordPress content are not sent to the platform. The null MCP observability
handler remains selected.

## Security invariants

- `aud` arrays, origins, aliases and trailing-slash variants fail;
- unknown, removed or locally revoked keys fail;
- unknown, duplicated, unordered or locally unapproved scopes fail;
- Bearer failure cannot use an ambient WordPress identity;
- a local user deletion invalidates grant resolution immediately;
- user capability changes apply on the next existing permission check;
- resource drift suspends the connection before token acceptance;
- safe cached JWKS may preserve an unexpired token during platform outage;
- no access token, WordPress credential or content is stored or logged.
