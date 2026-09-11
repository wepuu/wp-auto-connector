# Phase 1.6.2 Draft SEO Update Validation

Status: **implementation checkpoint complete; Phase 1.6.3 integration/security seal pending**

This checkpoint adds only the provider-neutral `wp-auto/seo-update` Ability and
its `wp-auto-seo-update` MCP tool. The candidate runtime is exactly twenty-three
ordered tools, with SEO Update last. SEO Update is limited to authorized built-in
Post/Page drafts and the admitted Rank Math 1.0.278 adapter.

## Baseline and scope

- Development baseline: `main@189090f0b5cb4d04893806aa7da576be32dad133`.
- Contract and provider decisions: `PHASE_1_6_SEO_CONTRACTS.md` and
  `ADR-007-SEO-PROVIDER-ABSTRACTION.md`.
- No Yoast/AIOSEO adapter, SEO audit values, published-content mutation,
  external request, REST/MCP provider call, or Phase 1.6.3 behavior was added.

## Automated evidence

| Check | Result |
| --- | --- |
| PHPUnit full suite | **PASS — 485 tests / 3,168 assertions** |
| SEO-focused PHPUnit suite | **PASS — 40 tests / 158 assertions** |
| SEO source WPCS | **PASS** |
| `composer validate --strict`, full `composer lint`, audit, production dry-run | Pending final release gate |
| WordPress 6.9/7.1 and Plugin Check 2.1.0 runtime matrix | Deferred to Phase 1.6.3 seal |

The automated update coverage includes strict input and URL validation, empty
value clearing, partial updates, robots non-target preservation, stale-token
conflicts, stale-token no-ops, draft/status and capability boundaries,
provider-unavailable behavior, post-write re-read, protected Core state checks,
write-failure classification, and value-free bounded SEO audit attribution.

## Contract evidence

- `wp-auto/seo-update` is registered after `wp-auto/seo-get` and is the final
  entry in the dedicated allowlist.
- Input and output schemas are strict objects with no arbitrary properties.
- Successful output is the complete SEO record plus `changed_fields` and
  `no_op`; provider names and metadata keys never cross the public boundary.
- The state token remains a best-effort SHA-256 precondition, not a database
  compare-and-swap. `wp_auto_seo_state_uncertain` requires a fresh Get.
- Explicit uninstall now removes and verifies the exact private SEO audit
  postmeta family.

Phase 1.6.3 must repeat the real WordPress 6.9/7.1 single-site and Multisite
MCP matrix, Plugin Check 2.1.0 static/runtime checks, no-outbound-request
review, and exact-range security scan before Phase 1.6 is formally sealed.
