# WePuu Auto Connector

Development repository for the free WordPress.org WePuu Auto Connector.

## Current objective

Phase 1 is Direct WordPress MCP. WePuu Auto Connector should let compatible AI agents connect directly to a WordPress site and invoke explicitly exposed, permission-aware WordPress abilities.

Phase 1.2 is complete: eight read-only site, content, and taxonomy abilities passed the frozen contract and full integration/security validation. The dedicated endpoint is `/wp-json/wp-auto/mcp`, and the exposed MCP tools are `wp-auto-site-health`, `wp-auto-site-info`, `wp-auto-posts-search`, `wp-auto-post-get`, `wp-auto-pages-search`, `wp-auto-page-get`, `wp-auto-categories-list`, and `wp-auto-tags-list`.

Phase 1.3 and Phase 1.4 are complete and formally sealed on `main`. The image-only Media surface includes Search/Get, authenticated Upload, metadata Update, draft Featured Image Assignment, and caller-triggered `wp-auto-media-import-url` with independent SSRF/DNS/redirect/byte/MIME validation. The exact eighteen-tool Direct MCP allowlist, WordPress 6.9/7.1 activation, production packaging, security review, and official Plugin Check 2.1.0 all pass. The approved WordPress.org identity is `WePuu Auto Connector` / `wepuu-auto-connector`; existing `wp-auto` MCP contracts remain unchanged.

Phase 1.5.0 froze the taxonomy mutation contract and safety architecture. Phase 1.5.1 through Phase 1.5.3 implement the built-in `wp-auto-category-create`, `wp-auto-tag-create`, and `wp-auto-taxonomy-assign` tools. Create operations require the fixed taxonomy `manage_terms` capability, Core-owned slug/description fields, persistent site/actor/key idempotency, and private bounded attribution. Assignment replaces one bounded Category or Tag ID set on an authorized draft Post after the taxonomy `assign_terms`, Post edit, and object checks, with an expected-set precondition and private bounded attribution. Categories accept an optional validated parent; Tags remain non-hierarchical. The Direct MCP allowlist is exactly twenty-one tools. Phase 1.5.4 is formally sealed after WordPress 6.9/7.1, Plugin Check 2.1.0 static/runtime, MCP, uninstall, Multisite, concurrency/state-integrity, and security gates passed. Assignment remains best-effort non-CAS; clients must re-read after `wp_auto_taxonomy_state_uncertain`.

Phase 1.6.0 through Phase 1.6.3 are formally sealed and landed on `main` at
`c48ba31`. The phase freezes the provider-neutral SEO contract and exposes
exactly twenty-three ordered tools: `wp-auto-seo-get` followed by draft-only
`wp-auto-seo-update`. Get is available for authorized built-in Posts/Pages,
and Update is limited to authorized drafts. The public fields are explicit
title, description, canonical URL, focus keywords, and index/follow directives.
Rank Math is the first independent internal adapter; provider MCP, REST,
Content AI, arbitrary metadata, site-wide settings, and outbound requests are
not part of the contract. WordPress 6.9/7.1 single-site and Multisite,
authenticated MCP, Plugin Check 2.1.0, uninstall, and exact-range security
validation pass; the robots input order is normalized before strict final-state
comparison. Phase 1.7.0 is the completed documentation checkpoint for client
acceptance and release-contract freeze. Phase 1.7.1 now contains development-
only client setup and protocol-probe tooling. MCP Inspector, Codex CLI, and
WorkBuddy canonical 23-tool workflow evidence passes; the Codex Desktop
read-only smoke also passes its local lane, while remote HTTPS validation and
release hardening remain open.
Claude Code is optional when Pro/Max/API authorization is available; the
runtime is unchanged.
See
`docs/PHASE_1_6_SEO_CONTRACTS.md`, `docs/ADR-007-SEO-PROVIDER-ABSTRACTION.md`,
and `docs/PHASE_1_6_3_VALIDATION.md`.

Phase 1.6.0.1 is landed on `main`. The admitted Rank Math
1.0.278 package bundles MCP Adapter 0.5.0, so WePuu must isolate its locked
0.6.1 runtime and prove exact allowlist behavior with Rank Math, Yoast 28.4,
and AIOSEO 5.0.1.1 while retaining the then-current exact 21-tool server. Its full local
matrix and Plugin Check gates pass. Phase 1.6.1 reuses that isolated runtime and
adds only the independent Rank Math 1.0.278 read adapter and SEO Get Ability;
Phase 1.6.2 then adds the draft-only SEO Update, and Phase 1.6.3 completes the
integration/security seal. See
`docs/ADR-008-MCP-ADAPTER-COEXISTENCE.md` and
`docs/PHASE_1_6_0_1_VALIDATION.md`, plus `docs/PHASE_1_6_1_VALIDATION.md`.

