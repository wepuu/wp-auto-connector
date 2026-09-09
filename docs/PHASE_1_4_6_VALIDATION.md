# Phase 1.4.6 Media Integration and Security Validation

Status: complete and formally sealed on `main`; the approved distribution identity resolves the final Plugin Check warnings.

Validation date: 2026-09-09

This checkpoint validates the Phase 1.4 media surface through automated tests, disposable WordPress 6.9/PHP 8.1 and WordPress 7.1/PHP 8.1 runtimes, and a prompt-driven security diff scan. The runtime contains exactly eighteen explicitly allowlisted tools, with `wp-auto/media-import-url` as the final tool. No publishing, deletion, taxonomy mutation, SEO, Cloud, telemetry, generic URL fetching, or arbitrary filesystem/code execution was added.

## Automated quality gates

- PHPUnit: 393 tests, 2,144 assertions, passing.
- `composer validate --strict`: passing.
- `composer lint`: 102/102 files passing.
- `git diff --check`: passing.
- New seam coverage covers in-progress idempotency ownership, completed-payload conflicts, claim retention after Core uncertainty, redirect pivots, redirect loops, non-identity encodings, effective upload-size overflow, and temporary-file cleanup.
- Three identity regression tests cover the approved plugin header/readme/Composer identity, all production gettext domains, and preservation of the MCP, REST, Ability, and persistent-state compatibility identifiers.

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

The product-identity working-tree diff received a separate complete prompt-only review as scan `38547def-5392-45d1-b437-6f99803429ba`. All 37 production review items were covered in the parent thread, the baseline-to-candidate normalization proved the PHP changes were limited to the authorized name/text-domain substitutions, and the scan completed with zero findings. It specifically reviewed bootstrap/package identity, MCP and Ability registration, all changed domain services, and Composer production metadata. TAC remained not granted and was advisory only, as previously authorized.

## Product identity resolution

The approved public identity is:

- plugin name: `WePuu Auto Connector`;
- WordPress.org slug and package root: `wepuu-auto-connector`;
- main file: `wepuu-auto-connector.php`;
- text domain and Settings page slug: `wepuu-auto-connector`; and
- Composer root package: `wepuu/wepuu-auto-connector`.

The migration intentionally preserves `WPAuto\Connector`, all `WP_AUTO_CONNECTOR_*` constants, every `wp-auto/*` Ability, every `wp-auto-*` MCP tool, the `wp-auto-direct` server ID, `/wp-auto/mcp`, all `wp_auto_connector_*` options/errors/hooks, and existing audit/post-meta keys. This avoids breaking clients or orphaning installed mutation-safety state.

## Plugin Check result

An isolated Docker environment ran official Plugin Check 2.1.0 against a release-like copy of the repaired build on WordPress 6.9 and PHP 8.1.34. The copy contained the production entry files, `src`, `readme.txt`, `LICENSE`, `composer.json`, `composer.lock`, and the locked three-package production Composer build; development files and the nested MCP Adapter plugin entry file excluded by `.distignore` were absent.

Both the default static WP-CLI scan and the documented runtime-enabled scan using `--require=/var/www/html/wp-content/plugins/plugin-check/cli.php` completed. The release-like plugin also activated successfully and its production bootstrap returned `BOOT_OK`. The pre-repair scan reported one error and seven warnings. Packaging/code-quality repairs reduced that result to only three product-identity warnings. The approved identity migration then removed those warnings: Plugin Check 2.1.0 reports `Success: Checks complete. No errors found.` for both static and runtime-enabled scans, with no warning rows emitted.

The identity candidate was packaged under the exact `wepuu-auto-connector/` root with `wepuu-auto-connector.php`, production source, readme/license/Composer manifests, and the locked three-package production vendor build. The nested MCP Adapter standalone plugin entrypoint and all development-only files were absent. The generated `wepuu-auto-connector.zip` is retained as validation evidence; the Plugin Check site, credentials, containers, volumes, and network were deleted, and exact-name follow-up queries returned zero Docker resources.

## Environment limitations and residual gates

- A deterministic PHP-worker process-death harness around the Core sideload boundary was not run. The implementation fails closed with `wp_auto_media_state_uncertain` and retains the idempotency claim when durable state cannot be proven.

## Verdict

Phase 1.4.6 implementation, main integration, exact-main runtime/security validation, WordPress 6.9/7.1 compatibility, approved identity migration, and release-like packaging evidence are complete. The media contract, exact eighteen-tool allowlist, automated suite, disposable live MCP matrices, state-integrity checks, cleanup checks, and security reviews pass. Official Plugin Check reports no errors or warnings for `WePuu Auto Connector` / `wepuu-auto-connector`. Phase 1.4 is formally sealed; Phase 1.5 still requires an explicit future task.
