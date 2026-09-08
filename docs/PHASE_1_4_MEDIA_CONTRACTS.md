# Phase 1.4 Media Contracts

Status: **Phase 1.4.0 frozen; Phase 1.4.1 sealed; Phase 1.4.2 Upload implemented and validated when its implementation lands on `main`**

Contract date: 2026-09-08

This is the authoritative public contract for Phase 1.4 media Abilities.
Phase 1.4.0 changed no PHP runtime, MCP allowlist, dependency, external request
behavior, or WordPress state. Phase 1.4.1 added only the two read-only Search/Get
tools defined here. Phase 1.4.2 adds only authenticated image Upload, producing
an exact fifteen-tool Direct MCP runtime.

## Goals and architecture

An authenticated, authorized WordPress identity can eventually discover and
inspect supported images, upload or safely import one image, update narrow
attachment presentation fields, and assign an image to an editable Post/Page
draft. The path remains:

```text
MCP client -> official MCP Adapter -> Abilities API
           -> WP-Auto media Ability/service -> WordPress Core APIs
```

Media operations are not a generic file, URL, filesystem, REST, post-meta, or
attachment CRUD surface. Authentication never replaces capability and object
authorization.

## Contract-fixed names and delivery order

| Ability | MCP tool | Checkpoint |
| --- | --- | --- |
| `wp-auto/media-search` | `wp-auto-media-search` | Phase 1.4.1 |
| `wp-auto/media-get` | `wp-auto-media-get` | Phase 1.4.1 |
| `wp-auto/media-upload` | `wp-auto-media-upload` | Phase 1.4.2 |
| `wp-auto/media-update` | `wp-auto-media-update` | Phase 1.4.3 |
| `wp-auto/media-set-featured` | `wp-auto-media-set-featured` | Phase 1.4.4 |
| `wp-auto/media-import-url` | `wp-auto-media-import-url` | Phase 1.4.5 |

Remote import is last because it introduces an open-world outbound network
boundary. No placeholder Ability is registered before its checkpoint.

## Shared schema and image policy

Every input/output schema is a strict object with
`additionalProperties=false`; every documented output field is required. The
service repeats security-relevant validation after schema validation.

Phase 1.4 is image-only. A supported file must pass the authenticated user's
effective WordPress upload policy, Core real-file/extension validation, image
metadata generation, and this fixed MIME allowlist:

```text
image/jpeg
image/png
image/gif
image/webp
image/avif
```

SVG/SVGZ, ICO, BMP, TIFF, HEIC/HEIF, PDF, audio, video, archives, documents,
executables, PHP-capable files, double-extension evasions, and every other MIME
are rejected even when a plugin or `unfiltered_upload` would allow them. A
client MIME, HTTP `Content-Type`, name, or extension is never type proof.

The effective file limit is `min(10 MiB, wp_max_upload_size())`; empty files
are invalid. Dates are raw Core GMT strings. A concurrency token accepts only
the exact `0000-00-00 00:00:00` Core sentinel or a real Gregorian GMT datetime
in `YYYY-MM-DD HH:MM:SS` form.

## Shared records

A search item contains exactly:

```text
id              integer
title           string
filename        string, basename only
mime_type       fixed supported MIME
source_url      string
parent_id       integer, zero when unattached
date_gmt        raw Core string
modified_gmt    raw Core string
```

A full record contains all search fields plus:

```text
alt_text        string
caption         string
description     string
width           integer, minimum 1
height          integer, minimum 1
```

Creation adds required boolean `idempotency_replayed`. No response contains a
server path, arbitrary meta, EXIF/IPTC payload, request URL, headers,
credentials, file bytes, or private audit/idempotency state.

## Media Search

`wp-auto/media-search` requires `upload_files` and is read-only. Input:

| Field | Type | Constraints | Default |
| --- | --- | --- | --- |
| `search` | string | max 200 characters | `""` |
| `mime_type` | string | `all` or one supported MIME | `all` |
| `page` | integer | minimum 1 | 1 |
| `per_page` | integer | 1 through 50 | 10 |
| `orderby` | string | `date`, `modified`, `title`, `id` | `modified` |
| `order` | string | `asc`, `desc` | `desc` |

