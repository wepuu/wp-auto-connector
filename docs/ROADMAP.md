# WePuu Auto Connector Iterative Roadmap

## Phase 0 - Repository foundation (complete)

Goal: an installable WordPress.org-oriented plugin skeleton and durable Codex development rules.

Status: complete baseline.

## Phase 1 - Direct WordPress MCP MVP (current)

Goal: a user can install WePuu Auto Connector and connect a compatible MCP client directly to the WordPress site to perform safe, permission-aware WordPress operations.

### Phase 1.1 - MCP server foundation (complete)

Goal: prove the direct MCP transport and WordPress Abilities path end to end.

Scope:
- integrate WordPress 6.9+ Abilities API;
- integrate official WordPress MCP Adapter using the approved project dependency strategy;
- create the WP-Auto direct MCP server;
- expose one read-only `wp-auto/site-health` ability;
- require authenticated transport for private site data;
- add diagnostics for MCP/Abilities availability;
- add automated tests for registration and permissions;
- document local/manual MCP Inspector or curl validation.

Acceptance target:
- MCP `initialize` succeeds;
- `tools/list` returns the WP-Auto site-health capability through the selected adapter model;
- invoking it as an authorized WordPress identity succeeds;
- unauthenticated/unauthorized invocation is rejected;
- no content mutation exists yet.

### Phase 1.2 - Read-only site/content tools (complete)

Scope:
- site info/health;
- posts search/get;
- pages search/get;
- categories/tags list;
- explicit schemas and capability checks;
- pagination/limits for list operations.

Delivery checkpoints:

1. **Phase 1.2.0 - Contract freeze (complete):** approved the exact ability/tool names, schemas, permission boundaries, privacy behavior, errors, and final allowlist.
2. **Phase 1.2.1 - Site info (complete):** implemented and validated `wp-auto/site-info`.
3. **Phase 1.2.2 - Posts search/get (complete):** implemented and validated bounded post discovery and object-authorized retrieval.
4. **Phase 1.2.3 - Pages search/get (complete):** implemented and validated bounded page discovery and object-authorized retrieval.
5. **Phase 1.2.4 - Categories/tags list (complete):** implemented and validated bounded taxonomy term lists and extended the dedicated server to all eight approved tools.
6. **Phase 1.2.5 - Final MCP allowlist audit (complete):** audited and froze the exact eight-tool allowlist, public schemas, annotations, errors, and security/resource boundaries without adding tools.
7. **Phase 1.2.6 - Integration/security validation (complete):** completed automated and live MCP permission, privacy, schema, bounded-query, state-integrity, and compatibility checks.

Phase 1.2 passed its Definition of Done: `tools/list` returned exactly eight approved tools and all seven new abilities passed live MCP invocation plus the documented permission and security scenarios. The authoritative contract is `docs/PHASE_1_2_READ_TOOLS.md`; the completion seal is `docs/PHASE_1_2_VALIDATION.md`.

### Phase 1.3 - Safe draft/content mutation

Scope:
- post create draft;
- post update;
- page create draft;
- page update;
- idempotency for create operations;
- optimistic concurrency for updates;
- local activity/audit metadata;
- no publishing.

Delivery checkpoints:

