# ADR-007: Provider-Neutral SEO Abstraction

Status: **Accepted for Phase 1.6.0 contract freeze; implementation deferred**

## Context

WordPress Core has no portable per-object SEO metadata contract. SEO plugins
own storage, permissions, template expansion, and additional fields. WP-Auto
must nevertheless expose a stable direct-MCP surface that can survive provider
changes without becoming an arbitrary post-meta API.

Rank Math is the first planned adapter. Its current plugin can initialize a
compatible MCP Adapter and its native SEO abilities are evolving independently
of WP-Auto. WP-Auto therefore must not delegate its public contract to a
provider Ability, provider REST route, or provider-specific schema.

The implementation review references the [official Rank Math bootstrap](https://github.com/rankmath/seo-by-rank-math/blob/master/rank-math.php)
and the still-open [native write Ability proposal](https://github.com/rankmath/seo-by-rank-math/pull/345).

## Decision

1. Define a provider-neutral `SeoProviderInterface` behind a small registry.
   The interface covers availability, effective read/write authorization,
   bounded state read, and allowlisted patch/write operations only. It is an
   internal PHP seam, not a WordPress or MCP public API.
2. Expose only `wp-auto/seo-get` and `wp-auto/seo-update`, in that order. The
   public shape contains explicit title, description, canonical URL, focus
   keywords, and index/follow directives; it never contains provider names or
   arbitrary metadata.
3. Keep tools stably registered. Provider absence and provider conflict are
   semantic runtime outcomes, not dynamic changes to `tools/list`.
4. Implement the first adapter with WordPress Metadata API calls and a fixed
   provider-key map. Do not invoke provider REST, MCP, Content AI, keyword
   suggestion, analytics, or other external functionality.
5. Use a canonical, bounded SHA-256 `state_token` over object identity,
   normalized public state, protected provider state, adapter version, and
   provider runtime version. The token is an optimistic best-effort check; it
   is not advertised as CAS, ETag, revision, lock, or transaction.
6. Update fields independently under an operation-scoped guard, re-read the
   complete state, verify omitted and protected fields, and fail closed as
   `wp_auto_seo_state_uncertain` whenever final state cannot be proven. Never
   attempt an unverified rollback.
7. Record only bounded private mutation attribution and clean it during
   explicit uninstall. Audit data is not idempotency state and creates no new
   SQL permission.

## Authorization decision

Get requires the provider's effective SEO read capability plus Core
`read_post`, with the existing password-protected-object rule. Update requires
the provider's effective SEO write capability, the actual fixed Post/Page edit
baseline, final `edit_post`, and `draft` status. Provider role restrictions
are enforced in both Ability and service layers.

## Rejected alternatives

- Allowlisting a provider's native MCP Ability: rejected because it exposes a
  third-party contract and can change tool discovery independently of WP-Auto.
- Calling a provider REST endpoint: rejected because it adds a second
  authentication/protocol path and may trigger undocumented side effects.
- Generic `meta` input/output: rejected as an arbitrary metadata and privacy
  boundary violation.
- Updating `post_modified_gmt` to obtain concurrency: rejected because SEO
  metadata writes must not mutate Core content timestamps or claim CAS.
- Waiting for a provider's future write Ability: rejected as a release
  dependency; compatibility is verified against a user-supplied pinned package
  during Phase 1.6.1 instead.

## Consequences

The adapter must translate provider storage and preserve provider fields not
owned by WP-Auto. Existing provider data outside the bounded shape is reported
as unsupported rather than truncated. Provider upgrades can invalidate state
tokens, which is safe and requires a fresh Get. Yoast and AIOSEO adapters can
be added later without changing the two public tool schemas.

## Required follow-up gates

Phase 1.6.1 requires a user-supplied official Rank Math package with verified
hash/version, a fake-provider unit matrix, and real WordPress compatibility
tests. Phase 1.6.2 additionally requires audit/uninstall coverage. Phase 1.6.3
requires WordPress 6.9/7.1, authenticated Streamable HTTP, Plugin Check,
state-integrity, no-outbound-request, and exact-diff security validation.
