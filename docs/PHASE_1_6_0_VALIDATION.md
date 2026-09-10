# Phase 1.6.0 SEO Contract and Provider-Safety Validation

Status: **Complete; documentation-only checkpoint; formally frozen on branch pending review**

Validation date: 2026-09-10

Runtime baseline: `main@daecd1a2a8aa40d5fd6f7ab2b378837a86afebf1`

## Scope verdict

Phase 1.6.0 freezes the provider-neutral SEO contract and ADR-007. It adds no
production PHP, tests, Composer dependency, Ability registration, MCP
allowlist entry, persistent state, uninstall behavior, outbound request, or
WordPress mutation. The Direct MCP runtime remains exactly twenty-one tools.

## Frozen decisions

- `wp-auto/seo-get` is the next read-only tool and `wp-auto/seo-update` is the
  following draft-only mutation; runtime counts are 22 and 23 respectively.
- The public shape contains only explicit title, description, canonical URL,
  focus keyword list, and index/follow directives.
- Get supports authorized built-in Posts/Pages; Update supports only authorized
  drafts and preserves the existing capability/privacy model.
- Rank Math is the first independent internal adapter. Public contracts do not
  name Rank Math, Yoast, AIOSEO, provider meta keys, or provider MCP abilities.
- The fixed postmeta allowlist, provider conflict handling, SHA-256 state token,
  best-effort concurrency, re-read/invariant verification, fail-closed uncertain
  state, bounded private audit, and uninstall boundary are frozen.
- No publishing, deletion, arbitrary metadata, provider REST/MCP calls, SEO
  scores, site settings, Cloud, telemetry, or outbound requests are authorized.

## Review evidence

The implementation branch must pass the documentation gate before landing:

```text
composer validate --strict
composer test                 # at least 441 tests / 2,465 assertions
composer lint
composer audit --locked
git diff --check
```

The exact 21-entry registrar allowlist, production source tree, test tree,
Composer manifests, `readme.txt`, and distribution assets remain unchanged.
No wp-env or Plugin Check run is required for this docs-only checkpoint.

## Provider artifact admission

The user supplied the following official plugin ZIPs from `D:\Codex`. They
were inspected without executing their PHP and are not copied into the
repository or distribution:

| Provider package | Verified plugin version | ZIP SHA-256 |
| --- | --- | --- |
| `seo-by-rank-math.1.0.278.zip` | Rank Math SEO 1.0.278 | `06b7b30b0d7f350fae929519c9c5265488c7b1f24ebe5650988de90975a1e73f` |
| `wordpress-seo.28.4.zip` | Yoast SEO 28.4 | `93eba5afc65149967a4bb4906bc8fdebf92f4a65ee02bec97f5c01a7e14e7028` |
| `all-in-one-seo-pack.5.0.1.1.zip` | All in One SEO 5.0.1.1 | `22599202e4f9ceafa71084d4b1cead8fa0a1938abfe3706b54ecad8d9a0227c2` |

The Rank Math package contains and initializes `wordpress/mcp-adapter` 0.5.0
from its normal plugin bootstrap. WePuu pins and verifies 0.6.1 and accepts
only `>=0.6.1 <0.7.0`. If Rank Math loads first, the global Adapter class and
its initialization hook can precede the WePuu registrar. Widening the accepted
range, replaying the public initialization hook, or relying on plugin order is
not approved. Yoast 28.4 and AIOSEO 5.0.1.1 expose provider abilities but do
not contain the same bundled Adapter package; their abilities must nevertheless
remain outside the dedicated WePuu allowlist.

## Satisfied prerequisite

Phase 1.6.0.1 implemented and locally validated the MCP Adapter coexistence
solution in `ADR-008-MCP-ADAPTER-COEXISTENCE.md` while retaining the exact
21-tool runtime. Its evidence is recorded in
`PHASE_1_6_0_1_VALIDATION.md`; Phase 1.6.1 is the current implementation
checkpoint and Phase 1.6.2 remains unauthorized.