The query fixes `post_type=attachment`, `post_status=inherit`, and supported
image MIME values. Output is exactly `items`, `page`, `per_page`, `returned`,
and `has_more`; it exposes no totals.

Logical pagination follows Phase 1.2: raw chunks are at most 100, at most 1,000
raw candidates are examined, and at most `per_page + 1` eligible records are
collected. An unprovable page returns `wp_auto_pagination_window_exceeded`.

Every result must pass `read_post` for the attachment. An attached image's
parent must also pass the Phase 1.2 final read rule: `read_post`, plus
`edit_post` when password protected. A missing/inaccessible parent makes the
attachment ineligible. An unattached image needs attachment authorization.

Annotations: `readonly=true`, `destructive=false`, `idempotent=true`.

## Media Get

`wp-auto/media-get` requires `upload_files`. Input is only required integer
`id`, minimum 1. The target must be a supported image and pass the identical
attachment/parent visibility policy used by Search.

Missing, non-attachment, unsupported, inaccessible, or parent-hidden targets
all return `wp_auto_media_not_found` 404. Output is the full media record. An
authorized image that cannot satisfy the exact output returns
`wp_auto_media_read_failed`, never a partial object.

Annotations: `readonly=true`, `destructive=false`, `idempotent=true`.

## Shared ingestion and idempotency

Upload and URL import require `upload_files` at both Ability and service
layers. Optional `parent_id` must identify an editable `post` or `page` draft;
missing, wrong-type, non-draft, or unauthorized parents all return the same
`wp_auto_content_not_found` 404. Omission fixes parent to zero.

The service uses WordPress temporary-file and media APIs. Clients cannot set
paths, status, author, dates, slug, GUID, MIME, arbitrary attachment data/meta,
or upload overrides. After Core returns, the service verifies attachment
identity, parent, MIME, image metadata, and URL. Owned temporary files are
cleaned with WordPress filesystem helpers in `finally` unless Core moved them.

Both operations require `idempotency_key`, 16 through 128 characters. Scope:

```text
site + actor user ID + Ability name + idempotency_key
```

The raw key, bytes, Base64, URL, filename, and request are never persisted. A
private non-autoloaded
`wp_auto_connector_media_idempotency_<scope-hash>` option stores only version,
actor, Ability, SHA-256 fingerprint, state, target ID when known, and GMT
timestamps.

The existing `AtomicOwnershipStore` is the sole arbitration primitive. It may
address only this fixed option family in the active site options table using
ADR-003 insert-if-absent, cache-coherence, and exact conditional-release
rules. This adds no SQL authority over media, postmeta, or files.

Completed identical requests replay the verified target. Different payloads
return `wp_auto_idempotency_conflict`; live/unresolved claims return
`wp_auto_idempotency_in_progress`. There is no TTL/takeover. A claim is
released only after deterministic proof Core created no object. If Core may
have inserted/moved a file but correlation, verification, audit, or completion
fails, retain the claim and return `wp_auto_media_state_uncertain`.

Upload fingerprints the exact decoded-byte SHA-256, sanitized filename, and
effective parent. Import fingerprints the canonical URL, sanitized filename,
and effective parent; the raw URL is not stored.

## Authenticated Upload

`wp-auto/media-upload` input contains required:

| Field | Constraints |
| --- | --- |
| `filename` | string, 1-255 characters, basename only after sanitization |
| `content_base64` | string, 4-13,981,016 characters, strict canonical Base64 |
| `idempotency_key` | string, 16-128 characters |

Optional `parent_id` is an integer, minimum 1. Strict canonical Base64 uses
the standard alphabet/padding, contains no whitespace or data-URI prefix,
decodes strictly, and round-trips byte-for-byte when re-encoded. Decoded bytes
must satisfy the effective limit before Core insertion. Only a WordPress-owned
temporary file is written.

Output is a full media record plus `idempotency_replayed`. Annotations:
`readonly=false`, `destructive=false`, `idempotent=true`.

