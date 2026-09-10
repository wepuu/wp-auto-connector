# ADR-006: Taxonomy Mutation Safety Boundary

Status: **Accepted, implemented, validated, and sealed for Phase 1.5.1–1.5.4**

Date: 2026-09-10

## Context

Phase 1.2 already exposes bounded read-only lists for the built-in Category and
Tag taxonomies. Phase 1.5 must add useful term creation and assignment without
turning the connector into a generic taxonomy/metadata API or granting publish,
delete, custom-content, or administrative authority.

Taxonomy writes have several non-obvious risks:

- Core taxonomy capabilities are mapped per taxonomy and can be filtered;
- term-name/slug uniqueness is not a request-idempotency mechanism;
- term creation crosses the terms and term-taxonomy data model and can become
  uncertain around hooks or finalization failures;
- relationship replacement can remove existing terms and is not an atomic
  compare-and-swap operation;
- object editing permission and taxonomy assignment permission are distinct;
- hooks can mutate the Post or unrelated relationships during an apparently
  narrow operation; and
- new persistent idempotency/audit state must be private and removable on
  explicit uninstall.

## Decision

The Phase 1.5.1 Category Create, Phase 1.5.2 Tag Create, and Phase 1.5.3
draft-Post assignment checkpoints implement the three contracts in this ADR;
the Phase 1.5.4 integration and security seal is complete.
They extend the exact AtomicOwnership option allowlist and ADR-004 uninstall
verification to shared taxonomy idempotency, termmeta Create audit state, and
postmeta assignment audit state.

Adopt three narrow Abilities: fixed Category Create, fixed Tag Create, and
single-taxonomy exact assignment to built-in Post drafts. Their complete
public contract is `docs/PHASE_1_5_TAXONOMY_CONTRACTS.md`.

### Fixed Core domains

Create resolves exactly `category` or `post_tag`; Assignment accepts only that
two-value enum. Assignment targets exactly the Core `post` type in `draft`
status. The implementation must confirm that the selected taxonomy is
registered for `post` before writing.

No custom taxonomy/post type, Page, attachment, nav menu, link category, post
format, term update/delete, implicit term creation, or arbitrary Core argument
is exposed.

### Capability composition

Create requires the fixed taxonomy object's actual `cap->manage_terms` at
Ability entry and again in the service immediately before Core mutation.

Assignment independently requires:

1. the fixed taxonomy object's actual `cap->assign_terms`;
2. the built-in Post type object's actual `cap->edit_posts`; and
3. `edit_post` for the final target object.

All capability properties must be valid non-empty strings. Failure denies the
operation. Authentication never substitutes for these checks. Parent and term
existence checks occur only after the relevant capability boundary.

### Strict ID-based exact assignment

Assignment accepts IDs only, never names or slugs, and never creates missing
terms. Requested sets are non-empty, unique, bounded to 50, and canonicalized
for comparison/output. The complete selected taxonomy set is replaced with
`append=false`; no client-controlled append/remove mode exists.

Current selected and protected non-target sets are each read with a 51-item
ceiling. More than 50 terms rejects the operation without mutation; an exact
and invariant-checked result must never depend on an unbounded relationship
read. Desired IDs must currently exist in the selected taxonomy. Expected IDs
are compared as an opaque prior-state set so deletion races become stale-state
conflicts rather than term-existence probes.

This is intentionally marked destructive because relationships omitted from
the desired set can be removed. It does not delete term objects.

### Best-effort concurrency

`expected_term_ids` is required. An already-satisfied desired set is an
idempotent no-op. Otherwise the latest canonical set must equal the expected
set immediately before the Core write.

This precondition reduces stale overwrites but is not CAS: WordPress term
relationships and hooks do not provide a portable transaction/version token.
The implementation and client documentation must state that concurrent writes
can still race after the comparison.

### Persistent create ownership

Term Create uses the existing ADR-003 insert-if-absent ownership design under
one new exact option family:

```text
wp_auto_connector_taxonomy_idempotency_<64 lowercase hex characters>
```

The `AtomicOwnershipStore` must explicitly validate this family. It remains a
closed internal primitive, not a generic option/SQL service. Claim acquisition,
cache coherence, exact conditional release, no TTL/takeover, and fail-closed
uncertainty rules remain unchanged.

Core duplicate-term behavior is classified as a semantic conflict, not a safe
replay. A replay is valid only through the same completed idempotency record
after current authorization and target verification.

### Core APIs and invariant verification

