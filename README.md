# WePuu Auto Connector

Development repository for the free WordPress.org WePuu Auto Connector.

## Current objective

Phase 1 is Direct WordPress MCP. WePuu Auto Connector should let compatible AI agents connect directly to a WordPress site and invoke explicitly exposed, permission-aware WordPress abilities.

Phase 1.2 is complete: eight read-only site, content, and taxonomy abilities passed the frozen contract and full integration/security validation. The dedicated endpoint is `/wp-json/wp-auto/mcp`, and the exposed MCP tools are `wp-auto-site-health`, `wp-auto-site-info`, `wp-auto-posts-search`, `wp-auto-post-get`, `wp-auto-pages-search`, `wp-auto-page-get`, `wp-auto-categories-list`, and `wp-auto-tags-list`.

Phase 1.3 and Phase 1.4 are complete and formally sealed on `main`. The image-only Media surface includes Search/Get, authenticated Upload, metadata Update, draft Featured Image Assignment, and caller-triggered `wp-auto-media-import-url` with independent SSRF/DNS/redirect/byte/MIME validation. The exact eighteen-tool Direct MCP allowlist, WordPress 6.9/7.1 activation, production packaging, security review, and official Plugin Check 2.1.0 all pass. The approved WordPress.org identity is `WePuu Auto Connector` / `wepuu-auto-connector`; existing `wp-auto` MCP contracts remain unchanged.

Phase 1.5.0 froze the taxonomy mutation contract and safety architecture. Phase 1.5.1 through Phase 1.5.3 implement the built-in `wp-auto-category-create`, `wp-auto-tag-create`, and `wp-auto-taxonomy-assign` tools. Create operations require the fixed taxonomy `manage_terms` capability, Core-owned slug/description fields, persistent site/actor/key idempotency, and private bounded attribution. Assignment replaces one bounded Category or Tag ID set on an authorized draft Post after the taxonomy `assign_terms`, Post edit, and object checks, with an expected-set precondition and private bounded attribution. Categories accept an optional validated parent; Tags remain non-hierarchical. The Direct MCP allowlist is exactly twenty-one tools. Phase 1.5.4 is formally sealed after WordPress 6.9/7.1, Plugin Check 2.1.0 static/runtime, MCP, uninstall, Multisite, concurrency/state-integrity, and security gates passed. Assignment remains best-effort non-CAS; clients must re-read after `wp_auto_taxonomy_state_uncertain`. Phase 1.6 has not started.

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
