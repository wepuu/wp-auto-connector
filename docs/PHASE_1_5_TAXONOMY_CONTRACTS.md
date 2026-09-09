# Phase 1.5 Taxonomy Mutation Contracts

Status: **PHASE 1.5.0–1.5.2 CONTRACTS; CATEGORY AND TAG CREATE IMPLEMENTED CANDIDATES; RUNTIME EXACTLY TWENTY TOOLS**

This document is the authoritative public contract for Phase 1.5 taxonomy
mutation. It extends the sealed Phase 1.2 category/tag read contracts without
changing them. Phase 1.5.0 was documentation-only; the separately authorized
Phase 1.5.1 and 1.5.2 checkpoints implement Category Create and Tag Create and
extend the runtime to exactly twenty tools. The assignment checkpoint remains
unimplemented.

## Fixed scope

Phase 1.5 may add exactly three WordPress Abilities and their Adapter-derived
MCP names, in this order:

| Checkpoint | WordPress Ability | MCP tool | Operation |
| --- | --- | --- | --- |
| 1.5.1 | `wp-auto/category-create` | `wp-auto-category-create` | Create one built-in Category |
| 1.5.2 | `wp-auto/tag-create` | `wp-auto-tag-create` | Create one built-in Tag |
| 1.5.3 | `wp-auto/taxonomy-assign` | `wp-auto-taxonomy-assign` | Replace one supported taxonomy set on one draft Post |

Only the built-in `category` and `post_tag` taxonomies are supported. The
contract does not expose arbitrary taxonomy names, custom taxonomies, term
updates/deletion, term meta, bulk creation, nav menus, links, formats, REST
arguments, SQL, or generic WordPress term APIs.

Assignment is limited to the built-in `post` post type because both approved
taxonomies are registered for Posts by Core. Pages, attachments, revisions,
custom post types, and non-draft Posts are outside Phase 1.5.

## Shared contract rules

- Every input/output schema is a strict object with
  `additionalProperties: false`.
- Ability entry permission and the domain service independently resolve the
  fixed Core taxonomy object and repeat the required real capability check.
- Role names, `manage_options`, administrator checks, and hard-coded default
  capability strings are not substitutes for the taxonomy object's actual
  capability mapping.
- Only allowlisted fields reach WordPress Core APIs.
- Returned term values come from a final Core re-read after sanitization,
  canonicalization, filters, and hooks.
- All errors are translatable and omit Core errors, SQL, option/meta values,
  stack traces, credentials, request bodies, and raw idempotency keys.
- Protocol authentication, Ability permission denial, schema rejection,
  WP-Auto semantic errors, and MCP `isError` remain separate layers.

String limits are Unicode-character limits enforced through the Ability schema
and repeated semantically where Core normalization can change meaning.
Whitespace-only names and slugs that sanitize to an empty value are rejected.

## Category Create

`wp-auto/category-create` always targets the built-in `category` taxonomy.

### Input

Required fields:

| Field | Type | Contract |
| --- | --- | --- |
| `name` | string | 1–200 characters and at least one non-whitespace character |
| `idempotency_key` | string | 8–128 characters; pattern `^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$` |

Optional fields:

| Field | Type | Contract |
| --- | --- | --- |
| `slug` | string | 1–200 characters; must remain non-empty after Core-compatible sanitization |
| `description` | string | 0–50,000 characters |
| `parent_id` | integer | minimum 1; an existing Category, never another taxonomy |

Omitted `slug` and `description` normalize to an empty string and omitted
`parent_id` normalizes to zero for fingerprinting. Zero is not an accepted
client-supplied `parent_id`.

The client cannot provide taxonomy, alias/term-group data, term ID, count,
object relationships, meta, locale, arbitrary Core arguments, or a list of
terms.

### Authorization and parent privacy

Both permission layers resolve `get_taxonomy( 'category' )`, require a valid
non-empty `$taxonomy->cap->manage_terms`, and call
`current_user_can( $taxonomy->cap->manage_terms )`.

When `parent_id` is supplied, the service checks it only after the management
capability succeeds. Missing, invalid, or wrong-taxonomy parents return the
same `wp_auto_term_not_found` response. A newly created Category cannot form a
cycle because its parent must already exist.