1. **Phase 1.3.0 - Mutation Contract Freeze (complete):** froze the four future Ability/tool names, strict schemas, capability paths, concurrent-safe persistent Create idempotency, best-effort non-atomic Update concurrency limits, invariant guard, bounded local audit, error semantics, Core side effects, and allowlist progression. No runtime tool was added.
2. **Phase 1.3.1 - Post/Page Create Draft (complete):** implemented and validated only `wp-auto/post-create-draft` and `wp-auto/page-create-draft`, including the persistent atomic claim, post-write invariant checks, and a ten-tool explicit allowlist.
3. **Phase 1.3.2.0 - modified_gmt Sentinel Compatibility Amendment (complete; formally sealed):** the exact Core sentinel compatibility contract is frozen on `main`. This documentation-only checkpoint added no Update runtime implementation or tool exposure.
4. **Phase 1.3.2 - Draft Update + Best-effort Optimistic Concurrency (complete; formally sealed):** implemented and validated only `wp-auto/post-update` and `wp-auto/page-update`, including object authorization, protected invariants, local audit, and the documented best-effort final timestamp check. The explicit Direct MCP allowlist is exactly twelve.
5. **Phase 1.3.3 - Mutation Security / Audit Freeze (complete; formally sealed):** resolved SEC-1, SEC-2, and SEC-3; landed ADR-003 atomic ownership and ADR-004 uninstall cleanup; enforced secure remote MCP transport; retained the exact twelve-tool surface; and completed exact-main automated, CI, live WordPress, and zero-finding security gates.
6. **Phase 1.3.4 - Full Mutation Integration Validation (complete; formally sealed):** proved through a disposable WordPress 6.9 / PHP 8.1 wp-env stack and real Streamable HTTP MCP sessions that each request changes only its intended target plus documented Core/plugin lifecycle effects. The matrix rejected publish/status promotion and preserved unrelated content, taxonomy, media, featured images, SEO/arbitrary metadata, users, settings, plugins, themes, Cloud, telemetry, and outbound HTTP state. Phase 1.3 is formally sealed with the exact twelve-tool runtime.

The authoritative contract is `docs/PHASE_1_3_MUTATION_CONTRACTS.md`; the accepted mutation safety decision is `docs/ADR-002-MUTATION-SAFETY.md`, with the landed internal ownership amendment in `docs/ADR-003-ATOMIC-OWNERSHIP.md` and uninstall decision in `docs/ADR-004-UNINSTALL-PRIVATE-STATE-CLEANUP.md`. Phase 1.3.0 through Phase 1.3.4 are complete and formally sealed on `main`. The completion evidence is `docs/PHASE_1_3_4_VALIDATION.md`.

### Phase 1.4 - Media (complete; formally sealed)

Scope:
- media search/get;
- authenticated upload;
- remote URL import with strict SSRF/file validation rules;
- media metadata update;
- featured image assignment.

Delivery checkpoints:

1. **Phase 1.4.0 - Media Contract and Security Architecture Freeze (complete; formally sealed):** froze the six future Ability/tool names, exact schemas, image-only MIME/size policy, capability and privacy rules, persistent ingestion idempotency, update/featured preconditions, bounded local audit, independent SSRF policy, error semantics, side effects, and exact allowlist progression. It added no runtime tool or external request.
2. **Phase 1.4.1 - Media Search/Get (complete; formally sealed):** implements bounded, permission-aware supported-image discovery/retrieval, validates the real Streamable HTTP surface, and expands the explicit allowlist to exactly fourteen tools.
3. **Phase 1.4.2 - Authenticated Image Upload (complete; formally sealed):** implements one bounded Base64 image upload, optional authorized draft parent association, persistent idempotency, Core validation, private bounded audit, uninstall cleanup, and the fifteen-tool allowlist.
4. **Phase 1.4.3 - Media Metadata Update (complete; formally sealed):** implements and validates allowlisted image title/alt/caption/description updates with object authorization, best-effort concurrency, protected attachment invariants, private bounded audit, and the sixteen-tool allowlist.
5. **Phase 1.4.4 - Featured Image Assignment (complete; formally sealed):** implemented and validated idempotent assignment for authorized Post/Page drafts and the seventeen-tool allowlist.
6. **Phase 1.4.5 - Remote URL Import (complete; formally sealed):** implemented the open-world downloader last, with the frozen SSRF/DNS/redirect/time/byte/MIME policy and the exact eighteen-tool allowlist. The release-like package includes the Composer manifests required for review and excludes the dependency's standalone plugin entrypoint.
7. **Phase 1.4.6 - Integration/Security Validation (complete; formally sealed):** automated and live wp-env-equivalent/Streamable HTTP behavior, exact schemas/permissions/privacy/allowlist, ingestion concurrency, filesystem cleanup, SSRF matrix, state integrity, WordPress 6.9/7.1 compatibility, and zero-finding security reviews all pass. The approved `WePuu Auto Connector` / `wepuu-auto-connector` identity resolves the former trademark warnings; official Plugin Check 2.1.0 reports no errors or warnings against the release-like package.

