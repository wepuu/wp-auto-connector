# Phase 1.4.2 Validation — Authenticated Image Upload

Status: **COMPLETE WHEN THIS RECORD AND IMPLEMENTATION LAND ON `main`**

Validation date: 2026-09-08

Base runtime: `main@fe861cd`

Validation branch: `feat/phase-1-4-media-upload`

This checkpoint adds only `wp-auto/media-upload`. It does not add remote URL
import, metadata update, featured-image mutation, publishing, deletion,
taxonomy mutation, SEO, Cloud, telemetry, outbound HTTP, resources, prompts,
dependencies, or a generic file/media surface. The Direct MCP server exposes
exactly fifteen explicitly allowlisted tools.

## Implementation boundary

- `MediaUploadContract` owns the strict input schema and full Media Get output
  plus `idempotency_replayed`.
- `MediaUploadService` validates canonical Base64 and proposed basenames,
  enforces the smaller of 10 MiB and `wp_max_upload_size()`, repeats
  `upload_files`, hides invalid optional parents, and uses Core temporary-file,
  MIME/image, sideload, attachment, and metadata APIs.
- Optional parents are limited to editable Post/Page drafts and are
  re-authorized immediately before the Core write.
- The final attachment is re-read and verified for type, status, actor, parent,
  supported MIME, original decoded-byte hash, dimensions, and canonical public
  output. Core-managed scaling/sub-sizes remain valid lifecycle effects.
- `MediaIngestionIdempotencyStore` uses the existing atomic ownership primitive
  with a separate site/actor/Ability/raw-key hash namespace. Its non-autoloaded
  record never contains the raw key, Base64, bytes, filename, or request.
- Deterministic pre-Core failures clean the owned temporary file and may release
  only the exact initial claim. Possible Core, filesystem, audit, or
  finalization ambiguity returns `wp_auto_media_state_uncertain` and retains
  ownership; there is no TTL or takeover.
- `MediaMutationAuditStore` uses a separate private meta key, accepts only the
  seven fixed Upload fields, rejects duplicate/sparse/malformed containers, and
  retains at most the newest 20 events.
- Explicit uninstall cleanup now covers exact media claim and audit families
  while preserving prefix neighbors and unrelated state.
- The dedicated allowlist appends only `wp-auto/media-upload` after the sealed
  Media Search/Get tools.

## Automated evidence

The implementation candidate passed:

- `composer validate --strict`;
- PHPUnit: **339 tests / 1,765 assertions**;
- `composer lint`: **75 of 75 PHP files**;
- `composer audit --locked` with no known advisories;
- production dependency install dry-run with no required package change; and
- `git diff --check`.

Tests freeze the schema, annotations, exact fifteen-tool order, plugin
registration, strict Base64/basename/size handling, fixed MIME intersection,
MIME spoof rejection, service-layer permissions, draft-parent authorization,
single attachment creation, final hash verification, completed replay,
payload conflict, live-claim blocking, audit-recorded recovery, fail-closed
Core ambiguity, private record shapes, atomic namespace isolation, bounded
audit retention, and exact uninstall cleanup.

## Disposable live runtime

The focused live validation used official `@wordpress/env` 11.14.0 with:

- WordPress 6.9;
- PHP 8.1;
- Docker Desktop / MariaDB;
- bundled official MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only;
- WordPress Application Password authentication;
- Streamable HTTP protocol `2025-11-25`; and
- endpoint `/index.php?rest_route=/wp-auto/mcp` on a disposable local port.

The run created an administrator-owned Post draft, uploaded one canonical 1×1
PNG attached to that draft, and exercised administrator and Subscriber MCP
sessions. Every temporary Application Password was deleted in the same run;
the final credential count for both identities was zero. The entire wp-env
environment and its data volumes were destroyed after validation.

## Live MCP evidence

- anonymous initialization returned HTTP 401;
- authenticated initialization and `notifications/initialized` succeeded;
- `tools/list` returned exactly fifteen tools ending with
  `wp-auto-media-search`, `wp-auto-media-get`, and `wp-auto-media-upload`;
- Upload advertised `readOnlyHint=false`, `destructiveHint=false`, and
  `idempotentHint=true`;
- the first Upload returned one exact full image record with the authorized
  draft parent, `width=1`, `height=1`, and `idempotency_replayed=false`;
- the identical retry returned the same attachment ID with
  `idempotency_replayed=true` and the attachment count remained one;
- the same key with a different sanitized filename returned
  `wp_auto_idempotency_conflict` semantics before another write;
- a data-URI-prefixed Base64 value returned the stable invalid-request result;
- an authenticated Subscriber passed transport authentication but the Upload
  tool was rejected because it lacked `upload_files`;
- the durable media claim used the exact hashed namespace with `autoload=off`;
- the attachment audit contained exactly one seven-field Upload event and no
  filename, path, bytes, Base64, raw key, request, URL, or credential; and
- both MCP sessions were explicitly deleted.

No secret, Authorization header, raw idempotency key, local path, image bytes,
or response artifact is stored in this validation record.

## Plugin Check and remaining boundary

Plugin Check was not installed in the disposable environment and was not
downloaded for this checkpoint. It remains a required pre-release gate when an
audited installation is available. Phase 1.4.6 still owns the complete media
integration/security matrix, including ingestion concurrency/process-death
coverage, filesystem failure variants, remote-import SSRF/DNS/redirect policy,
state integrity, packaging, and Plugin Check.

## Verdict

```text
Automated tests = 339 / 339 PASS
Automated assertions = 1,765 PASS
Direct MCP tools = 15 exact
Image attachments created by identical retries = 1 exact
External requests = NONE
Phase 1.4.2 = COMPLETE WHEN THIS RECORD LANDS ON MAIN
Next checkpoint = Phase 1.4.3 Media Metadata Update
```
