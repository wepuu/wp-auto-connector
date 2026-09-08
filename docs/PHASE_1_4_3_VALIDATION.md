# Phase 1.4.3 Validation — Media Metadata Update

Status: **COMPLETE WHEN THIS RECORD AND IMPLEMENTATION LAND ON `main`**

Validation date: 2026-09-08

Base runtime: `main@16a02ab`

Validation branch: `feat/phase-1-4-media-update`

This checkpoint adds only `wp-auto/media-update`. It does not add remote URL
import, featured-image mutation, publishing, deletion, taxonomy mutation, SEO,
Cloud, telemetry, outbound HTTP, resources, prompts, dependencies, or a generic
attachment/meta surface. The Direct MCP server exposes exactly sixteen
explicitly allowlisted tools.

## Implementation boundary

- `MediaUpdateContract` owns the strict input and shared full-record output.
- `MediaUpdateService` accepts only title, alt text, caption, and description;
  rejects all extra input; repeats `upload_files`; and requires attachment
  `edit_post` without disclosing missing, wrong-type, unsupported, or denied
  targets.
- The target is re-fetched immediately before comparing the raw Core
  `post_modified_gmt` token. The exact Core zero sentinel is accepted; this is
  documented best-effort second-precision concurrency, not atomic CAS.
- An operation-scoped `wp_insert_post_data` guard permits only allowlisted post
  presentation fields and preserves attachment type, status, name, author,
  parent, dates, MIME, GUID, and other forbidden row fields.
- Alt text maps only to `_wp_attachment_image_alt`. Its ambiguous Core false
  return is resolved by exact readback instead of being treated as failure by
  itself.
- Final verification preserves attached/original file identity and every
  protected or omitted value before returning the shared full Media record.
- Post-row and alt-meta writes are not represented as transactional. A possible
  partial write, failed final verification, or audit failure returns
  `wp_auto_media_state_uncertain` without a blind rollback.
- `MediaMutationAuditStore` accepts the exact update event family in the
  existing private media audit container, retains the newest 20 events, and
  stores no presentation text, filename, path, bytes, request, or credential.
- The dedicated allowlist appends only `wp-auto/media-update` after Upload.

## Automated evidence

The implementation candidate passed:

- `composer validate --strict`;
- PHPUnit: **352 tests / 1,826 assertions**;
- `composer lint`: **80 of 80 PHP files**;
- `composer audit --locked` with no known advisories; and
- production dependency install dry-run with no required package change.

Tests freeze the exact schema and annotations, sixteen-tool order, plugin
registration, strict field/date/bound validation, zero timestamp sentinel,
service-layer and object permissions, existence hiding, final stale-write
check, all four mutable fields, alt-only behavior, Core slash round-trip,
protected row invariants, scoped-guard cleanup, deterministic Core failure,
partial multi-write ambiguity, audit failure, exact audit shape, mixed audit
retention, and exclusion of presentation content from private attribution.

## Disposable live runtime

The focused live validation used locally cached Docker images with:

- WordPress 6.9;
- PHP 8.1;
- Docker Desktop 4.82.0 / Engine 29.6.1 and MariaDB 11;
- bundled official MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only;
- WordPress Application Password authentication;
- Streamable HTTP protocol `2025-11-25`; and
- endpoint `/index.php?rest_route=/wp-auto/mcp` on disposable port 8898.

No image or package was downloaded for this run. The administrator and
Subscriber Application Passwords were deleted in the validation `finally`
path, after which both containers, the isolated network, and both named data
volumes were removed.

## Live MCP evidence

- authenticated initialization and `notifications/initialized` succeeded;
- `tools/list` returned exactly sixteen tools including
  `wp-auto-media-update` with `readOnlyHint=false`, `destructiveHint=true`, and
  `idempotentHint=false`;
- an administrator uploaded one supported PNG through the existing Upload
  tool, then updated title, alt text, caption, and description through Update;
- the Update response returned the exact full Media record with all four
  canonical stored values;
- replaying the original `modified_gmt` after the successful update was
  rejected as a stale mutation and did not replace the title;
- post type, status, slug, author, parent, local/GMT creation dates, MIME, GUID,
  and attached-file identity matched their pre-update values;
- the private attachment audit contained exactly two events: the original
  Upload event and one eight-field Update event;
- the Update audit contained no title, alt text, caption, description,
  filename, path, bytes, or request data; and
- an authenticated Subscriber completed MCP initialization but its Update call
  was rejected and the stored title remained unchanged.

Plugin Check was not installed and was not downloaded for this checkpoint. It
remains a required Phase 1.4.6/pre-release gate.

## Verdict

```text
Automated tests = 352 / 352 PASS
Automated assertions = 1,826 PASS
Direct MCP tools = 16 exact in registration tests
External requests added by implementation = NONE
Live WordPress/MCP validation = PASS
Live Direct MCP tools = 16 exact
Live attachment audit events = 2 exact
Disposable credentials/containers/volumes = REMOVED
Phase 1.4.3 = COMPLETE WHEN THIS RECORD LANDS ON MAIN
Next implementation checkpoint = Phase 1.4.4 Featured Image Assignment
```
