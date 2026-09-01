# Phase 1.3.0 Mutation Contract Freeze Validation

Date: 2026-08-31

Remediation review: 2026-09-01

Baseline: `main@955377e2168e14a818c9d33d3daf242ac9d953ee`

Working branch: `chore/phase-1-3-0-mutation-contract-freeze`

Verdict: **PASS — Phase 1.3.0 Mutation Contract Freeze complete**

## Scope

Phase 1.3.0 is a documentation and architecture-decision checkpoint. It freezes four future Ability/MCP contracts without registering an Ability, changing the dedicated MCP server, or adding mutation runtime code.

| Ability | MCP tool | Planned phase | Current exposure |
| --- | --- | --- | --- |
| `wp-auto/post-create-draft` | `wp-auto-post-create-draft` | Phase 1.3.1 | none |
| `wp-auto/page-create-draft` | `wp-auto-page-create-draft` | Phase 1.3.1 | none |
| `wp-auto/post-update` | `wp-auto-post-update` | Phase 1.3.2 | none |
| `wp-auto/page-update` | `wp-auto-page-update` | Phase 1.3.2 | none |

The runtime allowlist remains the exact eight read-only Phase 1.2 abilities. No production PHP, tests, Composer metadata, dependency lock, MCP Adapter integration, plugin version, or CI workflow changed.

## Pre-freeze remediation review

The initial Phase 1.3.0 contract draft was reviewed before formal freeze. The review clarified that `modified_gmt` provides a best-effort, non-atomic optimistic concurrency precondition rather than a full compare-and-swap guarantee. It records both the timestamp's second-level granularity and the race in which an external write can occur after WP-Auto's final comparison but before WordPress completes the update.

The review also separated atomic idempotency claim acquisition from an unqualified exactly-once creation guarantee, defined deterministic non-duplicating behavior for completed, conflicting, live in-progress, stale, failed, and uncertain claims, and required persistent local authority across retries and PHP restarts. It separated the per-object 20-event audit retention policy from authoritative idempotency state and documented the distinction between requested mutation, expected WordPress Core/plugin lifecycle effects, and forbidden WP-Auto side effects.

Final review approved the remediated contract and ADR without additional semantic changes. Phase 1.3.0 is frozen; Phase 1.3.1 is the next implementation checkpoint.

## Entry gate

| Check | Result |
| --- | --- |
| Working tree clean before task | PASS |
| Local `main` matched `origin/main` | PASS — `955377e2168e14a818c9d33d3daf242ac9d953ee` |
| Phase 1.2 validation PR present in baseline | PASS |
| GitHub Actions for merged baseline | PASS — PHP Quality run `33374965716` |
| Locked official MCP Adapter | PASS — `wordpress/mcp-adapter` 0.6.1 |

## WordPress and Adapter feasibility review

The review used the WordPress 6.9/Core API surface and the locally locked MCP Adapter 0.6.1 source. No temporary production Ability was registered.

| Contract point | Evidence reviewed | Result |
| --- | --- | --- |
| Strict object and string constraints | WordPress Ability validation delegates to the REST schema validator; object `additionalProperties`, string `minLength`, `maxLength`, and `pattern` are supported | PASS |
| Adapter schema preservation | MCP Adapter 0.6.1 preserves the supplied object schema for Ability tools | PASS |
| Annotation mapping | Ability `readonly`, `destructive`, and `idempotent` map to MCP `readOnlyHint`, `destructiveHint`, and `idempotentHint` | PASS |
| Create capability | Fixed post type objects expose `cap->create_posts`; built-in Post/Page mappings are resolved through the capability object instead of hard-coded in the contract | PASS |
| Update authorization | Core `edit_post` meta-capability mapping supports final object-level authorization and additional Core restrictions | PASS |
| Draft creation behavior | `wp_insert_post()` supports fixed type/status/author; normal Core default-category behavior can occur for Posts | PASS |
| Draft update behavior | `wp_update_post()` uses the normal Core write path and can generate revisions | PASS |
| Modification token | `post_modified_gmt` is a second-precision best-effort precondition and cannot provide atomic database compare-and-swap across the final check/write race | PASS — limitation explicit |
| Atomic Create claim | `add_option()` provides a uniqueness-based claim and accepts non-autoloaded storage; a private hashed option name avoids storing the raw key | PASS |
| Stored content semantics | Core hooks, KSES, capability-dependent HTML handling, slashing, sanitization, and slug canonicalization remain authoritative | PASS |
| Core mutation path | No direct SQL conditional update, row lock, or custom transaction is required solely for CAS; Core hooks, revisions, caches, capabilities, and interoperability remain intact | PASS |

