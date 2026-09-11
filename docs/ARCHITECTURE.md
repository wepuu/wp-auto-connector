# WePuu Auto Connector Architecture

## Current target: Direct MCP first

```text
Claude Code / WorkBuddy / standards-compliant MCP client
                         |
                         | Streamable HTTP / MCP
                         v
                WP-Auto MCP Server
                         |
                         v
              Official MCP Adapter
                         |
                         v
            WordPress Abilities API
                         |
                         v
             WP-Auto Ability Layer
                         |
       +-----------------+------------------+
       |                 |                  |
     Site              Content            Media ...
                         |
                         v
                 WordPress Core APIs
```

The WordPress ability layer is the single source of domain operations. MCP is an adapter over abilities, not a second implementation of WordPress CRUD.

Mutation content writes remain WordPress Core API driven. The only approved
database exception is a future, private ownership service for internal
serialization:

```text
ContentMutationService
      |
      +-> WordPress Core content APIs
      |
      +-> AtomicOwnershipStore
             |
             +-> narrowly scoped $wpdb->options
               acquire/release only
```

`AtomicOwnershipStore` is internal infrastructure, not an Ability, MCP tool,
public API, or generic database layer. Its `$wpdb->options` SQL exception is
limited to atomic acquisition and conditional release of private,
non-autoloaded WP-Auto ownership rows. Posts, postmeta audit history,
taxonomies, users, media, SEO, settings, and all client-selected or arbitrary
SQL remain prohibited. See `docs/ADR-003-ATOMIC-OWNERSHIP.md`.

## Direct MCP endpoint target

The WP-Auto custom server should target a stable endpoint in the form:

```text
/wp-json/wp-auto/mcp
```

The exact route is implemented through the official MCP Adapter custom-server API and must be covered by integration tests. Do not create an unrelated hand-written JSON-RPC REST server if the official adapter can provide the required behavior.

## Ability naming

Canonical ability names use a stable WP-Auto namespace:

```text
wp-auto/site-health
wp-auto/site-info
wp-auto/posts-search
wp-auto/post-get
wp-auto/post-create-draft
wp-auto/post-update
```

Do not encode third-party SEO plugin names into public tool contracts.

## Ability requirements

Each ability must define:
- label/description;
- input schema;
- output schema where supported/appropriate;
- execute callback;
- narrow permission callback;
- public/MCP exposure metadata required by the selected adapter version;
- annotations describing read-only/destructive/idempotent behavior when supported.

## Authentication and authorization

Direct HTTP MCP has two layers:

1. Transport/client authentication establishes a WordPress identity.
2. Each ability checks the WordPress capability required for the operation.

Application Passwords are the Phase 1 remote-auth baseline. HTTPS is required outside local development.

A successful MCP authentication must never grant more WordPress authority than the authenticated WordPress account already has.

## Planned plugin modules

```text
src/
  Abilities/
    Site/
    Content/
    Media/
    Taxonomy/
    Seo/
  Auth/
  Mcp/
  Admin/
  Diagnostics/
  Infrastructure/
  Integrations/
```

Create directories only when real implementation requires them.

## Official MCP Adapter dependency

As of 2026-08-29:
- WordPress 6.9+ contains Abilities API in core;
- WordPress MCP Adapter latest stable release observed during project initialization is v0.6.1;
- official MCP Adapter documentation states that WordPress.org `Requires Plugins` dependency is not yet supported because MCP Adapter is not yet listed in the directory;
- official documentation allows bundling it as a Composer dependency and recommends a collision-safe autoloading strategy such as Jetpack Autoloader or dependency prefixing.

WP-Auto therefore treats MCP Adapter as a replaceable integration dependency.
From Phase 1.6.0.1, the locked official 0.6.1 sources and PHP MCP Schema 0.1.3
are deterministically built into `WPAuto\\Connector\\PrivateMcp`. Private
hooks, filters, CLI command, and per-site session keys prevent a provider's
public Adapter from selecting WePuu's protocol runtime. The dedicated endpoint
continues to expose only WePuu's exact ordered allowlist. See
`docs/ADR-001-MCP-ADAPTER-DEPENDENCY.md` and
`docs/ADR-008-MCP-ADAPTER-COEXISTENCE.md`.

## Future cloud architecture

```text
AI clients
    | direct MCP                       | cloud MCP
    v                                  v
WePuu Auto Connector <----------- WP-Auto Cloud Gateway
       |
       v
WP-Auto Ability Layer
```

Direct MCP and cloud MCP must invoke the same abilities and permission checks.

## Phase 1.4 media boundary

