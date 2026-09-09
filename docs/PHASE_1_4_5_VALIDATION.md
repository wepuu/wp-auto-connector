# Phase 1.4.5 Remote URL Import Validation

Status: implementation and focused live validation complete; superseded by the Phase 1.4.6 candidate validation record.

Validation date: 2026-09-09

This checkpoint adds only `wp-auto/media-import-url` and extends the explicit Direct MCP allowlist from seventeen to exactly eighteen tools. It does not add publishing, deletion, generic URL fetching, arbitrary filesystem access, Cloud, telemetry, or background requests.

## Implementation boundary

- `MediaImportContract` owns the strict URL, filename, idempotency, parent, and media-record schemas.
- `RemoteUrlPolicy` rejects credentials, fragments, IP literals, ambiguous hosts, unsupported schemes, and ports outside 80/443, and classifies all fixed IPv4/IPv6 special-use ranges.
- `RemoteMediaDownloader` resolves every redirect independently, limits redirects to three, streams to a WordPress temporary file, enforces the effective byte cap, rejects encoded expansion and length mismatches, and cleans owned files.
- Front-end MCP/REST requests do not preload the administrative file API, so the downloader loads Core's `wp-admin/includes/file.php` on demand before calling `wp_tempnam()`.
- `WordPressRemoteHttpClient` uses the WordPress HTTP API with curl destination pinning, redirects disabled, direct transport, fixed headers, and fixed timeouts.
- `MediaImportService` repeats capability/parent checks, claims persistent idempotency before network work, runs the fixed Core image validation, creates one attachment through Core sideload APIs, verifies the result, appends private `import_url` attribution, and completes the claim fail-closed.

## Automated evidence

The repository PHPUnit suite passes with 383 tests and 1,984 assertions. New coverage includes strict URL canonicalization, credential/fragment/IP/port rejection, IPv4/IPv6 special-use ranges, redirect revalidation, mixed DNS answer rejection, DNS consumption of the whole-request deadline, Content-Length truncation, temporary-file cleanup, import creation/replay, private policy failures, and exact eighteen-tool registration.

`composer validate --strict` and full-repository `composer lint` pass for the production implementation and tests.

The candidate security diff scan inspected all eighteen changed production files and reported zero findings. This is branch-candidate evidence, not the exact-`main` Phase 1.4.6 seal.

## Disposable live runtime

The locally cached equivalent of the official wp-env runtime was used because repository policy prohibits downloading or executing remote executable packages. The isolated stack used:

- WordPress 6.9 and PHP 8.1.34;
- Docker Engine 29.6.1 and MariaDB 11.8.9;
- the bundled official MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only; and
- `/index.php?rest_route=/wp-auto/mcp` on loopback port 8899.

No package, image, plugin, or executable was downloaded. A front-end-only regression was found during the run: `wp_tempnam()` was unavailable on the MCP request path because Core's administrative file API was not loaded. The downloader now loads that Core API on demand, after which the production downloader fetched the controlled public PNG successfully.

## Focused live MCP evidence

- unauthenticated initialization returned HTTP 401;
- authenticated initialization negotiated protocol `2025-11-25`;
- `tools/list` returned exactly eighteen tools, with `wp-auto-media-import-url` last and annotated `readOnlyHint=false`, `destructiveHint=false`, and `idempotentHint=true`;
- `http://127.0.0.1/private.png` was rejected as an MCP application error before any private request;
- the controlled public WordPress.org PNG created attachment ID 5 through the real Streamable HTTP endpoint;
- replaying the same actor, Ability, key, and payload returned attachment ID 5 with `idempotency_replayed=true`;
- the target contained one exact seven-field `import_url` audit event and no raw URL;
- the raw public host was absent from option and post-meta values;
- no downloader-owned temporary file remained; and
- all temporary WordPress Application Passwords were deleted (`0` remained).

Plugin Check was not installed in the cached environment and was not downloaded for this focused checkpoint. Phase 1.4.6 subsequently ran official Plugin Check 2.1.0 against the release-like main build and recorded the unresolved release-readiness result.

## Follow-up

The broader automated/live/security matrix, exact-main review, Plugin Check result,
and residual-risk record are documented in `docs/PHASE_1_4_6_VALIDATION.md`.
The Plugin Check error blocks formal Phase 1.4 sealing; TAC is not required.