## Contract completeness audit

| Area | Frozen decision | Result |
| --- | --- | --- |
| Names and sequencing | Four canonical Ability/tool pairs; Create in 1.3.1, Update in 1.3.2 | PASS |
| Strict schemas | Required/optional fields, types, bounds, patterns, field-combination validation, exact outputs | PASS |
| Draft boundary | Fixed create type/status/author; Update restricted to authorized existing drafts | PASS |
| Authorization | Transport authentication/read plus actual post type capabilities and final `edit_post` | PASS |
| Existence privacy | missing, wrong type, and unauthorized Update targets share 404 not-found semantics | PASS |
| Create retry safety | concurrent-safe persistent idempotency; atomic claim; completed replay; conflict/in-progress/recoverable/uncertain outcomes; no unconditional exactly-once claim | PASS |
| Update stale detection | best-effort final timestamp check; mismatch means no WP-Auto update and 409; second-level and check-to-write limits documented | PASS |
| Invariant defense | operation-scoped final data guard, `try/finally` cleanup, post-write re-read | PASS |
| Audit | private local metadata, fixed non-content fields, newest 20 events per object, no replay event, never authoritative for idempotency | PASS |
| Partial failure | separate confirmed failure and uncertain-write errors; no false rollback claim | PASS |
| Side-effect boundary | requested target mutation, expected Core/plugin lifecycle effects, and forbidden WP-Auto effects are distinct | PASS |
| Safety exclusions | no publish/delete/status transition/arbitrary fields/meta/taxonomy/media/Cloud/telemetry | PASS |
| Allowlist | current runtime remains 8; future explicit progression is 10 then 12 | PASS |

## Phase 1.2 regression and runtime-delta audit

- `src/Mcp/McpServerRegistrar.php` remains unchanged and explicitly lists the same eight read-only abilities.
- No `src/`, test bootstrap, plugin bootstrap, Ability registration, or production loader file changed.
- No placeholder mutation class or skipped mutation test was added.
- `docs/PHASE_1_2_READ_TOOLS.md` remains sealed and unchanged.
- No Composer dependency, MCP Adapter version, CI workflow, plugin version, or Stable tag changed.
- No external request, Cloud MCP, telemetry, tracking, publishing, deletion, generic REST, SQL, filesystem, shell, or WP-CLI surface was introduced.

## Phase 1.3.4 validation implication

Final mutation validation must not require a byte-for-byte unchanged database. It must prove that only the intended target mutation occurred, plus documented WordPress Core/plugin lifecycle effects such as timestamps, revisions, cache invalidation, hooks, sanitization, internal metadata, canonical slugs, and the Core default category when applicable.

It must separately confirm that WP-Auto caused no published content change or status promotion, no unrelated content-object mutation, no taxonomy relationship change beyond documented Core behavior, no media or featured-image change, no SEO change, no arbitrary client-controlled metadata, and no user/settings/plugin/theme/Cloud/telemetry mutation.

## Quality gates

| Check | Result |
| --- | --- |
| `composer validate --strict` | PASS — `composer.json` is valid |
| `composer test` | PASS — 132 tests, 769 assertions |
| `composer lint` | PASS — 34/34 files |
| `composer audit --locked` | PASS — no security vulnerability advisories found |
| `git diff --check` | PASS |
| Documentation-only final diff | PASS — eight documentation/status files; no runtime delta |

Plugin Check was not required for this documentation-only checkpoint. The Phase 1.2 live runtime seal remains authoritative; Phase 1.3 live mutation validation begins only after the relevant abilities are implemented.

## Completion boundary

This checkpoint freezes contracts and ADR decisions only. It does not make mutation tools available and does not claim that Phase 1.3 is complete. The only approved next task is:

```text
Phase 1.3.1 — Post/Page Create Draft
```
