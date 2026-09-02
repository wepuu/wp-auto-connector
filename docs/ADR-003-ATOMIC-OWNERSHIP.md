# ADR-003: Atomic Ownership for Mutation Safety

- Status: **Accepted - Phase 1.3.3 Atomic Ownership Security Amendment**
- Date: 2026-09-02
- Decision scope: internal ownership infrastructure for Phase 1.3 mutation safety

## Context

Phase 1.3 needs durable coordination for two internal workflows: the initial
Create idempotency claim and serialization of per-object mutation audit
appends. The public mutation contracts, WordPress Core content path, and
audit event schema are already frozen by ADR-002. A concurrent live probe
showed that the assumed `add_option()` uniqueness behavior is not a strict
acquire-if-absent primitive on WordPress 6.9: its SQL upsert path can return
success to more than one contender.

This amendment defines the smallest database-backed ownership primitive that
can arbitrate those internal records without moving content mutation out of
WordPress Core APIs. It is an internal implementation boundary, not a new
Ability, MCP tool, public API, or audit-history store.

## WordPress 6.9 `add_option()` finding

WordPress 6.9's `add_option()` path uses an `INSERT ... ON DUPLICATE KEY
UPDATE ...` statement for the options table. Under concurrent first
acquisition, two requests may therefore both report success while replacing
the same option value. The add-option uniqueness assumption cannot establish
which request owns the record.

The finding was reproduced in a disposable WordPress 6.9 / PHP 8.1 / MariaDB
environment and is recorded in
`docs/PHASE_1_3_3_ATOMIC_OWNERSHIP_REVIEW.md`.

## SEC-2

The SEC-2 audit candidate used an `add_option()` mutex. Because that call is
an upsert under WordPress 6.9, concurrent requests can both enter the audit
append critical section. Audit serialization therefore remains unproven and
the candidate is rejected until this amendment is reviewed and implemented.

## SEC-3

The same primitive was used for the initial Create idempotency claim. A live
two-request probe produced two successful Core draft creations for one
scope/key and payload. Atomic ownership of the initial claim is consequently
a blocker for safe Create retries; the public idempotency state model remains
valid, but its acquisition mechanism must change.

## Decision

### Approved SQL boundary

Direct SQL is approved only inside a future private service conceptually named
`AtomicOwnershipStore`, and only for atomic acquisition and release of
WP-Auto-owned internal ownership rows in the active site's `$wpdb->options`
table. The service is not a generic database layer. It must not be exposed as
an Ability, MCP tool, client-controlled query surface, or general option API.

Content, taxonomy, users, media, SEO, settings, and audit event history remain
WordPress API operations. In particular, Post/Page mutation continues to use
WordPress Core content APIs.

### Acquire semantics

Acquisition uses one strict insert-if-absent operation, conceptually:

```sql
INSERT IGNORE INTO $wpdb->options
    (option_name, option_value, autoload)
VALUES (...)
```

An exactly equivalent strict insert-if-absent query is acceptable. The
database UNIQUE key on `option_name` is the arbitration point. Ownership is
acquired if and only if the database reports `affected rows === 1`. A result
of zero, `false`, an error, or any unexpected result means that ownership was
not acquired and the caller must not proceed as owner.

The inserted ownership row must be explicitly non-autoloaded (`autoload=false`
or the WordPress 6.9 database representation produced for an explicit false
value); ownership locks must never become autoloaded options.

The service must not use `get_option()` followed by an absent check and an
insert as ownership proof. A read after a failed acquisition may classify the
authoritative existing record, but it is not part of arbitration.

WordPress Core itself uses `INSERT IGNORE INTO $wpdb->options` for internal
locking patterns (including taxonomy, comment, revision, and upgrader locks).
That is precedent for this narrowly scoped primitive, not permission for
general SQL.

### Release semantics

Release uses one conditional delete comparing both the private option name and
the exact persisted ownership value:

```sql
DELETE FROM $wpdb->options
WHERE option_name = ?
  AND CAST(option_value AS BINARY) = CAST(? AS BINARY)
