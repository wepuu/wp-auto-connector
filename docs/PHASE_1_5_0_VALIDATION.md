# Phase 1.5.0 Taxonomy Contract and Security Architecture Validation

Status: **CANDIDATE COMPLETE IN WORKING TREE; FORMALLY FROZEN WHEN LANDED ON `main`**

Validation date: 2026-09-09

Runtime baseline: `main@487cdaf8051d4465480b16bec473c6fc8de77338`

## Scope verdict

Phase 1.5.0 freezes the public taxonomy mutation contract in
`PHASE_1_5_TAXONOMY_CONTRACTS.md` and the safety decisions in
`ADR-006-TAXONOMY-SAFETY.md`. It intentionally adds no production PHP, test
stub, Ability registration, MCP allowlist entry, dependency, persistent state,
uninstall behavior, outbound request, or WordPress mutation.

The runtime remains the exact eighteen-tool Phase 1.4 baseline. Phase 1.5.1
Category Create is the next authorized implementation checkpoint only after
this candidate is reviewed and landed.

## Frozen decisions

The contract fixes:

- three canonical Ability names, Adapter-derived MCP names, delivery order,
  and exact allowlist progression from 18 to 21;
- built-in `category`/`post_tag` only and draft built-in Posts only for
  assignment;
- strict Category/Tag Create inputs and final Core-re-read outputs;
- actual taxonomy `manage_terms` and `assign_terms` capability resolution,
  plus fixed Post-type and target-object authorization;
- persistent site/actor/Ability/key Create idempotency through one future
  exact ADR-003 option family;
- independent existing-term conflicts instead of implicit adoption/replay;
- ID-only, bounded, non-empty exact relationship replacement with a bounded
  51-item current/protected-set overflow gate;
- required expected-term-set best-effort concurrency and idempotent no-op;
- protected Post and non-target taxonomy invariant verification;
- separate bounded, text-free taxonomy audit and required uninstall coverage;
- stable privacy-preserving errors and fail-closed uncertain state; and
- explicit exclusion of term update/delete, Pages/custom content/taxonomies,
  empty-set clearing, publishing, SEO, Cloud, telemetry, and outbound HTTP.

## Architecture and security review

Abilities remain the domain contract and MCP Adapter remains the protocol
layer. Core taxonomy APIs remain authoritative for term creation,
relationships, sanitization, caches, counts, and hooks.

ADR-006 permits no new active-runtime SQL in Phase 1.5.0. The first Create
checkpoint must explicitly extend the closed `AtomicOwnershipStore` name
allowlist and ADR-004 uninstall coverage before exposure. Any future termmeta
verification SQL is limited to prepared, bounded, exact-key, read-only explicit
uninstall after a formal ADR-004 amendment.

Assignment truthfully remains best effort because WordPress does not expose a
portable relationship CAS/version token. Exact desired/expected sets, final
re-read, operation-scoped guards, and invariant verification reduce but do not
eliminate hook/concurrent-writer races.

## Automated evidence

The documentation candidate passed:

- `composer validate --strict`: valid;
- PHPUnit 9.6.36: 393 tests / 2,144 assertions;
- `composer lint`: 102 of 102 PHP files;
- `git diff --check`: clean.

The exact registrar allowlist must remain eighteen ordered Ability constants,
with no taxonomy mutation class or fixture present.

## Live-environment decision

wp-env, Streamable HTTP MCP, and Plugin Check are not required for this
documentation-only freeze because no PHP, package, dependency, readme.txt, or
runtime/distribution behavior changes. Real WordPress and authenticated MCP
validation becomes mandatory at each implementation checkpoint, with the full
matrix required for Phase 1.5.4.

## Changed-file boundary

Expected tracked changes are limited to:

```text
AGENTS.md
README.md
docs/ADR-006-TAXONOMY-SAFETY.md
docs/ARCHITECTURE.md
docs/MCP_TOOL_CATALOG.md
docs/PHASE_1_5_0_VALIDATION.md
docs/PHASE_1_5_TAXONOMY_CONTRACTS.md
docs/PHASE_1_DIRECT_MCP.md
docs/ROADMAP.md
```

No Composer manifest, lock file, production source, test source, readme.txt,
or distribution asset changes.

## Freeze verdict

```text
Contract and ADR = CANDIDATE COMPLETE IN WORKING TREE
Production runtime change = NONE
Direct MCP tools = 18
External request behavior = NONE
Phase 1.5.0 = FORMALLY FROZEN WHEN REVIEWED AND LANDED ON MAIN
Next checkpoint = Phase 1.5.1 Category Create
```