The authoritative contract is `docs/PHASE_1_4_MEDIA_CONTRACTS.md`; the accepted safety decision is `docs/ADR-005-MEDIA-SAFETY.md`; checkpoint evidence is recorded in `docs/PHASE_1_4_0_VALIDATION.md` through `docs/PHASE_1_4_6_VALIDATION.md`. Phase 1.4 is formally sealed with the exact eighteen-tool runtime. It remains image-only and does not authorize publishing, deletion, generic file/network access, taxonomy mutation, SEO, Cloud, telemetry, or later roadmap work.

### Phase 1.5 - Taxonomy

Scope:
- reuse the Phase 1.2 category/tag list contracts;
- category/tag create with capabilities;
- assign terms to supported content.

Delivery checkpoints:

1. **Phase 1.5.0 - Taxonomy Contract and Security Architecture Freeze (complete):** froze the three future Ability/tool names, strict schemas, fixed built-in taxonomy scope, actual taxonomy/object capability composition, persistent Create idempotency, exact-replacement concurrency semantics, private bounded audit, uninstall requirements, errors, side effects, and allowlist progression. It added no runtime tool or persistent state; the Direct MCP runtime remained exactly eighteen tools.
2. **Phase 1.5.1 - Category Create (complete; formally sealed):** implements built-in Category creation, optional validated parent, taxonomy idempotency/audit/uninstall foundations, and the exact nineteen-tool allowlist. The authenticated Streamable HTTP, concurrency, state-integrity, WordPress 6.9/7.1, Plugin Check 2.1.0, and security evidence is recorded in `docs/PHASE_1_5_4_VALIDATION.md`.
3. **Phase 1.5.2 - Tag Create (complete; formally sealed):** implements built-in Tag creation on the frozen shared safety foundation and the exact twenty-tool allowlist. The same release-gate matrix passed without changing public contracts.
4. **Phase 1.5.3 - Draft Post Taxonomy Assignment (complete; formally sealed):** implements bounded ID-only exact Category/Tag replacement on authorized built-in Post drafts with expected-set concurrency, invariant verification, private bounded assignment attribution, and the exact twenty-one-tool allowlist. The best-effort non-CAS limitation is retained and `wp_auto_taxonomy_state_uncertain` requires a fresh client read.
5. **Phase 1.5.4 - Integration and Security Seal (complete; formally sealed):** WordPress 6.9 and 7.1 activation, authenticated Streamable HTTP, low-privilege rejection, idempotency, assignment race/state integrity, uninstall, Multisite cleanup, Plugin Check 2.1.0 static/runtime checks, Composer gates, and exact-diff security review all pass without adding tools.

The authoritative contract is `docs/PHASE_1_5_TAXONOMY_CONTRACTS.md`; the accepted safety decision is `docs/ADR-006-TAXONOMY-SAFETY.md`. Phase 1.5.0 through Phase 1.5.4 are complete and formally sealed with exactly twenty-one tools. Category/Tag updates or deletion, custom taxonomies/post types, Pages, empty-set clearing, publishing, content/media/SEO mutation, Cloud, telemetry, and external requests remain outside this phase. Phase 1.6.0 is formally frozen and Phase 1.6.1 now supplies the first read-only SEO runtime checkpoint.

### Phase 1.6 - SEO abstraction

Scope:
- stable WP-Auto SEO domain contract;
- read/update SEO metadata;
- provider abstraction;
- Rank Math adapter first;
- Yoast and AIOSEO adapters after the contract is stable.

Delivery checkpoints:

1. **Phase 1.6.0 - SEO Contract and Provider-Safety Freeze (complete; formally frozen):** freezes the provider-neutral five-field object contract, stable `seo-get`/`seo-update` order, draft-only mutation boundary, provider registry, state-token concurrency model, bounded private audit, explicit uninstall boundary, and failure-closed semantics. No runtime tool is added; the sealed runtime remains exactly twenty-one tools.
2. **Phase 1.6.0.1 - SEO Provider Artifact Admission and MCP Adapter Coexistence (complete; landed on main):** admits the pinned Rank Math 1.0.278, Yoast 28.4, and AIOSEO 5.0.1.1 packages; isolates WePuu's locked MCP Adapter 0.6.1 runtime from provider load order; and validates that the dedicated server still exposes exactly twenty-one ordered tools.
3. **Phase 1.6.1 - Rank Math Adapter and SEO Get (complete; landed on main):** implements the independent internal Rank Math 1.0.278 read adapter and exposes only `wp-auto/seo-get`, producing the exact twenty-two-tool runtime without provider REST/MCP or outbound requests.
4. **Phase 1.6.2 - Draft SEO Update (implementation checkpoint complete; seal pending):** exposes only `wp-auto/seo-update` for authorized Post/Page drafts, including state-token checks, invariant verification, bounded attribution, and uninstall cleanup; the candidate runtime is exactly twenty-three tools.
5. **Phase 1.6.3 - Integration and Security Seal (not started):** validate WordPress 6.9/7.1, authenticated Streamable HTTP, provider absence/conflict, state integrity, no outbound requests, Plugin Check, uninstall, and exact-diff security without adding tools.

The authoritative contract is `docs/PHASE_1_6_SEO_CONTRACTS.md`; the accepted
provider and safety decision is `docs/ADR-007-SEO-PROVIDER-ABSTRACTION.md`;
the coexistence decision is `docs/ADR-008-MCP-ADAPTER-COEXISTENCE.md`; the
documentation freeze evidence is `docs/PHASE_1_6_0_VALIDATION.md`; the SEO Get
candidate evidence is `docs/PHASE_1_6_1_VALIDATION.md`.

### Phase 1.7 - Client compatibility and release hardening

Target clients:
- Claude Code;
- WorkBuddy/CodeBuddy-compatible MCP client;
- MCP Inspector/custom standards-compliant client;
- ChatGPT direct compatibility where the current client capability permits it.

Scope:
- copy-ready client setup instructions;
- connection diagnostics;
- permission configuration;
- transport/authentication compatibility tests;
- build dependency review;
- WordPress.org Plugin Check iteration.

Phase 1 final demo:
A compatible AI agent inspects the site, creates a WordPress draft, assigns taxonomy, uploads/sets a featured image, and writes supported SEO metadata without publishing the article.

## Phase 2 - WP-Auto Cloud MCP

Goal: optional cloud connection without weakening the useful free direct-MCP plugin.

Connector scope:
- explicit administrator-initiated site pairing;
- connection status/revoke/disconnect;
- locally enforced allowed abilities;
- external-service disclosure before release.

SaaS repository scope:
- hosted MCP gateway;
- OAuth;
- multi-site routing;
- cloud audit/governance.

Do not implement the SaaS runtime in this repository.

## Phase 3 - Site Intelligence

Primarily WP-Auto Cloud work:
- content inventory;
- internal/external link graph;
- keyword/page mapping;
- content embeddings;
- site sync/indexing.

Connector work should expose only the safe primitives required by the cloud service after explicit connection.

## Phase 4 - Skills

WP-Auto Cloud skills:
- Keyword Strategy;
- SEO/GEO Content Writer;
- Reference to Original;
- Internal Link Optimizer.

Skills call the same connector abilities used by external MCP clients.

## Phase 5 - Automation

WP-Auto Cloud:
- scheduler;
- RSS/source triggers;
- workflow runtime;
- approval queue;
- controlled publishing.

## Phase 6 - Analytics and closed-loop optimization

- Google Search Console integration;
- performance feedback;
- content refresh recommendations;
- automated optimization workflows under explicit policies.

## WordPress.org release gate

Before first public directory release:
- use the confirmed `WePuu Auto Connector` name and `wepuu-auto-connector` slug;
- re-check MCP Adapter WordPress.org availability and packaging strategy;
- run Plugin Check;
- validate readme;
- review all bundled dependencies/licenses/source availability;
- test clean install and minimum versions;
- test activation/deactivation/uninstall;
- complete security review;
- verify external-service disclosures for any shipped cloud features;
- review final distribution ZIP manually.
