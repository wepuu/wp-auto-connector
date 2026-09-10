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
No wp-env, Plugin Check, or Rank Math package is required for this docs-only
checkpoint; those gates begin with Phase 1.6.1.

## Next checkpoint

Phase 1.6.1: obtain and verify a user-supplied official Rank Math package,
implement the internal adapter and `wp-auto/seo-get`, then validate the exact
22-tool authenticated runtime before starting Update.
