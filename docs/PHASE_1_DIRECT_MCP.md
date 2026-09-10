# Phase 1 Specification - Direct WordPress MCP MVP

## User outcome

After installing WePuu Auto Connector, a site administrator can connect a compatible MCP client directly to the WordPress site and perform explicitly permitted operations using normal WordPress authorization.

## Transport target

Preferred remote transport: Streamable HTTP through the official WordPress MCP Adapter.

Phase 1.6.0.1 privately namespaces the locked official Adapter implementation
under ADR-008. This preserves the official protocol behavior while making the
`wp-auto-direct` server independent of incompatible provider-bundled public
Adapter versions and WordPress plugin load order.

Target WP-Auto endpoint:

```text
https://example.com/wp-json/wp-auto/mcp
```

Do not implement a parallel custom MCP protocol stack unless an approved ADR replaces the official adapter decision.

## Authentication baseline

Phase 1 baseline for remote direct connections: WordPress Application Password over HTTPS.

The authenticated WordPress user is not automatically trusted for every WP-Auto tool. Every ability also enforces its own WordPress capability.

## Phase 1.1 proof tool

Canonical ability:

```text
wp-auto/site-health
```

Purpose: prove ability registration, MCP exposure, transport authentication, permission evaluation, execution, and structured result handling.

Initial result should contain only non-secret operational fields needed to verify the connector, for example:
- WordPress version;
- PHP version;
- WePuu Auto Connector version;
- Abilities API availability;
- MCP Adapter availability/version;
- REST API availability;
- HTTPS state.

Do not return admin email, usernames, filesystem paths, secrets, database details, or plugin inventory in Phase 1.1.

Required WordPress capability: `read`.

## Planned Phase 1 tool catalog

See `docs/MCP_TOOL_CATALOG.md`.

## Mutation safety

Before Phase 1.3 is considered complete:
- create operations must be idempotent;
- updates must use optimistic concurrency/version checking;
- draft creation is separate from publish;
- published content updates require explicit later policy decisions;
- every mutation is attributable to the authenticated WordPress user.

## Media safety

Remote URL media import is not a generic URL fetcher. It must include SSRF controls, redirect re-validation, private/reserved IP blocking, file-size/type limits, WordPress media validation, and timeouts.

Phase 1.4.0 freezes the detailed image-only contracts in
`PHASE_1_4_MEDIA_CONTRACTS.md` and the independent SSRF, ingestion,
authorization, idempotency, concurrency, cleanup, audit, and disclosure
decisions in `ADR-005-MEDIA-SAFETY.md`. Phase 1.4.1 implements image Search/Get;
Phase 1.4.2 implements bounded authenticated Base64 Upload without any outbound
request. Phase 1.4.3 adds only an authorized, concurrency-checked update of
image title, alt text, caption, and description. Remote import remains deferred
to its later checkpoint.

## Taxonomy mutation safety

Phase 1.5.0 freezes the built-in Category Create, built-in Tag Create, and
draft-Post taxonomy Assignment contracts in
`PHASE_1_5_TAXONOMY_CONTRACTS.md`, with the capability, idempotency,
concurrency, invariant, audit, and uninstall decisions in
`ADR-006-TAXONOMY-SAFETY.md`. Phase 1.5.1 through 1.5.3 subsequently register
only the three frozen abilities, and Phase 1.5.4 seals the authenticated
twenty-one-tool runtime and its WordPress 6.9/7.1, Plugin Check, uninstall,
and security gates.

Later checkpoints may append only the three frozen tools. Create uses the
fixed taxonomy object's actual management capability and persistent atomic
ownership. Assignment accepts IDs only, composes taxonomy and Post/object
permissions, and exactly replaces one non-empty Category/Tag set on one
authorized built-in Post draft using a required expected-set precondition. It
is explicitly destructive and best-effort rather than atomic CAS.

## SEO abstraction boundary

Phase 1.6.0 froze the provider-neutral SEO contract in
`PHASE_1_6_SEO_CONTRACTS.md` and the provider-safety decision in
`ADR-007-SEO-PROVIDER-ABSTRACTION.md`. It added no Ability and left the
runtime at exactly twenty-one tools. Phase 1.6.1 appends `wp-auto/seo-get`, so
the current candidate exposes exactly twenty-two tools; `wp-auto/seo-update`
remains a later checkpoint. Get reads only
explicit title, description, canonical URL, focus keywords, and index/follow
directives on authorized built-in Posts/Pages; Update is restricted to
authorized drafts and uses a best-effort state token. Rank Math is the first
internal adapter, while provider names, arbitrary meta, provider REST/MCP
calls, site-wide settings, and outbound requests remain outside the boundary.

## Phase 1 final acceptance scenario

From a compatible AI client:

1. Connect to the site's WP-Auto MCP endpoint.
2. Inspect site information and search existing content.
3. Create a new post as `draft` only.
4. Update the draft content.
5. Ensure/create relevant category/tag terms and assign them.
6. Upload/import an allowed image and set it as featured image.
7. Read/update supported SEO metadata.
8. Return the post ID and WordPress edit URL.
9. Confirm the post remains a draft.

The scenario must succeed without exposing publish, permanent delete, arbitrary code execution, plugin/theme management, or user-management capabilities.
