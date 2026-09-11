# Phase 1.6 SEO Abstraction Contracts

Status: **Phase 1.6.0 contract frozen; Phase 1.6.1 landed; Phase 1.6.2 implementation in progress**

This document is the authoritative public contract for the Phase 1.6 SEO
surface. Phase 1.6.0 was documentation-only and kept the sealed twenty-one-
tool runtime. Phase 1.6.1 appends `seo-get`, producing exactly twenty-two
tools; the later implementation checkpoint may append `seo-update` and must not change the contracts after a
checkpoint is exposed.

## Fixed scope and delivery order

| Checkpoint | WordPress Ability | MCP tool | Runtime count | Operation |
| --- | --- | --- | ---: | --- |
| 1.6.0 | none | none | 21 | Contract and provider-safety freeze |
| 1.6.1 | `wp-auto/seo-get` | `wp-auto-seo-get` | 22 | Read explicit object SEO overrides |
| 1.6.2 | `wp-auto/seo-update` | `wp-auto-seo-update` | 23 | Update explicit draft SEO overrides |
| 1.6.3 | none | none | 23 | Integration, uninstall, and security seal |

Rank Math is the first implementation adapter. Yoast and AIOSEO remain later
adapters and cannot change the provider-neutral public shape.

## Shared contract rules

- Every input and output is a strict object with `additionalProperties: false`.
- Only built-in `post` and `page` objects are supported. Attachments,
  revisions, custom post types, terms, archives, users, and site-wide SEO
  settings are outside this phase.
- Public fields describe explicit per-object overrides. Empty values mean that
  the provider default or generated value is inherited; WP-Auto does not
  evaluate provider templates or return rendered front-end output.
- Values are bounded, UTF-8, free of NUL/control characters and HTML markup.
  Template tokens such as `%title%` remain valid opaque text.
- Provider names, provider meta keys, provider REST routes, and provider MCP
  Ability names are not public schema, output, or error fields.
- WordPress authentication remains separate from per-Ability authorization.
  Errors hide missing, wrong-type, and unauthorized targets consistently.

## `wp-auto/seo-get`

### Input

```json
{
  "id": 123
}
```

`id` is a required positive integer and no other property is accepted.

### Authorization

The service resolves the target and requires the authenticated user to pass
the provider's effective SEO read capability and `current_user_can( 'read_post',
$id )`. Password-protected objects additionally require `edit_post`, matching
the existing Content Get privacy rule. An inaccessible object is reported as
`wp_auto_seo_not_found` with semantic status 404.

### Output

| Field | Type | Contract |
| --- | --- | --- |
| `id` | integer | final target ID |
| `type` | enum | `post` or `page` |
| `status` | enum | `publish`, `draft`, `pending`, `private`, or `future` |
| `title` | string | explicit SEO title, empty when inherited, max 500 characters |
| `description` | string | explicit meta description, empty when inherited, max 2,000 characters |
| `canonical_url` | string | explicit canonical URL, empty when inherited, max 2,048 characters |
| `focus_keywords` | array | 0–5 unique strings, each max 200 characters |
| `robots` | object | required `index` and `follow` values |
| `state_token` | string | exactly 64 lowercase hexadecimal characters |

`robots.index` is one of `default`, `index`, or `noindex`; `robots.follow` is
one of `default`, `follow`, or `nofollow`. The adapter preserves provider
robots directives outside this public pair.

Annotations: `readonly=true`, `destructive=false`, `idempotent=true`.

## `wp-auto/seo-update`

### Input

Required properties are `id` (positive integer) and
`expected_state_token` (64 lowercase hexadecimal characters). The optional
allowlist is `title`, `description`, `canonical_url`, `focus_keywords`, and
`robots`; at least one optional property must be present.

- `title`: string, max 500 characters; empty clears the explicit value.
- `description`: string, max 2,000 characters; empty clears the explicit value.
- `canonical_url`: string, max 2,048 characters; empty clears the explicit
  value. A non-empty value must be an absolute HTTP/HTTPS URL with a host,
  without credentials or a fragment. No network request or DNS lookup occurs.
- `focus_keywords`: array of 0–5 unique strings, each max 200 characters;
  an empty array clears the explicit list. Commas are rejected inside an item
  so provider serialization remains unambiguous.
- `robots`: object requiring both `index` and `follow`, with the same enums as
  Get. `default` clears the corresponding explicit directive.

Omitted fields remain unchanged. Only an authorized Post/Page in `draft`
status may be updated. The service repeats the fixed post-type edit baseline,
the provider's effective SEO write capability, and final `edit_post` immediately
before writing.

### Concurrency and result

`state_token` is a best-effort optimistic precondition, not a database CAS
token. The service re-reads the current state immediately before the write,
merges the supplied patch, and returns a successful no-op when the merged
state already matches current state, even if the supplied token is stale.

The output is the complete Get record plus:

| Field | Type | Contract |
| --- | --- | --- |
| `changed_fields` | array | canonical names of fields actually changed |
| `no_op` | boolean | true only when no field changed |

Annotations: `readonly=false`, `destructive=true`, `idempotent=true`.

## Provider, persistence, and failure policy

- Tools remain stably registered even when no provider is active. No compatible
  provider returns `wp_auto_seo_provider_unavailable` (409); multiple active
  supported providers return `wp_auto_seo_provider_conflict` (409).
- The internal provider registry exposes one adapter interface for availability,
  authorization, bounded read, and allowlisted patch/write operations. The
  first adapter uses WordPress Metadata API calls against fixed provider keys;
  it never calls a provider REST endpoint, MCP tool, Content AI, or external
  service.
- The adapter snapshots all public fields plus protected provider state before
  writing, re-reads after every multi-key write, and verifies omitted fields and
  protected Post/Page invariants. It never changes content, status, author,
  taxonomy, featured media, arbitrary meta, or `modified_gmt`.
- Partial application, hook interference, provider change, malformed existing
  data, or an unverifiable final read returns `wp_auto_seo_state_uncertain`;
  clients must call Get before retrying. WP-Auto never performs a blind rollback.
- Phase 1.6.2 adds one private postmeta audit family bounded to the most recent
  20 events per object. Entries contain only version, operation, Ability,
  actor, object ID, timestamp, expected/result state-token digests, and changed
  field names. They contain no SEO values, URL, keyword, request body,
  credential, or raw key and are never idempotency authority.
- Explicit uninstall removes the exact SEO audit family and verifies cleanup;
  no active-runtime SQL authority is added.

## Stable semantic errors

`wp_auto_invalid_request`, `wp_auto_seo_not_found`,
`wp_auto_seo_status_conflict`, `wp_auto_seo_conflict`,
`wp_auto_seo_provider_unavailable`, `wp_auto_seo_provider_conflict`,
`wp_auto_seo_state_unsupported`, `wp_auto_seo_write_failed`, and
`wp_auto_seo_state_uncertain` are the only new Phase 1.6 semantic errors.

## Explicit exclusions

Phase 1.6 does not expose publishing, deletion, status changes, arbitrary
metadata, taxonomy SEO, Schema, OpenGraph/Twitter fields, SEO scores,
redirects, robots.txt, site-wide settings, provider MCP tools, Cloud,
telemetry, or outbound requests.
