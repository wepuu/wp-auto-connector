# Phase 1.3.3 Atomic Ownership Review Evidence

Status: **ARCHITECTURE EVIDENCE**

Review date: 2026-09-02

This document records the evidence that motivated the Phase 1.3.3 atomic
ownership amendment. It is not runtime validation and does not authorize a
production implementation. The rejected SEC-2 candidate remains in the
original working tree and is intentionally not copied into this docs-only
branch.

## WordPress 6.9 option SQL

The WordPress 6.9 `add_option()` path in `wp-includes/option.php` uses an
options-table upsert of the form:

```sql
INSERT ...
ON DUPLICATE KEY UPDATE
    option_name = VALUES(option_name),
    option_value = VALUES(option_value),
    autoload = VALUES(autoload)
```

That path preserves an option row but does not provide strict
acquire-if-absent ownership when two requests race. The options table still
has a unique key on `option_name`, but the upsert can report success for both
contenders.

WordPress Core also contains a narrow `INSERT IGNORE INTO $wpdb->options`
precedent for internal lock patterns. In the WordPress 6.9 source these
occurred in `wp-includes/taxonomy.php`, `wp-includes/comment.php`,
`wp-includes/revision.php`, and
`wp-admin/includes/class-wp-upgrader.php`. The queries use a unique option
name and lock-style `autoload` values. This is precedent for a narrowly scoped
internal ownership primitive, not a general authorization for SQL.

## Real MariaDB race

A disposable WordPress 6.9 / PHP 8.1 / MariaDB 11.8 environment paused two
independent requests after the PHP-side pre-check and before the option SQL.
Both requests then called `add_option()` with the same option name:

| contender | return value | observed affected rows | final value |
| --- | --- | ---: | --- |
| A | `true` | 1 | `token-B` |
| B | `true` | 2 | `token-B` |

Both callers therefore believed they acquired the lock, while the later
upsert replaced the first value. This invalidates `add_option()` as the
ownership authority for a concurrent first acquisition.

## SEC-2 impact

The rejected SEC-2 candidate used an `add_option()` mutex around audit append
operations. Under the race above, two requests can enter the critical section
and both append based on stale history. The candidate cannot prove serialized
audit attribution and remains **OPEN** pending an approved ownership
primitive.

## SEC-3 two-draft proof

Two independent authenticated requests used the same Create idempotency
scope/key and identical payload. Both returned normal success with
`idempotency_replayed=false`. WordPress Core was called twice, producing draft
IDs **5** and **4**; the final draft count increased by two. One persisted
completed claim row ultimately pointed at draft ID **5**. The second draft was
not represented by a safe completed replay, demonstrating the concurrent
initial-claim blocker.

SEC-3 therefore remains **OPEN** as a confirmed blocker. The public
idempotency state model is not rejected; only its initial ownership primitive
is invalidated.

## Test-harness mismatch

The existing unit harness exercises the candidate service and its fake option
store sequentially. It does not model two independent database writers
executing the WordPress 6.9 upsert concurrently, so a green unit suite cannot
establish SEC-2 or SEC-3 atomicity. The race evidence above is the reason a
database-arbitrated primitive and dedicated concurrency tests are required
before runtime remediation is exposed.

## Architecture recommendation

Adopt the proposed `AtomicOwnershipStore` described in
`docs/ADR-003-ATOMIC-OWNERSHIP.md`:

- acquire only with one strict insert-if-absent operation such as
  `INSERT IGNORE INTO $wpdb->options (...) VALUES (...)`;
- treat `affected rows === 1` as the sole ownership success signal;
- release only with an atomic name-plus-exact-value conditional delete,
  again requiring `affected rows === 1`;
- preserve WordPress-compatible serialization, non-autoloaded rows, option
  cache coherence, active-site scoping, and fail-closed orphan semantics;
- retain WordPress Core APIs for content mutation and metadata audit storage;
- prohibit transactions, row/advisory locks, arbitrary SQL, and a generic
  database layer.

This recommendation is an internal architecture amendment. It adds no
Ability, MCP tool, public field, error code, or external idempotency semantic.

## Public contract classification

```text
Public MCP contract delta = NONE
Create/Update JSON schema delta = NONE
Error-set delta = NONE
12-tool surface delta = NONE
Idempotency external semantics delta = NONE
Audit event schema delta = NONE
Normative internal ownership mechanism delta = YES
ADR semantic delta = YES
Architecture internal-infrastructure delta = YES
```

## Evidence boundary and cleanup

The race probes ran only in a disposable local environment. Temporary users,
Application Passwords, fixtures, database state, containers, and networks
were removed after capture. No credentials, content, or external service
requests are included in this document.

The architecture amendment is approved at the Re-Review Gate, but runtime
remediation remains blocked until it is landed on `main`. No production PHP,
tests, Composer files, CI, plugin version, or registrar changes are part of
this evidence branch.

## Review Gate correction (2026-09-02)

The architecture direction remains approved. The initial amendment review
identified that ordinary textual `option_value` equality is not sufficient to
prove byte-exact ownership release across supported database collations. The
candidate is corrected to require a binary-safe exact comparison and explicit
cache-finalization success semantics for both acquisition and release. The
SEC-2 and SEC-3 evidence above is unchanged.

Final review result:

```text
Atomic Ownership Contract / ADR Amendment Re-Review Gate:
APPROVED
```

The accepted candidate includes binary-safe byte-exact conditional release,
prepared SQL binding, the distinction between database and Store-level
success, and fail-closed cache-finalization semantics. This is architecture
evidence only; it does not mark runtime validation complete.