```

The SQL above is conceptual. The future implementation must use a
MariaDB/MySQL-compatible binary-safe comparison (for example `CAST(... AS
BINARY)`, `CONVERT(... USING BINARY)`, or an equivalently proven expression)
so ownership equality is byte-for-byte and independent of the `wp_options`
text collation. It must not rely on ordinary `option_value = ?` comparison;
a value differing only in case, accents, or padding must never be deleted as
the owner's value. A hash-only comparison is not a substitute for comparing
the persisted value bytes.

Release succeeds if and only if `affected rows === 1`. Zero, `false`, an
error, or an unexpected result is not a successful release and must feed the
existing fail-closed `wp_auto_mutation_state_uncertain` behavior where the
operation may have progressed. Database release success alone is not store
success: required cache-coherence finalization must also complete.

The future implementation must never use `get_option()` followed by
`delete_option()` as the ownership guarantee. That check-then-delete sequence
can delete a value owned by another request.

### Prepared-query binding

Every dynamic SQL value, including the canonical option name and serialized
ownership value, must be bound through `$wpdb->prepare()` or an equivalently
safe WordPress database binding mechanism. The active-site table identifier
`$wpdb->options` is the fixed architecture-selected table and is not
client-supplied. Clients must never provide a table, column, predicate, SQL
fragment, placeholder format, or serialized ownership record.

### Cache coherence

Direct SQL bypasses WordPress option functions, so cache coherence is a
correctness requirement. The implementation must account for the individual
option cache, the `notoptions` cache, the representation of non-autoloaded
rows, and persistent object-cache environments. After a successful insert or
delete, a later `get_option()` must not observe a stale pre-insert or
pre-delete value. A persistent object cache may assist coherence, but
correctness must not depend on Redis, Memcached, or `wp_cache_add()`.

Database acquisition success means only `affected rows === 1`. Store-level
acquire success additionally requires all required cache-coherence work to
finish successfully. If the insert succeeds but cache finalization fails or
throws, the request must not proceed to Core Create or a protected critical
section as a normal owner. It propagates an internal uncertain/unresolved
result mapped to the existing fail-closed service semantics; the persisted
row remains authoritative and must not be blindly deleted.

Database release success means only a conditional-delete result of
`affected rows === 1`. Store-level release success additionally requires
cache finalization. If the delete succeeds but cache cleanup fails or throws,
the operation remains uncertain; the implementation must not issue a blind
second delete or claim release success.

After failed acquisition, any authoritative read used to classify an existing
record must refresh or invalidate stale local and persistent absence caches as
necessary. It must not trust a cached absence that predates the contender's
insert. The exact helper sequence is an implementation concern for the later
runtime review.

### Serialization

The exact persisted value is used for conditional release. Internal records
must use WordPress-compatible option serialization, conceptually
`maybe_serialize( $value )`, for both acquisition and comparison. JSON is not
introduced as a new ownership-only format.

### Option hooks

Ownership rows are private infrastructure, not generic WordPress option
mutations. The direct acquire/release primitive does not need to emulate
`add_option`, `added_option`, `delete_option`, or `deleted_option` hooks, and
the future implementation must not fire those hooks manually. Later
single-owner idempotency transitions may use normal `update_option()` APIs
and their ordinary hook behavior.

### Multisite

Ownership rows use `$wpdb->options` for the currently active site, never
`$wpdb->sitemeta` or a network-global lock. Scope hashes that depend on site
identity include `get_current_blog_id()`. The primitive therefore arbitrates
only within the active site's option table.

### Crash/orphan behavior

There is no TTL, time-based takeover, or lock stealing. An orphaned audit lock
blocks later audit finalization and resolves to the existing uncertain-state
path and operator remediation. Create idempotency retains its persistent
fail-closed claim semantics; an unresolved claim is never silently expired.

### Audit integration

Future SEC-2 remediation obtains a per-site, per-target-object opaque lock
from `AtomicOwnershipStore`, then calls
`MutationAuditStore::append()`. The lock is acquired with the strict insert
and released with the conditional name-plus-token delete. Audit events remain
in `_wp_auto_connector_mutation_audit` through normal WordPress metadata APIs;
direct SQL is used only for serialization ownership, not event storage.

### Create idempotency integration

Future SEC-3 remediation uses the persistent idempotency record itself as the
initial ownership record. The first claim is inserted by
`AtomicOwnershipStore`; a successful strict insert establishes the `claimed`
state. A failed insert reads the existing authoritative record to classify a
completed replay, conflict, in-progress, or unresolved outcome.

The frozen record schema is unchanged:

```text
version
actor_user_id
ability
fingerprint
state
target_id
created_gmt
updated_gmt
```

No ownership token, lease, expiry, sequence, or raw key is added. When Core
definitively proves that no object was created, release may conditionally
delete the exact initial serialized record. If the value has changed, release
fails closed as `wp_auto_mutation_state_uncertain`; there is no unconditional
delete. Later single-owner state transitions may use normal option APIs. No
direct SQL UPDATE is approved by this amendment.

## Interaction with ADR-002

ADR-003 supersedes only the internal atomic ownership mechanism for Create
acquisition, the internal audit serialization ownership mechanism, and the
blanket direct-SQL prohibition insofar as it applies to those narrowly scoped
ownership rows. ADR-002 remains authoritative for public mutation contracts,
the WordPress Core content mutation path, authorization, Update concurrency,
audit event schema, and documented Core side effects.

Public idempotency outcomes, error codes, output schemas, audit fields, and
the twelve-tool runtime surface do not change under this amendment.

## Security boundaries

- Only a private, prefixed, non-autoloaded ownership option may be addressed.
- The active site's `$wpdb->options` table is the only permitted table.
- Option names and values are produced by the service; clients cannot supply
  SQL, table names, columns, predicates, or serialized records.
- Every dynamic SQL value is bound with `$wpdb->prepare()` or an equivalent
  safe WordPress binding mechanism.
- Release comparison is binary-safe and byte-exact; ordinary text-collation
  equality is never an ownership proof.
- The content write itself always goes through WordPress Core APIs, including
  capability checks, hooks, revisions, sanitization, and cache invalidation.
- Acquisition and release ambiguity fails closed through the existing
  `wp_auto_mutation_state_uncertain` semantics.
- Ownership is local synchronization state, not a public contract, audit
  history, or substitute for WordPress authorization.

## Rejected alternatives

- `add_option()` upsert behavior as an atomic ownership proof;
- PHP pre-check followed by insert or delete;
- `wp_cache_add()`, transient, process-memory, or post-meta-only locks;
- unique post-meta rows or a second ephemeral lock around the idempotency
  record;
- MySQL advisory locks, `SELECT ... FOR UPDATE`, table locks, or custom SQL
  transactions;
- direct SQL against posts, postmeta audit history, taxonomies, users,
  settings, media, or SEO;
- a generic database abstraction or client-selected SQL surface;
- network-global `sitemeta` locks;
- manual emulation of `add_option`/`added_option`/`delete_option`/
  `deleted_option` hooks for private ownership infrastructure.

## Consequences

The database unique key becomes the single arbitration authority for the two
internal ownership use cases while Core remains authoritative for content and
metadata behavior. The design adds cache-coherence obligations and may leave
orphaned claims or locks requiring operator remediation, but it avoids the
duplicate-create and concurrent-audit races demonstrated by SEC-2 and SEC-3.
It does not provide transactional exactly-once Core creation, atomic
`modified_gmt` compare-and-swap, or any new public MCP capability.

## Validation requirements

Before runtime remediation can proceed, implementation review must prove:

1. concurrent first acquisition yields exactly one `affected rows === 1` owner;
2. a non-owner cannot append audit history or create a duplicate draft;
3. conditional release uses a prepared, binary-safe name-plus-value comparison
   and cannot delete a changed value, including collation-equivalent bytes;
4. store-level acquisition and release succeed only after required cache
   finalization, and insert-then-cache-failure/delete-then-cache-failure both
   fail closed without blind cleanup retry;
5. failed-acquisition authoritative reads refresh stale absence caches;
6. rows are non-autoloaded and scoped to the active site's options table;
7. Core content APIs and existing public contracts remain unchanged;
8. crash/orphan outcomes fail closed without TTL or takeover; and
9. no prohibited SQL or generic database surface is introduced.

This ADR records the architecture decision approved at the Phase 1.3.3
Atomic Ownership Contract / ADR Amendment Re-Review Gate. Runtime remediation
is still blocked until this amendment is landed on `main`; acceptance here
does not resolve SEC-2 or SEC-3.
