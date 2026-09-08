# ADR-005: Phase 1.4 Media Safety Model

- Status: Accepted when the Phase 1.4.0 contract freeze lands on `main`
- Date: 2026-09-08
- Decision scope: Phase 1.4 image reads, ingestion, metadata, and featured image assignment

## Context

Phase 1.4 adds file ingestion and, later, a caller-selected outbound URL. That
surface introduces resource exhaustion, malicious/polyglot uploads, path and
metadata overreach, duplicate attachments, partial filesystem/database state,
SSRF, DNS rebinding, redirect bypasses, and authorization leaks. It must remain
compatible with WordPress Core media behavior without becoming generic file or
HTTP access.

The Phase 1 product needs an allowed image and featured-image assignment, not
general-purpose document storage or URL fetching. The narrow contract is the
security boundary.

## Decision

### Image-only, fixed MIME scope

Phase 1.4 supports only JPEG, PNG, GIF, WebP, and AVIF. Core's effective
per-user upload policy and the WP-Auto fixed allowlist must both approve the
file. WordPress real-file inspection and media metadata generation must
succeed. `unfiltered_upload`, plugin filters, client MIME, response headers,
extensions, or names cannot expand the WP-Auto set.

This excludes SVG and other active-content-capable formats, documents,
archives, executables, audio/video, and formats with inconsistent Phase 1
featured-image interoperability. Broader media support requires a new contract
and security review.

### Bounded JSON upload

Direct MCP upload uses one canonical Base64 field. The schema bounds encoded
input and the service strictly decodes/re-encodes it before allocating the
WordPress temporary file. Decoded bytes are capped at the smaller of 10 MiB and
the site's Core upload limit.

The client supplies a proposed basename, never a path. Core sanitization,
unique filename selection, MIME/extension checks, attachment insertion,
metadata generation, and sub-size behavior remain authoritative. The service
does not synthesize `$_FILES` from arbitrary keys or expose Core upload
overrides.

Multipart MCP transport, chunked uploads, archives, client filesystem paths,
and multi-file batches are not part of Phase 1.4.

### Narrow authorization and visibility

Media Search/Get require `upload_files`, then final attachment `read_post`.
Attached media also inherits a conservative parent-read gate so an attachment
cannot reveal a parent that the identity cannot read. Mutation repeats
`upload_files` in the domain service. Parent association and featured-image
assignment additionally require target `edit_post`; metadata update requires
attachment `edit_post`.

Phase 1 featured assignment is draft-only for Post/Page. Wrong-type,
unsupported, missing, non-draft, and unauthorized targets are grouped into
existence-hiding errors before disclosing state.

### Core APIs and invariant verification

WordPress Core APIs own media and relationship writes. Expected implementation
primitives include Core upload/sideload handling, attachment APIs,
`wp_check_filetype_and_ext()`, attachment metadata functions, and
`set_post_thumbnail()`. WP-Auto re-reads each result and verifies contract
invariants after Core and plugin hooks execute.

No direct SQL is authorized for attachments, postmeta, posts, or files. The
only SQL-related use is the existing private `AtomicOwnershipStore` arbitration
primitive extended to the fixed media idempotency option family. Normal media,
audit-meta, and later state transitions use WordPress APIs.

### Persistent, fail-closed ingestion idempotency

Upload/import create operations use persistent idempotency scoped to site,
actor, Ability, and key. Atomic acquisition uses ADR-003's strict
insert-if-absent ownership semantics. Raw keys, file bytes, Base64, URLs,
filenames, and requests are never stored.

An identical completed request replays only after verifying the target still
matches. Different payloads conflict. Live or unresolved claims block another
creation. There is no TTL or lock stealing. A claim can be released only when
the service proves Core created no attachment. Filesystem/database ambiguity
returns `wp_auto_media_state_uncertain` and retains the claim.

This is concurrent-safe persistent idempotency, not transactional exactly-once
creation across arbitrary Core hooks, filesystem failure, or process death.

### Best-effort update concurrency

Attachment presentation updates compare the client's raw
`expected_modified_gmt` immediately before Core update. This is deliberately
the same honest, second-precision, non-CAS model used in Phase 1.3.

Featured-image metadata writes do not provide a reliable post timestamp
version. The contract instead compares `expected_featured_media_id` to the
latest relationship. If the desired image is already set, the call succeeds as
an idempotent no-op. This detects common stale clients without claiming atomic
compare-and-swap against arbitrary concurrent writers.

### Independent SSRF policy

Remote import is an open-world capability and is implemented last. The
downloader must apply a WP-Auto policy before and during the request; Core safe
HTTP validation is an additional layer, not the sole policy.

The independent policy requires:

1. parse a 1-2,048-character URL and accept only HTTP/HTTPS;
2. reject fragments, credentials, malformed/ambiguous hosts, IP literals, and
   ports other than 80/443;