## Remote URL Import

`wp-auto/media-import-url` input contains required:

| Field | Constraints |
| --- | --- |
| `url` | string, 1-2,048 characters, strict public HTTP(S) URL |
| `filename` | string, 1-255 characters, proposed basename/allowed extension |
| `idempotency_key` | string, 16-128 characters |

Optional `parent_id` is integer, minimum 1. `Content-Disposition` cannot
override the caller's proposed basename; actual bytes/Core checks remain
authoritative.

The complete ADR-005 policy is mandatory: HTTP/HTTPS only; no credentials or
fragment; ports 80/443 only; no IP literals or ambiguous numeric hosts; every
IPv4/IPv6 DNS answer public; DNS/connected destination consistency; every
redirect revalidated; maximum three redirects; no forwarded headers, cookies,
credentials, or caller proxy; connect timeout at most 5 seconds and total at
most 15; stream to a WordPress temp file with an enforced effective byte cap;
do not trust `Content-Length`/`Content-Type`; exactly one successful 2xx final
response; reject partial, expanded, looping, or oversized responses; run final
Core MIME/image/sideload validation.

Core safe HTTP validation remains defense in depth, but filters relaxing its
host/port policy cannot weaken WP-Auto's policy. `download_url()` alone is
insufficient because it does not impose this contract's byte/redirect limits.

Output is a full media record plus `idempotency_replayed`. Annotations:
`readonly=false`, `destructive=false`, `idempotent=true`.

Each import is explicitly caller-triggered. The target sees the site's egress
IP and WordPress HTTP user agent. This trigger/data exposure and absence of
WP-Auto Cloud involvement must be disclosed in `readme.txt` before shipping.

## Media Metadata Update

`wp-auto/media-update` requires `upload_files`, then attachment `edit_post`.
Required input is integer `id` (minimum 1) and `expected_modified_gmt`.
Optional fields are:

| Field | Constraints |
| --- | --- |
| `title` | string, 0-200 characters |
| `alt_text` | string, 0-2,000 characters |
| `caption` | string, 0-50,000 characters |
| `description` | string, 0-100,000 characters |

At least one mutable field is required. Omitted fields are unchanged. Alt text
maps only to `_wp_attachment_image_alt`; no client meta key exists. Parent,
file, URL, slug, MIME, status, author, dates, and arbitrary meta are forbidden.

Missing, wrong-type, unsupported, or unauthorized targets return
`wp_auto_media_not_found`. Immediately before Core update, compare raw
`post_modified_gmt`; mismatch returns `wp_auto_media_conflict` 409. This is
best-effort second-precision concurrency, not CAS. The service re-reads and
verifies file identity, MIME, parent, author, status, GUID, and omitted fields.

Output is the full record. Annotations: `readonly=false`, `destructive=true`,
`idempotent=false`.

## Featured Image Assignment

`wp-auto/media-set-featured` accepts exactly three required integers:

| Field | Constraints |
| --- | --- |
| `target_id` | minimum 1 |
| `media_id` | minimum 1 |
| `expected_featured_media_id` | minimum 0; zero means none |

The target must be a `post` or `page` draft and pass its actual
`cap->edit_posts` baseline plus target `edit_post`. Missing, wrong-type,
non-draft, or unauthorized targets return `wp_auto_content_not_found`. The
media target must be a supported image and pass attachment `read_post`, else
`wp_auto_media_not_found`. Parentage grants no edit permission.

If the current featured ID already equals `media_id`, return success with
`changed=false` and no audit event. Otherwise compare the latest current ID to
`expected_featured_media_id`; mismatch returns
`wp_auto_featured_media_conflict`. Call the Core featured-image API, re-read,
and verify the requested relation and protected target fields.

Output is exactly `target_id`, `target_type` (`post`/`page`), `status`
(`draft`), `featured_media_id`, and `changed`. Removal and non-draft/custom
post-type targets are out of scope. Annotations: `readonly=false`,
`destructive=true`, `idempotent=true`.

## Local media audit

