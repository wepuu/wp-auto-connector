# ADR-004: Uninstall Private-State Cleanup

- Status: **Accepted - Phase 1.3.3 Uninstall Private-State Cleanup**
- Date: 2026-09-04
- Decision scope: explicit uninstall cleanup of private mutation state and the three uninstall-only read-only SQL families

## Status and scope

This is the accepted normative Architecture Decision Record for Phase 1.3.3. It records the approved Policy A uninstall boundary and the narrowly scoped database reads required to discover and verify WP-Auto-owned private mutation state.

This accepted ADR authorizes no implementation. It does not create or modify uninstall.php, production PHP, tests, bootstrap code, the MCP server, an Ability, a tool, Composer dependencies, CI, plugin version, or any public contract. Implementation remains separately gated and this ADR is pending repository landing.

ADR-004 = ACCEPTED BY SECURITY / NORMATIVE REVIEW / PENDING REPOSITORY LANDING

## Authoritative baseline and source architecture

The accepted ADR is authored from the exact main baseline:

- MAIN_SHA: 96f0828c4d73968ea73054a6863d30739cef813d
- parent: 9ca1854bf2f9b7f5062aa72bdb9be96db5c137f9
- tree: d61ef56df0f966f1967eeed2408272575bc3c280
- origin/main must resolve to MAIN_SHA before any implementation begins.

The approved source architecture is docs/PHASE_1_3_3_UNINSTALL_CLEANUP_ARCHITECTURE.md. Its final corrected lifecycle, SQL, allowlist, deletion-proof, multisite, privacy, and residual-authority decisions are reproduced here without redesign. ADR-002-MUTATION-SAFETY.md, ADR-003-ATOMIC-OWNERSHIP.md, and PHASE_1_3_MUTATION_CONTRACTS.md remain authoritative for their existing scopes.