3. normalize the DNS name and resolve all A/AAAA answers;
4. reject any unspecified, loopback, private, shared, link-local,
   documentation, benchmarking, multicast, reserved, or metadata destination;
5. prevent a connection from drifting to an unchecked DNS address;
6. apply the same checks to every redirect and follow at most three;
7. send no caller headers, cookies, credentials, proxy selection, or auth;
8. cap connection time at 5 seconds and total time at 15 seconds;
9. stream into a WordPress-created temporary file while enforcing the smaller
   of 10 MiB and the site upload limit;
10. treat `Content-Length`/`Content-Type` as untrusted hints, accept one final
    2xx response, and reject truncation, excess, redirect loops, and expansion;
11. pass the finished file through the same fixed MIME and Core sideload
    validation used for local upload; and
12. delete every owned temporary artifact on all deterministic exits.

Core filters such as external-host or safe-port overrides must not relax this
policy. The implementation must be testable through injected/narrow resolver
and HTTP seams while production requests still use the WordPress HTTP API. It
must not implement a raw socket client or generic proxy.

`download_url()` is not selected as the whole downloader: it uses Core safe
HTTP and streaming but does not itself express this ADR's fixed byte cap,
redirect count, DNS-consistency, and independent host policy. A small service
may reuse Core HTTP primitives with fixed arguments.

### Temporary-file and partial-state handling

The service owns only temporary files it created. Cleanup uses WordPress
filesystem helpers in `finally` and never accepts a client path. After Core
moves a file, WP-Auto does not blindly delete the resulting upload on an
uncertain path. Deterministic pre-insert failures clean temporary data and may
release the exact initial claim; uncertain post-insert failures preserve state
for recovery.

The design does not promise transactional rollback across filesystem,
database, image processing, hooks, audit, and idempotency state.

### Privacy, audit, and disclosure

Media audit is bounded to 20 events per object under a new private meta key. It
contains attribution and fixed fingerprints/tokens only, never content, names,
URLs, network details, paths, or credentials. It is not idempotency authority.

Remote import is not WP-Auto Cloud, but it causes the WordPress site to contact
the caller-selected host. Before the tool ships, `readme.txt` must disclose the
trigger, purpose, target-controlled destination, and that the destination can
observe the site's egress IP and WordPress HTTP user agent. No background or
administrator-unapproved import is permitted.

## Consequences

### Positive

- The first media release satisfies the featured-image MVP without exposing
  arbitrary media/file/network operations.
- Core upload policy, hooks, metadata, sub-sizes, and plugin interoperability
  remain active.
- Persistent idempotency prevents live duplicate ingestion owners and fails
  closed after ambiguous writes.
- Search pagination and errors do not leak inaccessible attachment/parent
  existence.
- Remote import has independently testable SSRF and resource boundaries.

### Costs and limitations

- Base64 adds approximately one-third transport overhead and limits Phase 1
  uploads to 10 MiB decoded.
- Only five image MIME types are supported.
- Parent visibility rules can hide an otherwise public attachment.
- Interrupted ingestion can leave an operator-visible unresolved claim or
  Core-created artifact that must not be retried automatically.
- Timestamp/current-ID checks are not atomic CAS.
- Sites whose DNS/HTTP behavior cannot prove a public stable destination
  cannot use remote import for that URL.

## Rejected alternatives

- generic attachment CRUD, filesystem access, REST proxy, or URL fetcher;
- all Core MIME types, SVG, archives, or `unfiltered_upload` expansion;
- trusting MIME headers/extensions or validating only after attachment insert;
- client-supplied temp/destination paths or upload overrides;
- multipart/chunk/batch ingestion in Phase 1;
- transient, process-memory, audit-only, or time-expiring idempotency;
- direct SQL media writes or custom cross-system transactions;
- `download_url()`/`wp_safe_remote_get()` as the only SSRF policy;
- allowing Core filters to authorize private hosts or extra ports for WP-Auto;
- HEAD-only size/type validation;
- automatic deletion of possibly successful Core media after uncertain state;
- featured-image changes on published/non-draft/custom content;
- recording URLs, filenames, bytes, content, headers, IPs, or credentials in
  audit history.

## Validation requirements

Before Phase 1.4 is sealed, tests must prove strict schemas and allowlists;
capability and existence hiding; bounded authorized pagination; MIME and real
file rejection; Base64 and decoded limits; temp cleanup and partial-state
behavior; atomic duplicate-ingestion arbitration; update/featured preconditions;
SSRF coverage for IPv4/IPv6, numeric hosts, redirect pivots, DNS changes,
metadata destinations, ports, timeouts, oversize and type mismatch; no
unintended content/taxonomy/SEO/user/settings/plugin/theme/Cloud/telemetry
effects; and exact real MCP behavior in wp-env.

TAC is not a required external gate. Removing that unavailable gate does not
waive repository tests, exact-main security review, Plugin Check when an
audited environment is available, or documented residual-risk review.
