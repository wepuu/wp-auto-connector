# Phase 1.4.1 Validation — Media Search/Get

Status: **COMPLETE — FORMALLY SEALED ON MAIN WHEN THIS RECORD LANDS**

Validation date: 2026-09-08

Runtime baseline: `main@2159f0f`

Validation branch: `feat/phase-1-4-media-read`

This checkpoint implements only `wp-auto/media-search` and
`wp-auto/media-get`. It adds no upload, import, metadata mutation, featured
image mutation, publishing, deletion, taxonomy mutation, SEO, Cloud,
telemetry, external request, resource, prompt, dependency, or generic media
surface. The Direct MCP server exposes exactly fourteen tools.

## Implementation boundary

- `MediaReadContract` owns the strict Search/Get input and output schemas.
- `MediaReadService` owns repeated service-layer validation, supported-image
  filtering, attachment and parent authorization, normalized output, and the
  bounded logical-pagination scanner.
- Media Ability classes retain the fixed `upload_files` baseline and read-only,
  non-destructive, idempotent annotations.
- The dedicated server allowlist appends only Media Search and Media Get after
  the sealed twelve-tool Phase 1.3 order.
- Search scans fixed raw chunks of at most 100 and examines at most 1,000 raw
  candidates. It exposes no total and collects at most `per_page + 1` eligible
  records.
- Get hides missing, wrong-type, unsupported, attachment-unauthorized, and
  parent-hidden targets behind the same `wp_auto_media_not_found` result.
- Output exposes a basename and WordPress attachment URL, never a local path or
  arbitrary attachment metadata.

## Automated evidence

The implementation candidate passed:

- `composer validate --strict`;
- PHPUnit: **319 tests / 1,628 assertions**;
- `composer lint`: **66 of 66 PHP files**;
- `composer audit --locked` with no known advisories.

Tests freeze the category, Ability registrations, strict schemas, annotations,
plugin bootstrap, exact registrar order, input bounds/defaults, supported MIME
set, deterministic ordering, lightweight and full output shapes, attachment
and parent authorization, password-protected parents, existence hiding,
corrupt-record behavior, logical pagination, the 1,000-candidate ceiling, and
the absence of write APIs.

## Disposable live runtime

The live validation used official `@wordpress/env` 11.14.0 with:

- WordPress 6.9;
- PHP 8.1.34;
- MariaDB LTS;
- bundled official MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only;
- WordPress Application Password authentication;
- Streamable HTTP protocol `2025-11-25`;
- endpoint `/index.php?rest_route=/wp-auto/mcp` on
  `http://localhost:8892`.

The fixture set contained an unattached PNG, a JPEG attached to a readable
published Post, a WebP attached to another user's private Post, and an
unsupported PDF. An Author exercised successful media reads, a Subscriber
exercised the missing `upload_files` baseline, and a custom authenticated role
with an explicit false `read` capability exercised transport rejection.

## Live MCP evidence

The run completed **48 checks with no failure**:

- authenticated `initialize` and `notifications/initialized` succeeded;
- anonymous transport returned HTTP 401 and authenticated transport without
  `read` returned HTTP 403;
- `tools/list` returned the exact ordered fourteen-tool set ending with
  `wp-auto-media-search` and `wp-auto-media-get`;
- resources and prompts remained empty;
- both Media inputs retained `additionalProperties=false`;
- both tools retained `readOnlyHint=true`, `destructiveHint=false`, and
  `idempotentHint=true`;
- Media Search returned only the two authorized supported images in stable ID
  order and the exact lightweight record/envelope fields;
- the fixed `image/png` filter returned only the PNG;
- Media Get returned the exact full record including fixed alt metadata,
  caption, description, and validated 640 by 480 dimensions;
- the parent-hidden WebP and unsupported PDF returned identical existence-
  hiding errors;
- an unsupported deep page returned the bounded pagination
  application error;
- a Subscriber passed transport authentication but Media Search was rejected
  for lack of `upload_files`;
- both MCP sessions were explicitly deleted;
- a post-run semantic snapshot proved that the attachments, parents, titles,
  MIME values, attached-file metadata, alt text, and image metadata were
  unchanged by all read calls.

A focused follow-up smoke used the final Phase 1.2-compatible proof boundary
and confirmed that `page=1000, per_page=1` is rejected before an unprovable
`per_page + 1` result window can be claimed.

No password, Application Password, Authorization header, local path, response
artifact, or fixture content was written to the validation record.

## Plugin Check and remaining boundary

Plugin Check was not installed in the disposable environment and was not
downloaded for this task. It remains a required pre-release gate. Phase 1.4.6
still owns the full Phase 1.4 integration/security matrix, including ingestion
concurrency, temporary-file cleanup, SSRF, mutation integrity, packaging, and
Plugin Check where an audited installation is available.

## Verdict

```text
Automated tests = 319 / 319 PASS
Automated assertions = 1,628 PASS
Live MCP checks = 48 / 48 PASS
Direct MCP tools = 14 exact
Media mutations = NONE
External requests = NONE
Phase 1.4.1 = COMPLETE WHEN THIS RECORD LANDS ON MAIN
Next checkpoint = Phase 1.4.2 Authenticated Image Upload
```