Media mutations use separate private
`_wp_auto_connector_media_mutation_audit` metadata so Phase 1.3 stays sealed.
Retain the most recent 20 events per audited object. Ingestion/update events
belong to the attachment; featured events belong to the target draft.

Every event stores only version, operation, Ability, actor ID, target ID, and
GMT timestamp. Ingestion adds request fingerprint; update adds expected/result
timestamps; featured adds expected/result IDs. Never store bytes, Base64, URL,
filename, text fields, bodies, headers, credentials, raw keys, paths, IP/DNS,
or HTTP data. Audit is attribution, not idempotency authority. Safe replays and
featured no-ops add no event. Uncertain audit finalization fails closed.

## Error contract

| Code | Status | Meaning |
| --- | ---: | --- |
| `wp_auto_invalid_request` | 400 | Invalid field, Base64, URL, file, date, or bound. |
| `wp_auto_pagination_window_exceeded` | 400 | Bounded media page cannot be proved. |
| `wp_auto_remote_media_rejected` | 400 | URL/destination/response violates policy. |
| `wp_auto_media_not_found` | 404 | Media missing, wrong, unsupported, or unauthorized. |
| `wp_auto_content_not_found` | 404 | Parent/target missing, wrong/status-invalid, or unauthorized. |
| `wp_auto_idempotency_conflict` | 409 | Key/payload or completed replay conflict. |
| `wp_auto_idempotency_in_progress` | 409 | Claim is live or unresolved. |
| `wp_auto_media_conflict` | 409 | Attachment timestamp is stale. |
| `wp_auto_featured_media_conflict` | 409 | Expected featured ID is stale. |
| `wp_auto_media_read_failed` | 500 | Exact authorized record cannot be returned. |
| `wp_auto_media_create_failed` | 500 | Proven no-object Core creation failure. |
| `wp_auto_media_update_failed` | 500 | Proven unapplied metadata update failure. |
| `wp_auto_featured_media_update_failed` | 500 | Proven failed featured assignment. |
| `wp_auto_media_state_uncertain` | 500 | File/object/relation may have changed. |

Remote errors never reveal URL, host/IP, redirects, response data, DNS result,
path, or lower-level message. Protocol/transport/Ability/semantic error layers
remain distinct.

## Side-effect boundary

Requested effects are one image creation, one allowlisted presentation update,
or one draft featured relation. Expected Core/plugin lifecycle effects include
upload placement, unique names, attachment posts, metadata/sub-sizes, caches,
hooks, sanitization, timestamps, and plugin-maintained internal media data.

WP-Auto must not intentionally publish/change status; mutate content body,
excerpt, slug, author, dates, taxonomy, SEO, users, settings, plugins, themes,
or unrelated objects; accept arbitrary meta/path/MIME/header/port/proxy/query;
fetch non-images; contact Cloud; collect telemetry; or execute downloaded data.

## Allowlist progression and checkpoints

The sealed twelve-tool order remains. Later checkpoints append Search/Get to
14, Upload to 15, Update to 16, Set Featured to 17, and Import URL to 18.
There is no wildcard discovery, generic media Ability, resource, prompt, or
third-party tool.

1. **1.4.0:** freeze this contract and ADR-005; runtime stays twelve.
2. **1.4.1:** implement Search/Get and exact fourteen-tool allowlist.
3. **1.4.2:** implement Upload/idempotency and exact fifteen tools.
4. **1.4.3:** implement Metadata Update and exact sixteen tools.
5. **1.4.4:** implement draft Featured assignment and exact seventeen tools.
6. **1.4.5:** implement remote import last and exact eighteen tools.
7. **1.4.6:** complete automated, wp-env, Streamable HTTP, Plugin Check where
   available, security, state-integrity, cleanup, and exact-allowlist gates.

Phase 1.4.2 authorizes only Upload production PHP, test support, private media
idempotency/audit/uninstall state, Ability registration, and the exact
fifteen-tool allowlist. It adds no dependency, external request, URL import,
metadata/featured mutation, later roadmap work, or release authorization.