Phase 1.4 media operations stay behind WordPress Abilities and small domain
services. Upload and sideload use WordPress temporary-file/media APIs; clients
never receive or provide server paths. The existing `AtomicOwnershipStore` may
arbitrate only the fixed private media-ingestion idempotency option family and
does not authorize direct SQL against attachments, postmeta, or files.

Phase 1.4.2 Upload accepts one strict canonical Base64 image bounded by the
smaller of 10 MiB and the Core upload limit. It acquires a site/actor/Ability/key
claim before writing a WordPress-owned temporary file, rechecks permission and
optional draft-parent authorization immediately before Core sideload, and
verifies the final attachment, author, parent, MIME, file hash, and canonical
Media Get output. Deterministic pre-Core failures clean the owned temporary file
and may release the exact initial claim; any possible Core/file/audit ambiguity
retains ownership and fails closed. Media attribution is separate, fixed-schema,
and bounded to the newest 20 events per attachment.

Phase 1.4.3 Metadata Update repeats `upload_files` and attachment `edit_post`,
then re-fetches the supported image immediately before comparing the raw Core
GMT concurrency token. An operation-scoped Core guard preserves attachment
identity and forbidden row fields while only title, caption, and description
enter `wp_update_post()`; alt text maps only to Core's fixed
`_wp_attachment_image_alt` key. The service re-reads the image, verifies file,
MIME, parent, author, status, GUID, and omitted fields, and returns the shared
full Media record. Because post and alt-meta writes are not transactional, any
possible partial application fails closed as `wp_auto_media_state_uncertain`
without a blind rollback.

Remote image import adds a dedicated policy layer before the WordPress HTTP
API. It validates public DNS destinations and every redirect, fixes ports and
resource limits, prevents filters from relaxing the WP-Auto boundary, and then
passes the completed temporary file through the same Core media validation as
local upload. It is not a generic HTTP client or proxy. See
`docs/PHASE_1_4_MEDIA_CONTRACTS.md` and `docs/ADR-005-MEDIA-SAFETY.md`.

## Phase 1.5 taxonomy boundary

Phase 1.5 taxonomy writes remain fixed WordPress Abilities backed by a small
taxonomy mutation service and Core term APIs. Only built-in `category` and
`post_tag` are accepted. Create resolves the fixed taxonomy's actual
`manage_terms` capability; Assignment composes its actual `assign_terms`
capability with the built-in Post edit baseline and final `edit_post` check.

Category Create and Tag Create now reuse ADR-003 ownership through a closed
`AtomicOwnershipStore` allowlist and explicit-uninstall cleanup for one exact
private taxonomy-idempotency family. Create audit is stored under one exact
private termmeta key; Assignment audit uses the same key in postmeta, both
verified during explicit uninstall. Assignment is ID-only exact replacement on
a draft Post and uses a required expected current term set as a best-effort,
explicitly non-CAS concurrency precondition. Direct runtime SQL
against Core taxonomy tables remains prohibited. See
`docs/PHASE_1_5_TAXONOMY_CONTRACTS.md` and
`docs/ADR-006-TAXONOMY-SAFETY.md`.

## Phase 1.6 SEO boundary

Phase 1.6.0 froze the provider-neutral SEO contract in
`docs/PHASE_1_6_SEO_CONTRACTS.md` and the provider decision in
`docs/ADR-007-SEO-PROVIDER-ABSTRACTION.md`; it added no runtime code or tool.
Phase 1.6.1 implements the first named checkpoint, appending `wp-auto/seo-get`
after the sealed twenty-one-tool baseline. Phase 1.6.2 appends the draft-only
`wp-auto/seo-update`, so the Phase 1.6.3-sealed runtime is exactly twenty-three
tools.

SEO Get is exposed through a small internal `SeoProviderInterface` and registry,
never through a generic metadata API. The public shape contains only explicit
per-object title, description, canonical URL, focus keywords, and index/follow
directives. The first provider adapter is an independent Rank Math integration
using fixed WordPress Metadata API keys; provider REST/MCP/Content AI calls,
arbitrary metadata, and outbound requests are prohibited.

Get supports authorized built-in Posts/Pages and Rank Math 1.0.278 only. Update is draft-only and repeats
the provider capability, actual Post/Page edit baseline, and final `edit_post`
checks at both Ability and service layers. A bounded SHA-256 state token gives
best-effort optimistic concurrency; it is not a CAS token. Multi-key writes
must re-read and verify omitted/protected fields and fail closed as
`wp_auto_seo_state_uncertain` when final state is ambiguous. SEO audit state is
private, bounded to 20 events per object, contains no SEO values or credentials,
and is removed by explicit uninstall without adding runtime SQL authority.