### Output

Every field is required:

| Field | Type | Contract |
| --- | --- | --- |
| `id` | integer | final Category term ID |
| `name` | string | final stored name |
| `slug` | string | final stored slug |
| `description` | string | final stored description |
| `count` | integer | final Core relationship count, minimum 0 |
| `parent_id` | integer | final Category parent ID, minimum 0 |
| `idempotency_replayed` | boolean | false for creation; true for a verified completed replay |

Annotations:

```text
readonly=false
destructive=false
idempotent=true
```

## Tag Create

`wp-auto/tag-create` always targets the built-in `post_tag` taxonomy. Its
required `name` and `idempotency_key`, optional `slug` and `description`,
normalization, forbidden fields, and annotations match Category Create.
`parent_id` is not accepted because Core Tags are non-hierarchical.

Both permission layers resolve `get_taxonomy( 'post_tag' )` and require the
taxonomy object's actual non-empty `cap->manage_terms` capability.

The strict output contains exactly `id`, `name`, `slug`, `description`,
`count`, and `idempotency_replayed`. It never contains `parent_id`.

## Term-create idempotency

Both Create operations use persistent, concurrent-safe idempotency with scope:

```text
site + actor user ID + Ability name + idempotency_key
```

The implementation derives a scope hash and stores a private non-autoloaded
option named `wp_auto_connector_taxonomy_idempotency_<scope-hash>`. The raw
key, name, slug, description, parent name, complete request, and Core error are
never persisted. The fixed record contains only version, actor ID, Ability,
SHA-256 payload fingerprint, logical state, target term ID when known, and GMT
timestamps.

Category fingerprints fields in the fixed order `name`, `slug`,
`description`, `parent_id`; Tag fingerprints `name`, `slug`, `description`.
Fingerprint input uses the validated semantic request values and the omitted
defaults above, not the later Core-generated slug.

The existing ADR-003 `AtomicOwnershipStore` is the only permitted arbitration
primitive. The Phase 1.5.1 checkpoint narrowly adds the fixed
taxonomy-idempotency option family to its exact allowlist and to
explicit-uninstall cleanup before exposing Category Create. This does not
authorize arbitrary option names or other direct SQL.

Required behavior matches the sealed content/media ownership model:

1. A new scope is atomically claimed before one Core create attempt.
2. A completed identical request re-reads and verifies the same taxonomy,
   target term, parent invariant, and actor's current authorization, then
   returns it with `idempotency_replayed=true` and no new audit event.
3. A different payload under the same scope returns
   `wp_auto_idempotency_conflict` without a Core write.
4. A live or unresolved claim returns `wp_auto_idempotency_in_progress`.
5. There is no TTL, age-only takeover, transient authority, or blind retry.
6. A claim becomes retryable only after deterministic proof that no term or
   term-taxonomy row was created by the attempt.
7. If Core may have created a term but correlation, final verification,
   idempotency completion, or audit finalization is uncertain, retain the
   claim and return `wp_auto_taxonomy_state_uncertain`.

Core uniqueness is not idempotency. If the requested name/slug collides with
an independently existing term, the service does not adopt that term as the
result and returns `wp_auto_term_conflict`. Only a completed record owned by
the same idempotency scope may replay an existing target.

## Taxonomy Assign

`wp-auto/taxonomy-assign` replaces the complete term set for exactly one
approved taxonomy on one built-in Post draft.

### Input

All fields are required:

| Field | Type | Contract |
| --- | --- | --- |
| `target_id` | integer | Post ID, minimum 1 |
| `taxonomy` | string | enum `category` or `post_tag` |
| `term_ids` | array of integers | 1–50 unique positive IDs; requested exact final set |
| `expected_term_ids` | array of integers | 0–50 unique positive IDs; caller's expected current exact set |

Arrays must already contain unique JSON integers. The service canonicalizes
them to ascending integer order for comparison and output. `term_ids` cannot
be empty, so this tool cannot clear all Categories or Tags. The empty
`expected_term_ids` array means the caller expects no current terms.

