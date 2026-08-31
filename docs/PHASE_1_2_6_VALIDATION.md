# Phase 1.2.6 Full Read-Only Integration and Security Validation

## Baseline and verdict

- Starting baseline: clean, synchronized `main@7c0da50`
- Validation branch: `chore/phase-1-2-6-read-validation`
- Validation date: 2026-08-31
- Canonical endpoint: `/wp-json/wp-auto/mcp`
- Verdict: **PASS - ready to be sealed**

Phase 1.2.6 changed no production runtime code. It consolidated the automated evidence and exercised the frozen eight-tool contract against a disposable, real WordPress stack. The live run found no schema drift, authorization leak, ninth tool, mutation surface, unbounded query, or content-state change.

## Automated evidence

The existing focused suites already cover the required internal resource and privacy boundaries, so this checkpoint did not add duplicate integration tests:

- MCP registrar: exact ordered eight-Ability allowlist, `count === 8`, and empty resources/prompts.
- Content service: 100-candidate chunks, 1,000-candidate/ten-query ceiling, authorization-after-query logical pagination, stable ID tie-breaking, Search-to-Get eligibility, existence hiding, and stable window errors.
- Taxonomy service: fixed taxonomy, `per_page + 1`, `hierarchical=false`, 1,000-position ceiling, stable `term_id` tie-breaking, and scoped filter cleanup on success and failure.
- Ability suites: strict schemas, boundary validation, exact output shapes, `read` permissions, and read-only/destructive/idempotent annotations for all eight tools.

Focused results:

- MCP registrar: 3 tests, 18 assertions passed.
- Content read service: 67 tests, 341 assertions passed.
- Taxonomy read service: 31 tests, 149 assertions passed.

Final repository gates:

- Full PHPUnit: 132 tests, 769 assertions passed.
- `composer validate --strict`: passed.
- WordPress Coding Standards through `composer lint`: 34 files passed.
- `composer audit --locked`: passed; no security vulnerability advisories found.
- `git diff --check`: passed.

## Live WordPress evidence

The disposable environment used WordPress 6.9, PHP 8.1.34, MariaDB 11.8.9, and bundled official MCP Adapter 0.6.1. The plugin was activated, deactivated, and reactivated before validation. `WP_ENVIRONMENT_TYPE=local` permitted local HTTP only, and WP-Cron was disabled while future-content fixtures existed.

Temporary identities represented administrator-like, editor-like, two content owners, subscriber-like, and authenticated-without-`read` capability sets. The run recorded actual primitive capabilities rather than inferring access from role labels. It created published, draft, pending, private, future, password-protected, wrong-type, and parent/child fixtures for Posts and Pages, plus parent/child, empty/non-empty, and equal-count Category/Tag fixtures.

The authorization matrix verified:

- published content visibility for all identities with `read`;
- owner versus other-author draft, pending, private, and future visibility through Core capability mapping;
- final `read_post` authorization for Search and Get;
- the additional `edit_post` requirement for password-protected content;
- Search-to-Get consistency for every returned content object;
- identical existence-hiding behavior for missing, wrong-type, unauthorized, and protected-but-not-editable objects.

Taxonomy calls verified fixed `category`/`post_tag` selection, search, pagination, every supported sort direction, `hide_empty`, Category `parent_id`, Tag omission of `parent_id`, Core-maintained term counts, and exact output fields.

Normalized Posts, Pages, Terms, and term relationships were captured before and after all MCP calls. The snapshots were identical. Authentication-use metadata, cache data, and transients were intentionally excluded from this content-integrity comparison.

## Live MCP wire evidence

The canonical public endpoint remains `/wp-json/wp-auto/mcp`. The stock disposable Apache container had no effective pretty-permalink rewrite, so the equivalent WordPress REST query route `index.php?rest_route=/wp-auto/mcp` was used for the local wire run. This does not change the registered route or public endpoint contract.

| Check | Result |
| --- | --- |
| Authenticated `initialize` | HTTP 200 |
| `notifications/initialized` | HTTP 202 |
| Negotiated protocol | `2025-11-25` |
| Session DELETE | HTTP 200 |
| Anonymous `initialize` | HTTP 401 |
| Authenticated identity without `read` | HTTP 403 |
| `tools/list` | Exact eight-name set |
| Resources/prompts | Not exposed by the dedicated registrar |
| Input/output schemas | All eight strict; exact property sets |
| Wire annotations | `readOnlyHint=true`, `destructiveHint=false`, `idempotentHint=true` |
| Happy-path calls | All eight succeeded |
| Content/taxonomy state | Unchanged before versus after |

The exact tool set was:

1. `wp-auto-site-health`
2. `wp-auto-site-info`
3. `wp-auto-posts-search`
4. `wp-auto-post-get`
5. `wp-auto-pages-search`
6. `wp-auto-page-get`
7. `wp-auto-categories-list`
8. `wp-auto-tags-list`

Each tool rejected an unknown property. The representative negative matrix also covered invalid types, `page`/`per_page` boundaries, a 201-character search, invalid enums, `id=0`, string IDs, string `hide_empty`, and attempted `taxonomy`, `offset`, and `meta_query` injection.

Error layers remained distinct: transport authorization produced HTTP 401/403; Adapter schema rejection remained Adapter-managed; WP-Auto service failures retained their documented semantic status and appeared as MCP tool results with `isError=true` while the outer MCP exchange could remain HTTP 200.

## Client smoke

**NOT RUN - environment limitation.** The installed Codex CLI was discovered, but Windows denied execution of its packaged `codex.exe` (`Access is denied`) even for the read-only `codex mcp --help` probe. No client package was installed and no persistent MCP configuration or credential was created. The complete raw Streamable HTTP lifecycle above remains the Phase 1.2.6 protocol acceptance gate; broader client compatibility remains a Phase 1.7 release-hardening task.

## Static security and production audit

Plugin-owned MCP runtime paths contain no content, taxonomy, media, user, setting, publish, schedule, or delete mutation. They expose no arbitrary REST proxy, SQL, filesystem, shell, WP-CLI, plugin/theme administration, arbitrary post/term query, Cloud, AI-provider, analytics, telemetry, tracking, or external WP-Auto request.

The official Adapter remains isolated behind `McpAdapterLoader` and `McpServerRegistrar`; no separate MCP protocol implementation was introduced. Composer files, dependency versions, plugin/minimum versions, CI, and all production PHP remained unchanged.

## Cleanup and remaining release gates

All temporary users, roles/capabilities, Application Passwords, fixtures, request files, containers, database/WordPress volumes, Docker network, and validation scripts were removed. A post-run Docker query returned zero matching containers, volumes, and networks. No credentials or generated artifacts remain in the repository.

Plugin Check was not installed and was not downloaded. Clean-install/minimum-version testing, independently installed Adapter coexistence, distribution ZIP review, final WordPress.org dependency review, and the broader client matrix remain release-hardening gates; none invalidates the completed Phase 1.2 read-only contract.
