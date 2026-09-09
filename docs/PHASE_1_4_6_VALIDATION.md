# Phase 1.4.6 Media Integration and Security Validation

Status: implementation is merged on `main`; exact-main validation is complete; WordPress 7.1 compatibility is verified; formal Phase 1.4 seal remains blocked by product-identity Plugin Check warnings.

Validation date: 2026-09-09

This checkpoint validates the Phase 1.4 media surface through automated tests, disposable WordPress 6.9/PHP 8.1 and WordPress 7.1/PHP 8.1 runtimes, and a prompt-driven security diff scan. The runtime contains exactly eighteen explicitly allowlisted tools, with `wp-auto/media-import-url` as the final tool. No publishing, deletion, taxonomy mutation, SEO, Cloud, telemetry, generic URL fetching, or arbitrary filesystem/code execution was added.

## Automated quality gates

- PHPUnit: 390 tests, 2,012 assertions, passing.
- `composer validate --strict`: passing.
- `composer lint`: 101/101 files passing.
- `git diff --check`: passing.
- New seam coverage covers in-progress idempotency ownership, completed-payload conflicts, claim retention after Core uncertainty, redirect pivots, redirect loops, non-identity encodings, effective upload-size overflow, and temporary-file cleanup.

## Disposable live runtime

The test stack used locally cached Docker images equivalent to the official wp-env baseline: WordPress 6.9, PHP 8.1.34, Docker Engine 29.6.1, MariaDB 11.8.9, and MCP Adapter 0.6.1. It ran with `WP_ENVIRONMENT_TYPE=local` and the real Streamable HTTP route `/index.php?rest_route=/wp-auto/mcp` on loopback port 8899.

No remote package, image, plugin, executable, or other code was downloaded. The plugin was mounted from the validation worktree. The front-end request path initially exposed a Core-loading gap (`wp_tempnam()` was unavailable); the downloader now loads `wp-admin/includes/file.php` on demand and the real import path passes.

## WordPress 7.1 compatibility

Because a `wordpress:7.1-php8.1-apache` Docker tag was not available, an isolated
WordPress 6.9/PHP 8.1 Apache container was upgraded with the official WordPress
7.1 core files while retaining a read-only plugin mount. The compatibility smoke
test then passed:

- `wp core version` returned `7.1`;
- the release-like plugin activated successfully and its main class returned
  `BOOT_OK`;
- the site-health diagnostics reported WordPress `7.1`, PHP `8.1.34`, Abilities
  API available, MCP Adapter `0.6.1` available, and REST API available;
- the Abilities registry contained 24 entries: the 18 expected WP-Auto abilities
  plus the documented three Core and three Adapter entries; and
- the REST server exposed `/wp-auto/mcp`.

## Live MCP matrix

- Anonymous initialization returned HTTP 401.
- Authenticated initialization negotiated protocol `2025-11-25`.
- `tools/list` returned exactly 18 tools; the final tool was `wp-auto-media-import-url` with `readOnlyHint=false`, `destructiveHint=false`, and `idempotentHint=true`.
- Private loopback, URL-fragment, and non-image requests were rejected as MCP application errors.
- A controlled public WordPress.org PNG created one attachment; replaying the same actor, key, and payload returned the same attachment with `idempotency_replayed=true`.
- Two simultaneous identical requests returned one successful attachment owner and one MCP error; attachment count increased by two across the distinct test keys, with no duplicate owner for the concurrent key.
- Before/after checks showed posts, pages, terms, and unrelated state remained stable.
- Each successful import produced one exact seven-field private `import_url` audit event. No raw URL, key, request body, credential, or temporary downloader file remained in options, post meta, or the upload temp area.
- A subscriber request was rejected. Temporary application passwords were deleted and verified at zero.

## Security review

The candidate Codex Security prompt-only diff scan reviewed all 18 changed production files against `main@09d4268` with zero findings. After the fast-forward merge, the exact-main range `09d4268..e72bdb6` was reviewed again as scan `cdc2d267-c5b3-4a5b-ade6-6497dc420b12`: zero findings, complete source coverage, and no reportable candidates. Generated reports and SARIF remain in the Codex Security temporary scan bundles.

The review covered transport and Ability authorization, URL/DNS/redirect handling, cURL destination pinning, transfer/time/MIME bounds, temporary-file lifecycle, atomic idempotency ownership, audit locking, bootstrap, and the eighteen-tool allowlist. TAC was checked once and was not granted; the local prompt-only review proceeded as authorized.

## Plugin Check result

An isolated Docker environment ran official Plugin Check 2.1.0 against a release-like copy of the repaired build on WordPress 6.9 and PHP 8.1.34. The copy contained the production entry files, `src`, `readme.txt`, `LICENSE`, `composer.json`, `composer.lock`, and the locked three-package production Composer build; development files and the nested MCP Adapter plugin entry file excluded by `.distignore` were absent.

Both the default static WP-CLI scan and the documented runtime-enabled scan using `--require=/var/www/html/wp-content/plugins/plugin-check/cli.php` completed. The release-like plugin also activated successfully and its production bootstrap returned `BOOT_OK`. The pre-repair scan reported one error and seven warnings. The repair removed the missing Composer manifest, nonexistent Domain Path, and two reviewed DirectDB warnings. After updating `Tested up to` to 7.1, the static and runtime-enabled scans report no errors and only three product-identity warnings:

- three `trademarked_term` warnings: the existing `WP-Auto` display name and `wp-auto-connector` slug checks.

The tested-version error and packaging/code-quality warnings are resolved. This validation does not silently rename the approved product/slug; the remaining identity warnings require an explicit product-name/slug decision (or WordPress.org review outcome) before formal Phase 1.4 sealing.

The Plugin Check package, test site, credentials, containers, volumes, network, and release-like copy were deleted after the scan; exact-name follow-up queries returned zero Docker resources.

## Environment limitations and residual gates

- A deterministic PHP-worker process-death harness around the Core sideload boundary was not run. The implementation fails closed with `wp_auto_media_state_uncertain` and retains the idempotency claim when durable state cannot be proven.
- The implementation is on `main`, the exact-main review is complete, and the repaired release-like build passes Plugin Check with no errors. This document is not a formal release seal because the three product-identity warnings remain unresolved.

## Verdict

Phase 1.4.6 implementation, main integration, exact-main runtime/security validation, WordPress 7.1 compatibility, and repaired release-like packaging evidence are complete. The media contract, eighteen-tool allowlist, automated suite, disposable live MCP matrices, state-integrity checks, cleanup checks, and both security reviews pass. Official Plugin Check now reports no errors; formal Phase 1.4 sealing awaits an explicit product identity/slug decision for the three remaining trademark warnings.