Names/slugs are not accepted for assignment. This avoids implicit term
creation, ambiguous name resolution, accidental typo terms, and locale/filter
differences. Clients discover IDs through the frozen Phase 1.2 list tools or
the two Create outputs.

### Authorization and visibility

The Ability and service must:

1. resolve the fixed taxonomy object and require its actual non-empty
   `cap->assign_terms` capability;
2. resolve the built-in `post` type and require its actual non-empty
   `cap->edit_posts` baseline;
3. require final `current_user_can( 'edit_post', target_id )` authorization;
4. require the target to be exactly a built-in `post` in `draft` status; and
5. verify every requested `term_ids` ID belongs to the selected taxonomy.

`expected_term_ids` is an opaque prior-state precondition rather than an
existence query. If a formerly assigned term was deleted, comparison with the
latest set produces a conflict instead of revealing a separate lookup result.

Checks are repeated immediately before privileged work. A target that is
missing, the wrong type/status, or unauthorized returns the same
`wp_auto_content_not_found` 404. A missing or wrong-taxonomy term returns
`wp_auto_term_not_found` 404 only after target and taxonomy authorization.
Responses never reveal whether an inaccessible object exists.

### Concurrency and write semantics

The operation uses exact replacement (`append=false`) through the fixed Core
term relationship API. It never accepts client `append`, `remove`,
`create_missing`, or arbitrary taxonomy arguments.

Immediately before writing, re-read at most 51 canonical current IDs for the
selected taxonomy and the non-target approved taxonomy. If either current set
exceeds 50, return `wp_auto_taxonomy_set_too_large` without writing; the
bounded Phase 1.5 tool cannot safely prove exact replacement/invariants for
that object.

For supported current sets:

- if current IDs already equal `term_ids`, return a successful no-op with
  `changed=false`, even when `expected_term_ids` is now stale;
- otherwise, if current IDs do not equal `expected_term_ids`, return
  `wp_auto_taxonomy_conflict` 409 without writing;
- otherwise perform one exact replacement attempt.

This is best-effort optimistic concurrency, not an atomic compare-and-swap.
Another writer or hook can change relationships between the final comparison
and the Core write. The response and documentation must not claim serializable
or exactly-once assignment.

Before Core mutation, snapshot protected target fields and the non-target
approved taxonomy set. Use an operation-scoped invariant guard with
`try/finally`, then re-read and verify:

- target remains the same built-in `post`, actor, and `draft` status;
- protected content fields, dates, slug, password, parent, menu order,
  comment/ping state, featured media, and unrelated metadata are unchanged;
- the selected taxonomy is exactly `term_ids`; and
- the other approved taxonomy is unchanged.

Normal Core term-count changes, relationship rows, caches, and documented
taxonomy hooks are expected. Do not blindly roll back when hooks or partial
failures make the final state uncertain.

### Output

Every field is required:

| Field | Type | Contract |
| --- | --- | --- |
| `target_id` | integer | authorized Post draft ID |
| `target_type` | string | fixed enum `post` |
| `status` | string | fixed enum `draft` |
| `taxonomy` | string | `category` or `post_tag` |
| `term_ids` | array of integers | final canonical ascending exact set |
| `changed` | boolean | false for an already-satisfied no-op |

Annotations:

```text
readonly=false
destructive=true
idempotent=true
```

The destructive annotation is required because exact replacement can remove
existing relationships even though it cannot delete term objects.

## Private taxonomy audit

Taxonomy mutation uses the separate exact private key
`_wp_auto_connector_taxonomy_mutation_audit`; it does not extend or reinterpret
the sealed content/media audit schemas.

- Category/Tag Create events are stored as private term metadata on the
  created term.
- Assignment events are stored as private post metadata on the target Post.
- Retain only the newest 20 valid events per audited object.
- Serialize audit writers with the existing fixed
  `wp_auto_connector_mutation_audit_lock_<scope-hash>` ownership family, using
  a domain-discriminated scope so term IDs and Post IDs cannot collide.
- Create replays and Assignment no-ops add no event.

Every event contains only version, operation, Ability, actor ID, taxonomy,
target ID, GMT timestamp, and the minimum safe concurrency attribution:

- Create adds request fingerprint and final parent ID where applicable.
- Assignment adds expected, previous, and resulting canonical term-ID arrays.

