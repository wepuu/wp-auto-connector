# WP-Auto Connector

Development repository for the free WordPress.org WP-Auto Connector.

## Current objective

Phase 1 is Direct WordPress MCP. The plugin should let compatible AI agents connect directly to a WordPress site and invoke explicitly exposed, permission-aware WordPress abilities.

Phase 1.2 is complete: eight read-only site, content, and taxonomy abilities passed the frozen contract and full integration/security validation. The dedicated endpoint is `/wp-json/wp-auto/mcp`, and the exposed MCP tools are `wp-auto-site-health`, `wp-auto-site-info`, `wp-auto-posts-search`, `wp-auto-post-get`, `wp-auto-pages-search`, `wp-auto-page-get`, `wp-auto-categories-list`, and `wp-auto-tags-list`.

Phase 1.3.0 Mutation Contract Freeze and Phase 1.3.1 Post/Page Create Draft are complete. Create operations are authenticated, capability-checked, draft-only, idempotent, and bounded by the frozen mutation contract. Phase 1.3.2.0 `modified_gmt` sentinel compatibility is sealed on `main`. The Phase 1.3.2 feature branch adds locally validated, draft-only `wp-auto-post-update` and `wp-auto-page-update` tools for an exact twelve-tool surface; the implementation has passed review and remains pending PR, CI, merge, and formal sealing.

Start with:

- `AGENTS.md`
- `docs/ROADMAP.md`
- `docs/PHASE_1_DIRECT_MCP.md`
- `docs/ARCHITECTURE.md`
- `docs/ADR-001-MCP-ADAPTER-DEPENDENCY.md`
- `docs/ADR-002-MUTATION-SAFETY.md`
- `docs/MCP_TOOL_CATALOG.md`
- `docs/PHASE_1_3_MUTATION_CONTRACTS.md`
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