Relevant WordPress Core lifecycle/API references include [Uninstall Methods](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/), [delete_option()](https://developer.wordpress.org/reference/functions/delete_option/), [delete_post_meta_by_key()](https://developer.wordpress.org/reference/functions/delete_post_meta_by_key/), [wpdb::esc_like()](https://developer.wordpress.org/reference/classes/wpdb/esc_like/), [wpdb::prepare()](https://developer.wordpress.org/reference/classes/wpdb/prepare/), [switch_to_blog()](https://developer.wordpress.org/reference/functions/switch_to_blog/), [restore_current_blog()](https://developer.wordpress.org/reference/functions/restore_current_blog/), and [get_sites()](https://developer.wordpress.org/reference/functions/get_sites/). WordPress 6.9 behavior is the implementation target; those references do not authorize a runtime change in this ADR.

## Decision summary

Adopt a supplemental, uninstall-only lifecycle/database-boundary decision:

- While the plugin runtime is active, direct SQL rules remain governed by ADR-003. AtomicOwnershipStore remains the sole runtime direct-SQL authority.
- During explicit WordPress uninstall only, ADR-004 permits exactly three read-only query families:
  1. bounded option-name enumeration and final verification on the current blog's options table;
  2. bounded exact-key audit-meta absence verification on the current blog's postmeta table;
  3. bounded physical blog-ID enumeration on the trusted blogs table, only for multisite traversal.
- Actual deletion is always delegated to WordPress Core through delete_option() and delete_post_meta_by_key().
- No direct SQL write, ownership-value read, arbitrary table read, client-selected query, retry loop, or new runtime state is introduced.
- Cleanup classifications are COMPLETE or INCOMPLETE for validation and documentation. They are not a return channel consumed by WordPress plugin deletion.

ADR-004 supplements, but does not supersede or broaden, ADR-002, ADR-003, or the Mutation Contract.

## Relationship to existing decisions

The semantic relationships are frozen:

- ADR-002 Mutation Safety semantic delta: NONE.
- ADR-003 Atomic Ownership semantic delta: NONE.
- Phase 1.3 Mutation Contract semantic delta: NONE.
- ADR-004: a new supplemental uninstall-only lifecycle/database-boundary decision.

ADR-003 continues to govern runtime AtomicOwnershipStore operations, including atomic ownership acquisition, cache finalization, and release. ADR-004 does not authorize uninstall code to reuse runtime ownership classification, issue a runtime SQL write, or alter recovery/idempotency behavior.

The current runtime remains read and draft-mutation tooling already approved by earlier phases. ADR-004 exposes no MCP endpoint, Ability, resource, prompt, REST route, or client operation.

## Context

WP-Auto Connector stores private mutation-safety state while the plugin is active. Explicit uninstall must be able to remove only those records owned by WP-Auto. WordPress Core does not provide an option-prefix enumeration API, so a narrow read-only database query is needed to discover dynamic option names without trusting a caller. Core APIs remain the deletion authority and retain their cache behavior.

The approved lifecycle policy is Policy A:

- deactivation retains mutation-safety state;
- explicit uninstall performs bounded best-effort removal of WP-Auto-owned private state;
- a reinstall resets only authority that was successfully removed.

Uninstall is a plugin lifecycle operation, not a normal request failure, retry recovery, TTL, takeover, claim expiry, or activation repair.

## Persistent-state inventory

The closed inventory is:

1. Create idempotency and recovery site options whose complete names match:
   wp_auto_connector_idempotency_[0-9a-f]{64}
2. Temporary mutation-audit lock site options whose complete names match:
   wp_auto_connector_mutation_audit_lock_[0-9a-f]{64}
3. Post metadata with the exact key:
   _wp_auto_connector_mutation_audit

Idempotency records can contain version, actor user ID, Ability, fingerprint, state, target ID, and GMT timestamps. Audit attribution can contain version, operation/Ability, actor user ID, target object ID, timestamp, Create fingerprint, and Update expected/result modified_gmt. Raw idempotency keys, content, request bodies, credentials, and authorization headers are not stored.

The inventory is closed. A future implementation must re-audit all persistence writes before landing. Discovery of any other persistent WP-Auto family blocks implementation and requires a separate decision; ADR-004 never uses a broad plugin-prefix deletion.

## Lifecycle and reinstall semantics

### COMPLETE

Cleanup is classified COMPLETE only as of one final verification point when all of the following are proven absent:

- all exact-valid idempotency options;
- all exact-valid audit-lock options;
- all rows for the exact audit metadata key on every processed blog;
- on multisite, the authoritative blog-ID traversal has reached its terminal empty batch and every enumerated blog completed both option passes and audit-meta verification without an unresolved failure.

A COMPLETE classification means a reinstall begins without historical WP-Auto mutation authority because those authoritative records no longer exist. It is an as-of-final-verification classification, not a transactional guarantee against a later write from an already-running request.

### INCOMPLETE

Any of the following produces INCOMPLETE:

- option or postmeta enumeration failure;
- Core deletion ambiguity or failure;
- verification failure or invalid result;
- resource exhaustion;
- a site that could not be processed or restored;
- a residual exact-valid row;
- an unprocessed or unresolved multisite blog;
- a concurrent write observed after the final verification point.

WordPress may still continue its ordinary plugin-file deletion flow after uninstall.php returns. ADR-004 does not invent a WP-Auto result object, WP_Error, custom delete_plugins status, MCP response, or automatic abort.

Any residual exact-valid Create idempotency record remains authoritative after reinstall under the existing runtime. It is processed by normal persisted-state logic, not ignored, expired, taken over, repaired, deleted during activation, or bypassed because uninstall was attempted. This preserves duplicate-create safety.

No lifecycle epoch, generation ID, install marker, activation bypass, TTL, or replacement authority is introduced.

## Uninstall execution gate and inputs

The future entry point must retain:

~~~php
defined( 'WP_UNINSTALL_PLUGIN' )
~~~

as the mandatory uninstall gate. Direct HTTP execution or inclusion without that constant must do nothing.

Cleanup selection receives no parameters from MCP, REST, HTTP, admin forms, CLI arguments, remote services, or users. Names, prefixes, table identifiers, columns, predicates, cursors, limits, regular expressions, and site IDs are all implementation constants or Core-derived values. No external request is made and no private values are logged.

## Direct SQL family A: option-name enumeration

Only the current blog's trusted $wpdb->options table may be queried. Both the deletion traversal and the final verification traversal use the same SQL family. The conceptual statement is:

~~~sql
SELECT option_id, option_name
FROM <trusted current $wpdb->options>
WHERE option_id > %d
  AND (
       option_name LIKE %s
       OR option_name LIKE %s
  )
ORDER BY option_id ASC
LIMIT %d
~~~

Only option_id and option_name may be selected. The cursor is an internal integer last_seen_option_id and advances monotonically to the greatest returned option_id. The batch size is a fixed, reviewed plugin constant bound as an integer parameter. There is no OFFSET, unbounded result set, restart-from-zero loop, or client-selected limit.

The query is discovery only in the deletion traversal and read-only verification in the final traversal. A returned name must pass the exact full-name allowlist before deletion in Pass 1. Deleting rows while enumerating never changes the cursor strategy: keyset pagination prevents deletion from causing skipped rows.

### Pass 1 — deletion traversal

For each blog, initialize last_seen_option_id to 0 exactly once. Run bounded keyset batches until the batch is empty. For every returned row, validate the exact full-name allowlist; preserve a malformed prefix-like name, and call delete_option() once for an exact-valid name. Record a true or false Core result only as supporting evidence. Never retry, repair, normalize, or delete from this pass by SQL.

### Pass 2 — authoritative final verification

After Pass 1 completes for that blog, create a new verification cursor initialized to 0 exactly once and run the same prepared, read-only SQL family in bounded keyset batches. Pass 2 never deletes, retries, repairs, takes over, normalizes, or restarts itself. Re-apply the exact full-name allowlist to every returned row. No exact-valid row means OPTION STATE = PROVEN ABSENT; one or more exact-valid rows means OPTION STATE = RESIDUAL and cleanup is INCOMPLETE; query failure or an invalid/non-monotonic result means UNRESOLVED and cleanup is INCOMPLETE. A malformed prefix-like row is preserved and does not by itself make cleanup incomplete.

Each pass initializes its own cursor exactly once. Neither pass may restart itself from zero. The complete uninstall option workflow performs at most one deletion traversal and one final verification traversal per blog.

### LIKE construction

For each raw fixed prefix:

- wp_auto_connector_idempotency_
- wp_auto_connector_mutation_audit_lock_

the sequence is mandatory:

1. retain the raw fixed prefix;
2. call $wpdb->esc_like( $prefix );
3. append the literal % wildcard;
4. bind the resulting pattern as %s through $wpdb->prepare().

The raw prefix is never interpolated into SQL. esc_like() always precedes wildcard append and prepare(). The table identifier is only the trusted current-blog $wpdb->options property.

### Exact deletion allowlist

SQL LIKE matching is not deletion authority. Before calling delete_option(), validate the complete option name against exactly one of these case-sensitive, anchored expressions:

~~~regex
\Awp_auto_connector_idempotency_[0-9a-f]{64}\z
\Awp_auto_connector_mutation_audit_lock_[0-9a-f]{64}\z
~~~

Malformed names, uppercase hexadecimal, 63- or 65-character suffixes, appended suffixes, embedded separators, other prefixes, and similar options remain untouched. Names with a trailing LF, CRLF, embedded newline, or leading newline also remain untouched. The expressions use case-sensitive absolute-start and absolute-end PCRE anchors with no multiline or final-newline exception. No normalization, strtolower(), truncation, wildcard-only acceptance, or best-effort repair is allowed.

### Option deletion and proof

For each exact-valid name discovered in Pass 1, call:

~~~php
delete_option( $option_name );
~~~

No SQL DELETE and no automatic retry is permitted. A true or false result is supporting evidence only. get_option() is not authoritative for uninstall absence because pre_option_{$option} and pre_option filters can short-circuit the logical read before physical retrieval and can return the supplied default.

The only authoritative absence proof is Pass 2's final read-only SQL verification using this same SQL family. No additional SQL query family is added. There is no blind second delete, raw SQL fallback, unbounded retry, TTL, takeover, or forced cleanup. Any query failure or residual exact-valid row prevents a COMPLETE claim.

## Direct SQL family B: audit-meta absence verification

Only the current blog's trusted $wpdb->postmeta table may be queried for audit-meta presence. The conceptual statement is:

~~~sql
SELECT meta_id
FROM <trusted current $wpdb->postmeta>
WHERE meta_key = %s
ORDER BY meta_id ASC
LIMIT 1
~~~

The only bound value is the exact fixed key:

_wp_auto_connector_mutation_audit

The query selects only meta_id, binds the key through $wpdb->prepare(), and uses a fixed reviewed limit of one. It must not select meta_value, post content, another meta key, or an arbitrary postmeta predicate.

### Audit-meta deletion and proof

For each active blog, the implementation calls only:

~~~php
delete_post_meta_by_key(
    '_wp_auto_connector_mutation_audit'
);
~~~

This Core API removes all matching values on the active blog and handles Core metadata caches. Its boolean result is supporting evidence only and is never final absence authority. Core may return false when no rows existed or when deletion did not complete; filters may short-circuit deletion.

After every call, regardless of true or false, run the exact-key verification query. Classification is:

- zero matching rows: PROVEN ABSENT;
- a matching row: INCOMPLETE;
- query failure, invalid result, or Throwable: INCOMPLETE / UNRESOLVED.

The implementation must not issue a second automatic delete or raw SQL deletion. The postmeta query is Family B, the second of exactly three uninstall-only direct-SQL families.

## Direct SQL family C: authoritative multisite blog enumeration

Family C is permitted only when is_multisite() is true. It is the authoritative source of physical blog namespaces for COMPLETE classification; get_sites() and WP_Site_Query are not completeness authorities because their results can be filtered or short-circuited.

The conceptual statement is:

~~~sql
SELECT blog_id
FROM <trusted $wpdb->blogs>
WHERE blog_id > %d
ORDER BY blog_id ASC
LIMIT %d
~~~

Only blog_id may be selected. The table is the trusted `$wpdb->blogs` property, the cursor is an internal integer last_seen_blog_id initialized to 0, and the fixed reviewed batch size and cursor are bound through `$wpdb->prepare()`. OFFSET, unbounded site arrays, client influence, and selection of domain, path, registered, last_updated, site metadata, flags, sitemeta, or network options are prohibited.

For each returned integer blog_id (validated to be at least 1), switch_to_blog( $blog_id ), run that blog's option Pass 1, option Pass 2, audit-meta deletion, and audit-meta verification, and always restore_current_blog() in finally. Advance the cursor monotonically. An empty batch is the terminal verification point. A query, validation, switch, processing, or restore failure leaves that blog unresolved and makes overall cleanup INCOMPLETE; it must not be silently skipped.

Each Family C traversal initializes its cursor once and never restarts itself. A normal new site receives a higher blog_id and is included if it appears before the terminal empty batch. A site added after that point is a concurrent lifecycle residual. A site removed during traversal can make processing fail and therefore produces INCOMPLETE. No transactional network snapshot is claimed.

Family C is the only direct read against `$wpdb->blogs`; no direct SQL is used against blogs for any other column, or against sitemeta, network options, or a network-global ownership table.

## Complete direct-SQL prohibition

Outside the three exact read families above, ADR-004 authorizes no direct database behavior.

Explicitly prohibited are:

- INSERT, UPDATE, DELETE, REPLACE, TRUNCATE, DROP, and ALTER;
- transactions, SELECT FOR UPDATE, LOCK TABLES, advisory locks, and row locks;
- ownership-value SELECTs;
- meta_value or post-content SELECTs;
- arbitrary options, posts, users, terms, termmeta, sitemeta, network-option, custom-table, or client-table reads; `$wpdb->blogs` reads are limited to Family C's `blog_id` column and predicate;
- any SQL supplied or parameterized by a client.

The runtime remains subject to ADR-003. This uninstall exception is not a generic database permission.

## Multisite traversal

Cleanup is per blog, never network-global. On multisite, use Family C's bounded `$wpdb->blogs` blog-ID keyset traversal as the authoritative site set:

1. Initialize last_seen_blog_id to 0 exactly once.
2. Fetch fixed-size Family C batches ordered by ascending blog_id until the terminal empty batch.
3. For each validated blog ID, call switch_to_blog( $blog_id ).
4. In a try block, run that blog's Pass 1 option deletion traversal, Pass 2 option final verification traversal, audit-meta Core deletion, and unconditional audit-meta SQL verification.
5. In finally, always call restore_current_blog(), including after a Throwable, query failure, or Core failure.
6. Advance the blog cursor monotonically. A query, validation, switch, processing, or restore failure leaves that blog unresolved and makes final cleanup INCOMPLETE; it must not be silently skipped.

After switching, option enumeration uses the switched site's $wpdb->options and audit verification uses the switched site's $wpdb->postmeta. Family C is the only direct SQL read against `$wpdb->blogs`; no direct SQL is used against sitemeta, network options, or a network-global ownership table. No unbounded all-site ID array is built. `get_sites()`/WP_Site_Query may be used only as non-authoritative convenience, if at all, and never to decide that all blogs were processed or that cleanup is COMPLETE.

Memory is bounded by fixed site batches and fixed per-site option batches. Total work is O(site count + owned rows). Very large networks may exceed one PHP request's execution budget, and network changes during traversal can leave cleanup incomplete. This limitation is documented rather than addressed with background jobs, persistent cleanup state, or a public API.

## Concurrent uninstall residuals

An already-running PHP or MCP request may write mutation state after uninstall has passed that site's cleanup and final verification. No global uninstall mutex and no new persistent runtime state are introduced.

One final bounded read-only verification pass is recommended because it catches ordinary deletion failures and rows observed during traversal. It does not make cleanup transactional and cannot close a race with a write that occurs afterward. Therefore:

- state visible and successfully removable during the uninstall execution is handled;
- a later concurrent write may remain;
- an exact-valid residual idempotency record remains authoritative after reinstall;
- no absolute zero-residual, exactly-once, or cross-process transaction guarantee is claimed.

## Privacy and data minimization

The cleanup removes only the three inventoried private WP-Auto state families. It never logs their values. No raw idempotency key, title, content, excerpt, request body, Application Password, Authorization header, credential, SQL, path, or stack trace is expected or emitted.

Unrelated options, metadata, posts, users, terms, and another plugin's data remain untouched. The fixed audit metadata key and dynamic option names are not exposed through an MCP tool or public response.

## WordPress deletion-flow limitation

WordPress 6.9's lifecycle is modeled as:

~~~text
delete_plugins()
  -> uninstall_plugin()
  -> include uninstall.php
  -> continue normal plugin-file deletion after return
~~~

ADR-004 creates no result object consumed by delete_plugins(). An INCOMPLETE classification does not automatically prevent plugin-file deletion. Aborting with wp_die() or another termination mechanism is not approved by this ADR and would require a separate lifecycle/UX decision.

This is consistent with the [WordPress Plugin Handbook uninstall guidance](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/): deactivation and uninstall are distinct lifecycle events, and uninstall is the deliberate point for removing plugin-owned entities. The policy remains WP-Auto's deliberate choice; WordPress does not mandate one universal deletion policy.

## WordPress.org compatibility

The decision uses Core deletion APIs, a fixed private-state inventory, no remote service, no credentials, no public endpoint, no remote code, and no broad wildcard deletion. It is designed to be reviewable under WordPress.org privacy and lifecycle expectations.

The read-only option enumeration is a narrow compatibility exception because Core lacks a prefix enumerator. The postmeta verification query is equally narrow and selects only meta_id. Large multisite cleanup may be resource-intensive, and incomplete cleanup is reported honestly. These are documented limitations, not hidden behavior or claims of a universal WordPress.org requirement.

## Rejected alternatives

- Delete every option beginning with wp_auto_connector_: rejected because future or similar state could be destroyed.
- Direct SQL DELETE: rejected because Core deletion APIs preserve authorization/cache semantics and SQL writes broaden the exception.
- Runtime registry/index: rejected because it adds persistent state that can be stale or non-atomic.
- Deactivation cleanup: rejected because it breaks active idempotency and recovery guarantees.
- TTL, takeover, activation cleanup, lifecycle epoch, or generation ID: rejected because they weaken surviving authority and duplicate-create safety.
- Background cleanup: rejected because it adds persistence, scheduling, and a new failure surface.
- Network-global SQL: rejected because state is per blog and direct network-table access is out of scope.
- OFFSET pagination while deleting: rejected because changing result sets can skip rows.
- Unbounded retry or blind second delete: rejected because failure cannot be distinguished safely and cleanup must fail closed.
- A WP-Auto return value, WP_Error, custom delete_plugins status, or automatic wp_die(): rejected because WordPress does not consume such a channel and lifecycle UX requires a separate decision.

## Governance alignment prerequisite

AGENTS.md currently describes the Phase 1.3 direct-SQL exception as ADR-003-scoped. ADR-004 does not silently invalidate that rule. Before uninstall cleanup implementation is authorized, AGENTS.md must receive a narrow governance alignment stating:

- runtime direct SQL remains ADR-003-only;
- explicit uninstall may additionally use only the three ADR-004-approved read-only SQL families: options enumeration/final verification, exact-key postmeta verification, and multisite blog-ID enumeration.

That alignment must not be combined with a broad GOV-1 status rewrite unless separately authorized. It is a prerequisite for implementation, not a change made by this ADR.

## Implementation and landing order

After this accepted ADR completes repository landing, the required order is:

1. land ADR-004 as a documentation-only change;
2. land the narrow AGENTS.md SQL-governance alignment;
3. run main CI and verify the exact baseline;
4. prepare an uninstall.php implementation candidate;
5. review implementation, security, and lifecycle behavior separately;
6. add deterministic uninstall tests and a disposable WordPress 6.9.x / PHP 8.1 / MariaDB probe;
7. complete the later Phase 1.3.3 seal work.

This ADR does not authorize steps 4–7.

## Validation requirements before implementation

Future deterministic tests and a live probe must prove:

- exact valid idempotency and audit-lock options are removed;
- malformed, uppercase, short, long, appended, similar, unrelated, trailing LF, trailing CRLF, embedded-newline, and leading-newline options remain;
- the exact option allowlist uses absolute PCRE anchors: `\Awp_auto_connector_idempotency_[0-9a-f]{64}\z` and `\Awp_auto_connector_mutation_audit_lock_[0-9a-f]{64}\z`, with case-sensitive no-newline-exception behavior;
- esc_like() precedes wildcard append and prepare() binds both patterns, cursor, and fixed limit;
- the options query is a current-blog prepared SELECT of only option_id and option_name;
- the postmeta query is a current-blog prepared SELECT of only meta_id for the exact key with limit one;
- Family C is a multisite-only current `$wpdb->blogs` prepared SELECT of only blog_id, with an internal keyset cursor, fixed limit, ascending order, and no OFFSET;
- no direct SQL write, ownership-value read, meta_value read, post-content read, arbitrary table read, lock, transaction, or advisory lock occurs;
- keyset batches advance monotonically without OFFSET, restart, skips, or unbounded memory;
- delete_option(false) uses bounded proof, no blind second delete, and no raw SQL fallback;
- get_option() is never used as authoritative option-absence proof, including when filters return the supplied default;
- delete_post_meta_by_key(false) with zero verification rows is PROVEN ABSENT;
- Core true short-circuited while a row remains is INCOMPLETE;
- actual metadata deletion followed by zero rows is PROVEN ABSENT;
- postmeta verification failure is INCOMPLETE with no second delete;
- persistent option cache remains coherent through Core operations;
- `sites_pre_query`, `pre_get_sites`, and `the_sites` cannot hide cleanup targets because get_sites()/WP_Site_Query is not the completeness authority;
- multiple Family C batches visit every blog ID exactly once; site addition/deletion during traversal, Family C query failure, invalid/non-monotonic IDs, and switch failure produce INCOMPLETE;
- multisite switch/restore is balanced on success and failure, with bounded memory and partial-failure reporting;
- `switch_to_blog()` is inside the guarded switch/restore control flow; if WordPress has pushed/switched context before a Throwable, `restore_current_blog()` still runs exactly when a switch context was established and is not skipped merely because the call did not return normally;
- a direct request to uninstall.php is blocked;
- WordPress deletion flow does not consume a custom cleanup result;
- an exact-valid idempotency record surviving incomplete cleanup remains authoritative after reinstall;
- a concurrent post-verification write is reported as a possible residual, not as transactional success.

The tests must also prove the filter and cache false-absence cases: with a real exact-valid option row present, a pre_option_{$option} or global pre_option filter returning the supplied default cannot produce PROVEN ABSENT, and stale/absence-like option cache state cannot hide the row from Pass 2. A false delete with a physically absent row is PROVEN ABSENT; a true/supporting delete with a residual row is INCOMPLETE. Per blog, Pass 1 runs at most once and Pass 2 runs at most once, with no self-restart, OFFSET, or unbounded loop.

A disposable WordPress 6.9.x / PHP 8.1 / MariaDB validation must cover representative single-site and multisite behavior, cache state, malformed rows, Core return values, and before/after snapshots. It must remove all temporary users, credentials, fixtures, containers, volumes, networks, and generated files.

## Public contract and phase impact

Public MCP delta: NONE.
Schema delta: NONE.
Tool delta: NONE.
Ability delta: NONE.
Mutation service error-set delta: NONE.
Request-time idempotency semantics delta: NONE.
Audit event schema delta: NONE.
Update concurrency delta: NONE.
Runtime endpoint, authentication, transport checks, allowlist, resources, and prompts: unchanged.
Phase 1.3.3 runtime status: unchanged and not sealed.

## Phase status and stop rule

At this docs-only landing stage:

- Phase 1.3.3: AUDIT FINAL REVIEW PASSED / GOV-1 POLICY APPROVED / GOV-2 POLICY A APPROVED / GOV-2 ARCHITECTURE APPROVED / ADR-004 SECURITY / NORMATIVE REVIEW APPROVED / DOCS-ONLY LANDING CANDIDATE PENDING REVIEW / NOT SEALED.
- Phase 1.3.4: BLOCKED.

If the state inventory, Core deletion proof, SQL boundary, multisite restoration, privacy behavior, residual-authority rule, or WordPress deletion-flow limitation cannot be demonstrated, the landing is BLOCKED. Do not implement uninstall.php, modify AGENTS.md, add tests, seal Phase 1.3.3, or start Phase 1.3.4 from this ADR.

## Scope and Git freeze

The docs-only landing candidate changes exactly these paths:

AGENTS.md
docs/ADR-004-UNINSTALL-PRIVATE-STATE-CLEANUP.md
docs/PHASE_1_3_3_UNINSTALL_CLEANUP_ARCHITECTURE.md

No production or test file, ADR-002, ADR-003, Mutation Contract, status document, Composer file, CI file, version metadata, uninstall.php, or current MCP runtime file may change. Do not stage, commit, push, create a PR, merge, tag, release, reset, clean, or rewrite history.

## Verdict

PASS — Phase 1.3.3 GOV-2 / ADR-004 docs-only landing candidate is ready for Landing Review Gate

Next task:

Phase 1.3.3 GOV-2 / ADR-004 Docs-Only Landing Review Gate
