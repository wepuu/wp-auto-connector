# ADR-011: Scoped Bearer Authentication

- Status: accepted, implemented, and validated
- Date: 2026-09-28

## Context

Phase 2.0.4 established public JWKS caching, signed revocation delivery and
bounded local deny state, but intentionally did not connect OAuth access tokens
to the MCP request path. The connector already stores an immutable local
`grant_id` mapping containing the current WordPress user, site, OAuth client,
canonical resource and locally approved scopes.

Phase 2.0.5 must add OAuth without replacing Application Passwords, changing the
23-tool catalog, trusting token roles, or placing the WePuu control plane in the
MCP data path.

## Decision

The connector accepts either the existing WordPress authentication path or one
WePuu Bearer access token on the exact `/wp-auto/mcp` REST route. A presented
Bearer credential is authoritative for that request: malformed or invalid
Bearer credentials fail closed and never fall back to a cookie, Basic
credential or Application Password identity.

Bearer validation is local and ordered:

1. accept exactly one bounded compact JWT from the `Authorization` header;
2. require `typ=at+jwt`, `alg=RS256`, a bounded `kid`, and no remote key header;
3. resolve the key through the accepted fresh/safe-stale JWKS cache;
4. verify the signature with `firebase/php-jwt` 7.2.0;
5. require exact issuer, single-string audience, tenant, site, client and grant;
6. require bounded `sub` and `jti`, canonical known scopes and integer times;
7. enforce a five-minute maximum lifetime and 60-second clock tolerance;
8. reject locally denied keys, JTI hashes, grants, sites or subjects;
9. resolve the immutable local grant and current local WordPress user;
10. install that user only for the current REST request.

The access-token scope set must be a subset of local consent. A centralized
Ability registration decorator maps every frozen ability to exactly one scope
and checks the Bearer scope before calling the original WordPress permission
callback. Application Password requests bypass only the OAuth scope ceiling;
they retain their existing transport and Ability checks.

The path-specific RFC 9728 document is published at
`/.well-known/oauth-protected-resource` followed by the canonical MCP resource
path. It contains only the exact resource, issuer, five public scopes and
`bearer_methods_supported=["header"]`.

## Scope mapping

- `mcp:read`: all existing site, content, taxonomy, media and SEO reads;
- `mcp:content.write`: the four existing draft create/update tools;
- `mcp:media.write`: the four existing media mutation tools;
- `mcp:taxonomy.write`: the three existing taxonomy mutation tools;
- `mcp:seo.write`: the existing SEO update tool.

No write scope implies `mcp:read`, and no scope grants a WordPress capability.
Tool discovery continues to expose the exact ordered 23-tool catalog.

## Failure behavior

- missing credentials on an active pairing: `401` Bearer discovery challenge;
- invalid presented Bearer: `401` with `error="invalid_token"`;
- valid Bearer lacking the tool scope: deny before execution. A REST-level
  denial uses `403`, `error="insufficient_scope"` and the required public
  scope; MCP Adapter 0.6.1 represents an ability-level denial as an MCP tool
  error over HTTP 200, so no false HTTP challenge is claimed on that path;
- valid scope but failed WordPress capability/object policy: the existing local
  non-disclosing error, never relabeled as an OAuth scope failure;
- inactive/suspended pairing, unsafe JWKS state or uncertain local state: deny.

## Persistence and migration

No database or local grant migration is introduced. Access tokens and request
identity are never persisted. Existing public connection metadata, local grant,
JWKS cache and revocation state remain authoritative. A public version marker
flushes the new rewrite rule once on activation or an in-place update and is
removed on deactivation/uninstall; it contains no secret.

## Compatibility and rollback

Application Password behavior and all tool schemas remain unchanged. Disabling
or disconnecting the platform makes Bearer unavailable without affecting the
independent direct path. Rolling back the Phase 2.0.5 code does not require a
data migration; accepted Phase 2.0.3/2.0.4 state may remain for a later retry.

## Rejected alternatives

- Per-request introspection was rejected because it inserts the platform into
  the data path and reveals request timing.
- Token roles/capabilities and synthetic WordPress users were rejected because
  the current local user and WordPress remain the authority.
- Falling back after Bearer failure was rejected as credential confusion.
- Hiding tools by scope was rejected because it changes the frozen catalog and
  client behavior.
- Custom JOSE/RSA/base64 primitives were rejected in favor of the reviewed
  dependency and accepted cache.