Never store names, slugs, descriptions, raw keys, bodies, credentials, role or
capability snapshots, Core errors, or unrelated object data. Audit retention
is not authoritative idempotency state.

The Phase 1.5.1 exposure extends explicit uninstall to remove and
independently verify absence of the taxonomy-idempotency option family and the
exact taxonomy audit key from termmeta. Core APIs perform deletion. Any direct
SQL remains read-only, exact-key/prefix, bounded, and limited to uninstall
verification under the ADR-004 amendment. Phase 1.5.0 itself granted no new
runtime or uninstall SQL authority.

## Error contract

| Code | Status | Meaning |
| --- | ---: | --- |
| `wp_auto_invalid_request` | 400 | Unknown field, invalid type/bound/enum, duplicate ID, empty requested set, or invalid normalized text |
| `wp_auto_content_not_found` | 404 | Assignment target missing, wrong type/status, or unauthorized |
| `wp_auto_term_not_found` | 404 | Parent/term missing or belongs to another taxonomy after authorization |
| `wp_auto_idempotency_conflict` | 409 | Same idempotency scope with a different payload or unsafe completed replay |
| `wp_auto_idempotency_in_progress` | 409 | Create ownership is live or unresolved |
| `wp_auto_term_conflict` | 409 | Core term uniqueness conflicts with an independently existing term |
| `wp_auto_taxonomy_conflict` | 409 | Latest canonical term set differs from `expected_term_ids` |
| `wp_auto_taxonomy_set_too_large` | 409 | Current selected or protected non-target set exceeds the 50-term safety bound |
| `wp_auto_taxonomy_create_failed` | 500 | Proven no-term Core create failure |
| `wp_auto_taxonomy_assign_failed` | 500 | Proven unapplied relationship replacement failure |
| `wp_auto_taxonomy_state_uncertain` | 500 | A term/relationship or private finalization may have changed but cannot be verified |

Capability denial remains an Ability/MCP Adapter permission failure rather
than one of these semantic errors.

## Side-effect boundary

Requested effects are one built-in term creation or one exact term-set
replacement on one draft Post. Expected Core/plugin lifecycle effects include
term/term-taxonomy rows, relationship rows, term counts, caches, sanitization,
and taxonomy hooks, plus the fixed private idempotency/audit state.

WP-Auto must not intentionally update/delete terms; create/modify custom
taxonomies; publish or change post status; mutate title/content/excerpt/slug,
author, dates, media, SEO, users, settings, plugins, themes, menus, arbitrary
meta, unrelated objects, Cloud state, telemetry, or outbound HTTP.

## Allowlist progression and checkpoints

The sealed eighteen-tool order remains unchanged through Phase 1.5.0. The
Phase 1.5.1 and 1.5.2 candidates append the first two entries; later
checkpoints append only:

```text
19 wp-auto/category-create
20 wp-auto/tag-create
21 wp-auto/taxonomy-assign
```

There is no wildcard discovery, generic taxonomy Ability, resource, prompt,
REST proxy, or third-party tool.

1. **1.5.0 — Contract and Security Architecture Freeze:** freeze this document
   and ADR-006; runtime stays exactly eighteen.
2. **1.5.1 — Category Create:** implement Category Create, taxonomy
   idempotency/audit/uninstall foundations, and exactly nineteen tools.
3. **1.5.2 — Tag Create:** reuse the frozen foundation for Tag Create and
   exactly twenty tools.
4. **1.5.3 — Taxonomy Assign:** implement draft-Post exact replacement and
   exactly twenty-one tools.
5. **1.5.4 — Integration and Security Seal:** complete automated, real
   WordPress, Streamable HTTP, concurrency, state-integrity, uninstall,
   Plugin Check, and security gates without adding tools.

At the Phase 1.5.0 documentation checkpoint there was no placeholder Ability,
production service, test stub, registrar change, private state, publishing,
deletion, SEO, Cloud, telemetry, external request, or later-roadmap
implementation. Phase 1.5.1 and 1.5.2 are the separately authorized Category
Create and Tag Create checkpoints described above; Phase 1.5.3 remains pending.
