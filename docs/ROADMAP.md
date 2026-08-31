# WP-Auto Connector Iterative Roadmap

## Phase 0 - Repository foundation (complete)

Goal: an installable WordPress.org-oriented plugin skeleton and durable Codex development rules.

Status: complete baseline.

## Phase 1 - Direct WordPress MCP MVP (current)

Goal: a user can install WP-Auto Connector and connect a compatible MCP client directly to the WordPress site to perform safe, permission-aware WordPress operations.

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

Next checkpoint:

- **Phase 1.3.0 - Mutation Contract Freeze:** define and review exact mutation names, schemas, capability checks, idempotency, optimistic concurrency, error semantics, and allowlist changes before implementation begins.

Scope:
- post create draft;
- post update;
- page create draft;
- page update;
- idempotency for create operations;
- optimistic concurrency for updates;
- local activity/audit metadata;
- no publishing.

### Phase 1.4 - Media

Scope:
- media search/get;
- authenticated upload;
- remote URL import with strict SSRF/file validation rules;
- media metadata update;
- featured image assignment.

### Phase 1.5 - Taxonomy

Scope:
- reuse the Phase 1.2 category/tag list contracts;
- category/tag create with capabilities;
- assign terms to supported content.

### Phase 1.6 - SEO abstraction

Scope:
- stable WP-Auto SEO domain contract;
- read/update SEO metadata;
- provider abstraction;
- Rank Math adapter first;
- Yoast and AIOSEO adapters after the contract is stable.

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
- confirm plugin name/slug;
- re-check MCP Adapter WordPress.org availability and packaging strategy;
- run Plugin Check;
- validate readme;
- review all bundled dependencies/licenses/source availability;
- test clean install and minimum versions;
- test activation/deactivation/uninstall;
- complete security review;
- verify external-service disclosures for any shipped cloud features;
- review final distribution ZIP manually.