Create uses `wp_insert_term()` with only the fixed taxonomy and allowlisted
arguments. Assignment uses the fixed Core relationship API with
`append=false`. Direct SQL against terms, term taxonomy, relationships,
termmeta, posts, or postmeta is prohibited in active runtime.

Services re-read final state. Assignment snapshots protected Post fields and
the other approved taxonomy, uses an operation-scoped guard with `try/finally`,
and verifies the exact selected set plus protected invariants. A possible
partial write or hook-induced divergence returns a state-uncertain error; it is
not blindly rolled back.

### Audit and uninstall

Use one exact private taxonomy audit meta key in the correct Core metadata
domain: termmeta for creation and postmeta for assignment. Retention is the
newest 20 valid events per object; records exclude taxonomy text and request
material. Audit cannot arbitrate idempotency.

The Category Create runtime checkpoint extends explicit uninstall for the exact
new option family and audit key. Assignment uses the same exact key in
postmeta. Deletion uses Core APIs. Independent verification may add one
bounded, prepared, exact-key read against the active blog's postmeta and
termmeta tables, but only through the ADR-004 amendment. This ADR does not
expand active runtime SQL authority.

## Alternatives rejected

### Generic `taxonomy` plus generic term CRUD

Rejected because it exposes site/plugin-specific taxonomies, capability maps,
meta workflows, and side effects outside a stable public contract.

### Assigning by name or slug

Rejected because it makes lookup/creation ambiguous, can create typo terms,
and changes behavior under sanitization, locale, and filters. IDs keep
discovery and mutation separate.

### Append/remove modes

Rejected for Phase 1.5 because multiple mutation modes multiply concurrency
and retry semantics. Exact replacement plus an expected set is one auditable
contract.

### Allowing an empty desired set

Rejected because it turns assignment into a clear-all operation. Phase 1.5
supports positive assignment, while explicit clearing can be reviewed later as
a separate destructive ability.

### Using post `modified_gmt` for relationship concurrency

Rejected because taxonomy relationship changes do not provide a reliable,
portable post-modified version. Comparing the selected term-ID set is narrower
and directly tied to the requested state.

### Treating an existing term as a replay

Rejected because uniqueness does not prove that this actor/request created the
term. Silent adoption can cross authorization and ownership expectations.

### Transients, audit history, or `add_option()` for ownership

Rejected by the established ADR-003 concurrency analysis. Only the strict
private insert-if-absent ownership primitive may arbitrate a Create request.

### Direct database taxonomy writes or rollback

Rejected because they bypass Core capabilities, sanitization, cache/count
maintenance, hooks, and compatibility. Blind rollback can overwrite legitimate
concurrent changes.

## Consequences

### Positive

- The surface is useful for ordinary draft Posts while remaining reviewable.
- Capabilities follow the active Core taxonomy mappings.
- Create retries cannot silently adopt unrelated terms.
- Assignment has explicit stale-write detection and truthful limitations.
- Public schemas exclude custom taxonomies and arbitrary metadata.
- Runtime/database exceptions remain narrow and testable.

### Trade-offs and residual risks

- Pages and custom post types cannot receive terms in Phase 1.5.
- Clients must list/create terms before assigning IDs.
- Exact replacement can remove relationships and therefore requires clear MCP
  destructive annotation and client confirmation policy.
- `expected_term_ids` is best effort; a concurrent writer can still race after
  the final comparison.
- Core/plugin hooks can create uncertain partial state. Failing closed may
  require administrator review rather than automatic retry.
- Taxonomy idempotency and termmeta cleanup enlarge private-state/uninstall test
  matrices and must land before Create exposure.

## Validation requirements

Each implementation checkpoint must test strict schemas, real filtered
capability maps, existence hiding, parent taxonomy, bounded 51-item overflow,
duplicate conflicts,
idempotency concurrency/crash points, no-TTL ownership, exact assignment/no-op,
stale-set conflict, hook races, invariant verification, audit bounds/privacy,
multisite isolation, uninstall completeness, exact MCP allowlist order, and
forbidden side effects.

Phase 1.5.4 repeated the complete matrix through real WordPress 6.9/7.1
environments and authenticated Streamable HTTP MCP, then passed Plugin Check
2.1.0 static/runtime checks and an exact-diff security review. Phase 1.5 is
formally sealed with exactly twenty-one tools. The assignment operation remains
best-effort non-CAS; clients must re-read after `wp_auto_taxonomy_state_uncertain`.
