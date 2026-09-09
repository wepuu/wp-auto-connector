# Phase 1.4.6 Media Integration and Security Validation

Status: candidate validation complete; formal Phase 1.4 seal remains pending landing on `main` and the exact-main release review.

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

The Codex Security prompt-only diff scan reviewed all 18 changed production files against `main@09d4268`. Result: zero findings, complete source coverage, and no reportable candidates. The sealed report is scan `7560e18d-a813-424b-be8e-e73eac47ea25`; the generated report and SARIF remain in the Codex Security temporary scan bundle.

The review covered transport and Ability authorization, URL/DNS/redirect handling, cURL destination pinning, transfer/time/MIME bounds, temporary-file lifecycle, atomic idempotency ownership, audit locking, bootstrap, and the eighteen-tool allowlist. TAC was checked once and was not granted; the local prompt-only review proceeded as authorized.

## Environment limitations and residual gates

- Plugin Check was unavailable in the cached disposable environment. It was not downloaded because repository policy prohibits remote executable packages.
- A deterministic PHP-worker process-death harness around the Core sideload boundary was not run. The implementation fails closed with `wp_auto_media_state_uncertain` and retains the idempotency claim when durable state cannot be proven.
- The candidate branch still needs exact-main review after integration. Until that review and merge, this document is evidence for the candidate implementation, not a release seal.

## Verdict

Phase 1.4.6 implementation and candidate validation evidence are complete. The media contract, eighteen-tool allowlist, automated suite, disposable live MCP matrix, state-integrity checks, cleanup checks, and security review all pass. Formal Phase 1.4 sealing should occur only after the candidate is landed on `main`, the exact-main diff is rechecked, and Plugin Check is run when an audited environment is available.
