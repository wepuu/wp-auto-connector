# Phase 1.4.0 Media Contract and Security Architecture Validation

Status: **COMPLETE ON THIS BRANCH; FORMALLY FROZEN WHEN LANDED ON `main`**

Validation date: 2026-09-08

Runtime baseline: `main@20684886a0e9faaa564f8c1b733830fed2b8e0a4`

Validation branch: `feat/phase-1-4-media-contracts`

## Scope verdict

Phase 1.4.0 freezes the public media contract in
`PHASE_1_4_MEDIA_CONTRACTS.md` and the safety decisions in
`ADR-005-MEDIA-SAFETY.md`. It intentionally adds no production PHP, test stub,
Ability registration, MCP allowlist entry, dependency, external request,
filesystem mutation path, or WordPress media mutation.

The existing runtime remains the exact twelve-tool Phase 1.3 baseline. Phase
1.4.1 Media Search/Get is the next authorized implementation checkpoint.

## Frozen decisions

The review fixed:

- six canonical Ability names, MCP names, delivery order, and exact allowlist
  progression from 12 to 18;
- strict object schemas and exact media/search/featured outputs;
- image-only JPEG/PNG/GIF/WebP/AVIF scope and the smaller of 10 MiB/site upload
  limits;
- `upload_files`, attachment/target meta-capability, parent visibility, draft,
  and existence-hiding boundaries;
- bounded post-authorization pagination matching Phase 1.2 limits;
- one-file canonical Base64 upload through WordPress-owned temporary files;
- persistent atomic ingestion idempotency and fail-closed uncertain state;
- allowlisted attachment presentation fields and best-effort timestamp
  concurrency;
- draft-only Post/Page featured assignment using an expected-current-ID
  precondition and idempotent no-op behavior;
- independent SSRF/DNS/redirect/port/time/byte policy layered over Core safe
  HTTP and media validation;
- bounded URL/content/path-free media attribution; and
- required WordPress.org disclosure before remote import ships.

## Architecture and security review

The contract preserves Abilities as the domain layer and MCP Adapter as the
protocol layer. Core APIs remain authoritative for upload/sideload,
attachments, metadata, image sub-sizes, and featured relationships.

ADR-005 extends the already approved `AtomicOwnershipStore` only to a fixed
private media-idempotency option family. It grants no direct SQL against
posts, attachments, postmeta, media files, taxonomy, users, settings, or
client-selected values.

Remote import is deliberately last and fails closed when a public,
stable/checked destination cannot be proven. Core safe URL validation cannot
be weakened by site filters for WP-Auto. The future downloader must process
redirects one hop at a time, enforce connection-address consistency, stream to
a bounded WordPress temporary file, and run the same final image validation as
local upload.

TAC is not included as an external audit dependency. Repository tests,
implementation review, exact-main security review, residual-risk reporting,
and Plugin Check in an audited available environment remain required.

## Automated evidence

The documentation candidate passed:

- `composer validate --strict`: valid;
- PHPUnit 9.6.36: 288 tests / 1,506 assertions;
- `composer lint`: 57 of 57 PHP files;
- `git diff --check`: clean.

The exact registrar allowlist remains covered by
`McpServerRegistrarTest::test_registers_a_custom_http_server_with_exact_allowlist()`,
which asserts twelve ordered Ability constants. No new runtime class or test
fixture exists in this checkpoint.

## Live-environment decision

wp-env and Streamable HTTP MCP were not started for this documentation-only
freeze. Phase 1.3.4 already validated the unchanged runtime baseline, while
Phase 1.4.0 contains no executable behavior to exercise. Live WordPress and
HTTP validation becomes mandatory as each Phase 1.4 runtime checkpoint lands,
with the full media/SSRF/state-integrity matrix required in Phase 1.4.6.

Plugin Check was not rerun because there is no PHP, packaging, readme.txt,
dependency-manifest, or distribution change. Existing release-readiness
findings remain unchanged and are not represented as resolved.

## Changed-file boundary

Expected tracked changes are limited to:

```text
AGENTS.md
README.md
docs/ADR-005-MEDIA-SAFETY.md
docs/ARCHITECTURE.md
docs/MCP_TOOL_CATALOG.md
docs/PHASE_1_DIRECT_MCP.md
docs/PHASE_1_4_0_VALIDATION.md
docs/PHASE_1_4_MEDIA_CONTRACTS.md
docs/ROADMAP.md
docs/WORDPRESS_ORG_COMPLIANCE.md
```

The pre-existing untracked `w2.zip` is user-owned and excluded. Composer's
installed `vendor/` directory is ignored build/test state and no Composer
manifest or lock file changed.

## Freeze verdict

```text
Contract and ADR = COMPLETE ON BRANCH
Automated baseline = PASS
Production runtime change = NONE
Direct MCP tools = 12
External request behavior = NONE
Phase 1.4.0 = FORMALLY FROZEN WHEN LANDED ON MAIN
Next checkpoint = Phase 1.4.1 Media Search/Get
```
