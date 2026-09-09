# Phase 1.4.6 Media Integration and Security Validation

Status: implementation is merged on `main`; exact-main validation is complete; formal Phase 1.4 seal is blocked by the audited Plugin Check result.

Validation date: 2026-09-09

This checkpoint validates the Phase 1.4 media surface through automated tests, a disposable WordPress 6.9/PHP 8.1 Streamable HTTP runtime, and a prompt-driven security diff scan. The runtime contains exactly eighteen explicitly allowlisted tools, with `wp-auto/media-import-url` as the final tool. No publishing, deletion, taxonomy mutation, SEO, Cloud, telemetry, generic URL fetching, or arbitrary filesystem/code execution was added.

## Automated quality gates

- PHPUnit: 390 tests, 2,012 assertions, passing.
- `composer validate --strict`: passing.
- `composer lint`: 101/101 files passing.
- `git diff --check`: passing.
- New seam coverage covers in-progress idempotency ownership, completed-payload conflicts, claim retention after Core uncertainty, redirect pivots, redirect loops, non-identity encodings, effective upload-size overflow, and temporary-file cleanup.

## Disposable live runtime

The test stack used locally cached Docker images equivalent to the official wp-env baseline: WordPress 6.9, PHP 8.1.34, Docker Engine 29.6.1, MariaDB 11.8.9, and MCP Adapter 0.6.1. It ran with `WP_ENVIRONMENT_TYPE=local` and the real Streamable HTTP route `/index.php?rest_route=/wp-auto/mcp` on loopback port 8899.

No remote package, image, plugin, executable, or other code was downloaded. The plugin was mounted from the validation worktree. The front-end request path initially exposed a Core-loading gap (`wp_tempnam()` was unavailable); the downloader now loads `wp-admin/includes/file.php` on demand and the real import path passes.

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

An isolated Docker environment ran official Plugin Check 2.1.0 against a release-like copy of `main@be12037` on WordPress 6.9 and PHP 8.1.34. The copy contained the production entry files, `src`, `readme.txt`, `LICENSE`, and the locked three-package production Composer build; development files and the nested MCP Adapter plugin entry file excluded by `.distignore` were absent.

Both the default static WP-CLI scan and the documented runtime-enabled scan using `--require=/var/www/html/wp-content/plugins/plugin-check/cli.php` completed. The release-like plugin also activated successfully and its production bootstrap returned `BOOT_OK`. Both scans reported the same one error and seven warnings:

- error: `outdated_tested_upto_header` because `Tested up to: 6.9` is behind the checker current version, WordPress 7.1;
- three warnings: the existing WP-Auto name and `wp-auto-connector` slug trademark checks;
- warning: `missing_composer_json_file` because the release-like package contains the production `vendor` tree while `.distignore` excludes `composer.json`;
- warning: the declared `/languages` domain path does not yet exist in the release-like package; and
- two warnings: the already reviewed ADR-003 and ADR-004 prepared DirectDB boundaries in `AtomicOwnershipStore` and `PrivateStateCleanup`.

The tested-version error is a formal sealing blocker. This validation does not silently claim WordPress 7.1 compatibility, rename the approved product/slug, change the dependency packaging contract, add release-only structure, or rewrite the approved SQL boundaries. Those findings require an explicit release-readiness decision and, where changed, fresh compatibility and packaging validation.

The Plugin Check package, test site, credentials, containers, volumes, network, and release-like copy were deleted after the scan; exact-name follow-up queries returned zero Docker resources.

## Environment limitations and residual gates

- A deterministic PHP-worker process-death harness around the Core sideload boundary was not run. The implementation fails closed with `wp_auto_media_state_uncertain` and retains the idempotency claim when durable state cannot be proven.
- The implementation is on `main`, the exact-main review is complete, and Plugin Check was run against `main@be12037`. This document is not a formal release seal because the Plugin Check error remains unresolved.

## Verdict

Phase 1.4.6 implementation, main integration, and exact-main runtime/security validation evidence are complete. The media contract, eighteen-tool allowlist, automated suite, disposable live MCP matrix, state-integrity checks, cleanup checks, and both security reviews pass. Official Plugin Check ran successfully as a tool but did not pass the release gate: its tested-version error blocks formal Phase 1.4 sealing until the release-readiness findings are resolved and the checker is rerun.