Start with:

- `AGENTS.md`
- `docs/ROADMAP.md`
- `docs/PHASE_1_DIRECT_MCP.md`
- `docs/ARCHITECTURE.md`
- `docs/ADR-001-MCP-ADAPTER-DEPENDENCY.md`
- `docs/ADR-002-MUTATION-SAFETY.md`
- `docs/MCP_TOOL_CATALOG.md`
- `docs/PHASE_1_3_MUTATION_CONTRACTS.md`
- `docs/PHASE_1_4_MEDIA_CONTRACTS.md`
- `docs/ADR-005-MEDIA-SAFETY.md`
- `docs/PHASE_1_4_0_VALIDATION.md`
- `docs/PHASE_1_4_1_VALIDATION.md`
- `docs/PHASE_1_4_2_VALIDATION.md`
- `docs/PHASE_1_4_3_VALIDATION.md`
- `docs/PHASE_1_5_TAXONOMY_CONTRACTS.md`
- `docs/ADR-006-TAXONOMY-SAFETY.md`
- `docs/PHASE_1_5_2_VALIDATION.md`
- `docs/PHASE_1_5_3_VALIDATION.md`
- `docs/PHASE_1_6_SEO_CONTRACTS.md`
- `docs/ADR-007-SEO-PROVIDER-ABSTRACTION.md`
- `docs/ADR-008-MCP-ADAPTER-COEXISTENCE.md`
- `docs/PHASE_1_6_0_VALIDATION.md`
- `docs/PHASE_1_6_0_1_VALIDATION.md`
- `docs/PHASE_1_6_1_VALIDATION.md`
- `docs/PHASE_1_6_2_VALIDATION.md`
- `docs/PHASE_1_6_3_VALIDATION.md`
- `docs/PHASE_1_7_CLIENT_ACCEPTANCE.md`
- `docs/PHASE_1_7_CLIENT_SETUP.md`
- `docs/PHASE_1_7_RELEASE_CONTRACT.md`
- `docs/PHASE_1_7_0_VALIDATION.md`
- `docs/PHASE_1_7_1_VALIDATION.md`
- `tools/phase-1-7-client-probe.ps1`
- `docs/WORDPRESS_ORG_COMPLIANCE.md`
- `docs/CODEX_FIRST_PROMPT.md`

## Product boundary

This repository contains the WordPress plugin only.

The free plugin is intended to provide useful direct MCP functionality. Paid WP-Auto features belong to substantive hosted services such as hosted multi-site MCP, Skill execution, automation, scheduling, analytics, research, Site Intelligence, and model orchestration.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- Composer 2.x for development

## MCP architecture

WordPress domain operations are registered through WordPress Abilities API. The official WordPress MCP Adapter is the preferred transport/protocol adapter. See the architecture and ADR documents for the temporary packaging strategy while MCP Adapter remains unavailable as a WordPress.org plugin dependency.

## Local quality checks

```bash
composer install
composer test
composer lint
```

See `docs/PHASE_1_1_VALIDATION.md` and `docs/PHASE_1_2_VALIDATION.md` for authenticated Streamable HTTP validation with a WordPress Application Password and the checkpoint evidence index.
Runtime package versions, licenses, and distribution handling are documented in `docs/DEPENDENCIES.md`.

Production plugin builds must install Composer dependencies without development packages and include the resulting `vendor/` directory:

```bash
composer install --no-dev --optimize-autoloader
```

The distribution build excludes the dependency's standalone `mcp-adapter.php` plugin bootstrap. WP-Auto initializes the Composer library through its isolated loader, so a nested second plugin header is neither needed nor shipped.

Before WordPress.org submission, also run the official Plugin Check tool in a supported WordPress environment and review the final distribution ZIP.

## Codex workflow

Open this directory as the repository root. Codex should read `AGENTS.md` and the relevant docs before each development task. Use `docs/CODEX_TASK_TEMPLATE.md` for narrow tasks.

The first implementation prompt is in `docs/CODEX_FIRST_PROMPT.md`.
