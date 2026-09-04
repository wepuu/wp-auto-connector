# Phase 1.3.3 GOV-2 Uninstall Cleanup Architecture

APPROVED — Phase 1.3.3 GOV-2 Uninstall Cleanup Architecture
Final Consolidated Security / Normative Review: APPROVED

## 1. Purpose and review boundary

This document is the approved normative architecture for the Phase 1.3.3 GOV-2 uninstall-cleanup decision. It defines how WP-Auto Connector private mutation state would be removed when WordPress explicitly uninstalls the plugin. It does not authorize or implement runtime behavior.

Final Consolidated Security / Normative Review: APPROVED

This landing candidate contains no runtime implementation. There is no change to production PHP, tests, the current twelve-tool runtime, the Mutation Contract, ADR-002, ADR-003, Composer, CI, plugin version, or public MCP contracts.

## 2. Baseline and evidence

The design baseline is:

- MAIN_SHA: 96f0828c4d73968ea73054a6863d30739cef813d
- parent: 9ca1854bf2f9b7f5062aa72bdb9be96db5c137f9
- tree: d61ef56df0f966f1967eeed2408272575bc3c280
- origin/main is required to resolve to MAIN_SHA before implementation.
- The current runtime has twelve tools from the already sealed read and draft-update phases; uninstall cleanup exposes no tool.

The design review inspected the current uninstall gate, AtomicOwnershipStore, CreateIdempotencyStore, and MutationAuditStore, together with the frozen mutation contracts and ADRs. The WordPress Plugin Handbook distinguishes deactivation from uninstall and describes uninstall as the point at which a plugin may remove its options and database entities: https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/. The Core metadata API provides delete_post_meta_by_key() to remove all post-meta values for one fixed key: https://developer.wordpress.org/reference/functions/delete_post_meta_by_key/. WordPress 6.9 Core behavior and the locked MCP Adapter remain the implementation references; this candidate does not change the dependency.

If the baseline, state inventory, or Core behavior differs during implementation, stop and record inventory drift rather than silently widening cleanup.

## 3. Lifecycle policy: Policy A

Policy A is frozen for review:

- Deactivation retains every mutation-safety record.
- Explicit WordPress uninstall/delete performs bounded best-effort cleanup of WP-Auto-owned private state.
- Cleanup is an explicit plugin lifecycle reset, not a retry timeout, TTL, takeover, expiry, recovery shortcut, or normal request retry.
- No deactivation hook performs this cleanup.
- No public MCP, REST, admin-form, client, or remote request can invoke or parameterize it.
- No install epoch, generation ID, activation marker, or other bypass state is introduced.

The uninstall flow has two honest classifications:

- COMPLETE: every exact-valid idempotency option, audit-lock option, and audit metadata row is independently proven absent. On multisite, the authoritative blog-ID traversal has reached its terminal empty batch and every enumerated blog completed both option passes and audit-meta verification without an unresolved failure. A reinstall then begins without historical WP-Auto mutation authority because those authoritative records no longer exist.
- INCOMPLETE: any query, Core deletion, verification, resource, concurrent, or multisite traversal uncertainty remains. WordPress continues its normal plugin-file deletion flow; this architecture does not invent a WP-Auto return channel, WP_Error, custom delete_plugins status, MCP response, or abort behavior. Any residual exact-valid idempotency record remains authoritative after reinstall under the existing runtime. It is not ignored, expired, taken over, repaired, deleted during activation, or bypassed because uninstall was attempted.

The semantic delta to the Mutation Contract is NONE. Active-runtime guarantees remain unchanged: successful cleanup removes records that no longer exist, while partial cleanup does not weaken surviving authoritative records.

## 4. Exact persistent state inventory

The only removable persistent families currently owned by WP-Auto are:

1. Persistent Create idempotency/recovery options, each named:
   wp_auto_connector_idempotency_[0-9a-f]{64}
   These are private, non-autoloaded site options. The hash is dynamic; the raw idempotency key is not stored in the option name.
2. Temporary mutation-audit lock options, each named:
   wp_auto_connector_mutation_audit_lock_[0-9a-f]{64}
   These are private atomic ownership rows and may be orphaned by a process interruption.
3. Post meta with the exact key:
   _wp_auto_connector_mutation_audit
   Each object retains at most the most recent twenty local attribution events. This metadata is not idempotency authority.

The inventory is closed. A future implementation must first enumerate source symbols and storage writes and compare them with this list. Any additional persistent family, including a similar-looking prefix, is inventory drift and blocks implementation. Temporary in-memory guard tokens and query markers are not persistent cleanup targets.

## 5. Normative database boundary and proposed ADR-004

Runtime SQL remains unchanged. ADR-003 continues to govern the atomic-ownership runtime and its direct SQL. The proposed uninstall design introduces exactly three narrowly scoped, uninstall-only read exceptions: option-name enumeration/final verification on the current site's options table, fixed-key audit-meta absence verification on the current site's postmeta table, and physical blog-ID enumeration on the trusted blogs table for multisite only.

A supplemental document, ADR-004, is included in this landing candidate. It records the same uninstall-only lifecycle/database boundary and does not authorize runtime implementation:

docs/ADR-004-UNINSTALL-PRIVATE-STATE-CLEANUP.md

Its proposed decision is:

### Context

WordPress does not provide a Core API for enumerating arbitrary option names by a private, dynamic prefix. Explicit uninstall nevertheless needs to find WP-Auto-owned idempotency and audit-lock rows without trusting a client-controlled name. Cleanup must therefore use a bounded, read-only enumeration query and Core APIs for every deletion.

### Decision

- Keep defined('WP_UNINSTALL_PLUGIN') as the entry gate.
- Enumerate only the current blog's options table through a prepared, read-only SELECT. The only allowed selected columns are option_id and option_name. The query uses keyset pagination (option_id > last_seen_option_id), ascending option_id, a fixed batch size, and prepared LIKE patterns for the two fixed prefixes.
- Verify audit-meta absence only through a second prepared, read-only SELECT on the current blog's postmeta table: select meta_id where meta_key = the exact fixed key, order by meta_id ascending, limit 1. Bind `_wp_auto_connector_mutation_audit` through `$wpdb->prepare()`; select neither meta_value nor post content.
- On multisite only, enumerate physical blog IDs through a third prepared, read-only SELECT on the trusted `$wpdb->blogs` table: select blog_id where blog_id > an internal cursor, order by blog_id ascending, limit a fixed batch. This is the authoritative site set for COMPLETE classification; `get_sites()`/WP_Site_Query are non-authoritative convenience only.
- Validate every returned name against the exact lowercase hexadecimal allowlist before any deletion.
- Delete option rows only by calling delete_option($option_name).
- Delete audit metadata only by calling delete_post_meta_by_key('_wp_auto_connector_mutation_audit').
- No other direct SQL is allowed. In particular there is no raw SQL write (INSERT, UPDATE, DELETE, REPLACE, TRUNCATE, DROP, ALTER), transaction, row lock, advisory lock, arbitrary table read, ownership-value SELECT, meta_value SELECT, post-content SELECT, or blog-table column read other than Family C's blog_id. There is no SQL lookup for any key other than the exact audit key.
- This exception is uninstall-only and cannot broaden ADR-003 or any runtime mutation service.

The proposed ADR must also preserve the LIKE-escaping, deletion classification, multisite, concurrency, privacy, and failure rules in the following sections. ADR-002 delta is NONE; ADR-003 delta is NONE; Mutation Contract delta is NONE. ADR-004 is a new lifecycle/database-boundary decision and is required before implementation.

## 6. Safe LIKE construction

The fixed raw prefixes are constants:

- wp_auto_connector_idempotency_
- wp_auto_connector_mutation_audit_lock_

For each prefix the implementation must perform this exact conceptual sequence:

1. Keep the raw fixed prefix.
2. Call $wpdb->esc_like($prefix).
3. Append the % wildcard to the escaped value.
4. Bind the resulting pattern through $wpdb->prepare().

The prefix is never interpolated into SQL, and % is never added before esc_like(). The table identifier comes only from the trusted $wpdb->options property. A client cannot influence a prefix, table, column, predicate, pattern, batch size, or regex.

## 7. Exact post-enumeration allowlist

A row is deletable only when its complete option_name matches one of these exact PCRE expressions:

 - \Awp_auto_connector_idempotency_[0-9a-f]{64}\z
 - \Awp_auto_connector_mutation_audit_lock_[0-9a-f]{64}\z

The match is case-sensitive, uses absolute start/end anchors, has no multiline interpretation or final-newline exception, and is anchored to the complete string. Malformed names, uppercase hexadecimal, 63/65-character hashes, suffixes, embedded separators, leading or embedded newlines, trailing LF, trailing CRLF, different prefixes, and similar options are retained. No normalization, strtolower(), truncation, wildcard-only acceptance, or best-effort repair is allowed. A row that matches a LIKE prefix but fails the exact expression is skipped and remains untouched.

## 8. Bounded keyset option enumeration

Only the current blog's options table is enumerated. The conceptual query is:

~~~sql
SELECT option_id, option_name
FROM <trusted current $wpdb->options>
WHERE option_id > <last_seen_option_id>
  AND (option_name LIKE <prepared idempotency pattern>
       OR option_name LIKE <prepared audit-lock pattern>)
ORDER BY option_id ASC
LIMIT <fixed batch size>
~~~

The production implementation may use the project's normal WordPress database style, but must preserve this shape. The cursor and fixed batch size are bound as integer parameters (for example, `%d`) through `$wpdb->prepare()`; they are never interpolated from client input:

- option_id is the internal integer cursor.
- The cursor starts below the first row and advances monotonically to the last returned option_id.
- A fixed, reviewed batch size bounds memory and per-query work.
- There is no OFFSET, restart-from-zero loop, unbounded get_results(), or client-selected limit.
- The extra row is not deleted merely because it was returned; each candidate still passes exact validation and Core deletion.
- A query failure or non-monotonic/invalid cursor is unresolved and prevents a complete-cleanup claim.
- Rows added concurrently after the cursor passes can remain; the residual rule is in Section 12.

This query is a read-only enumeration exception for uninstall. It does not read ownership values to classify runtime acquisition and does not replace AtomicOwnershipStore.

## 9. Option deletion and failure semantics

For each exact-valid option name returned by Pass 1, call delete_option($option_name). Do not issue SQL deletion and do not automatically retry.

delete_option() returning true or false is supporting evidence only. get_option() is not authoritative for uninstall absence because pre_option_{$option} and pre_option filters can short-circuit the logical read before physical retrieval and can return the supplied default. Do not use an object or scalar sentinel, temporarily remove filters, or manipulate caches to make a Core logical read authoritative.

The only authoritative absence proof for dynamic WP-Auto options is Pass 2's final read-only SQL verification using this same SQL family. Pass 2 starts a new cursor at zero exactly once after Pass 1 and never deletes, retries, repairs, takes over, normalizes, or self-restarts. No additional SQL family is added. No exact-valid rows means OPTION STATE = PROVEN ABSENT; a residual exact-valid row, query failure, invalid result, or non-monotonic cursor means OPTION STATE = RESIDUAL or UNRESOLVED and cleanup is INCOMPLETE. A malformed prefix-like row is preserved and does not by itself make cleanup incomplete.

Each pass initializes its own cursor exactly once. Neither pass may restart itself from zero. The complete uninstall option workflow performs at most one deletion traversal and one final verification traversal per blog. There is no blind second delete, raw SQL fallback, unbounded retry, TTL, takeover, or forced cleanup. The uninstall entry point may leave state behind, but it must never claim complete removal when proof is unavailable.

## 10. Audit metadata cleanup and independent absence proof

For the active blog, call exactly:

delete_post_meta_by_key('_wp_auto_connector_mutation_audit')

This remains the only deletion mechanism. It delegates row and cache handling to Core and removes all matching values across posts, including multiple values on one post, while leaving unrelated keys untouched. Direct SQL deletion against posts or postmeta is forbidden.

The boolean return is supporting evidence only. Core can return false when no matching rows existed or when deletion did not complete, and metadata deletion can be short-circuited by filters. Therefore verification is unconditional: after every call, perform one bounded, read-only exact-key query against the switched blog's `$wpdb->postmeta`:

~~~sql
SELECT meta_id
FROM <trusted current $wpdb->postmeta>
WHERE meta_key = <prepared exact '_wp_auto_connector_mutation_audit'>
ORDER BY meta_id ASC
LIMIT 1
~~~

The query selects only `meta_id`, binds the exact key with `$wpdb->prepare()`, and uses a fixed limit of one. It must not select `meta_value`, post content, or any other key. Classification is:

- no row: PROVEN ABSENT, regardless of whether Core returned true or false;
- a row: CLEANUP INCOMPLETE; do not issue a second delete;
- query failure, invalid result, or a thrown exception: UNRESOLVED / CLEANUP INCOMPLETE; do not issue a second delete.

The verification query is Family B, the second of exactly three uninstall-only direct-SQL read families, and is not a deletion authority. Its table is always the current blog's table after `switch_to_blog()`. No automatic retry is allowed.

## 11. Multisite traversal

On multisite, cleanup covers each site, not network-global state. Family C is the authoritative site-set query:

~~~sql
SELECT blog_id
FROM <trusted $wpdb->blogs>
WHERE blog_id > %d
ORDER BY blog_id ASC
LIMIT %d
~~~

Family C is allowed only when `is_multisite()` is true. Start `last_seen_blog_id` at 0 exactly once and fetch fixed-size prepared batches until an empty batch marks the terminal verification point. Select only `blog_id`, bind the internal cursor and fixed limit as integer parameters, validate each returned ID as an integer at least 1, and advance monotonically. No OFFSET, unbounded site-ID array, client-selected value, or selection of domain, path, registration, flags, site metadata, sitemeta, or network options is allowed.

For each blog ID call `switch_to_blog($blog_id)`. In a try block run that blog's option Pass 1 deletion traversal, option Pass 2 final verification traversal, audit-meta Core deletion, and unconditional audit-meta SQL verification. In finally always call `restore_current_blog()`, including after a Throwable, query failure, switch failure, or Core failure. A failure leaves the blog unresolved and makes overall cleanup INCOMPLETE; it must not be silently skipped.

After switching, option enumeration and verification use the switched site's `$wpdb->options`, and audit verification uses the switched site's `$wpdb->postmeta`. Family C is the only direct SQL read against `$wpdb->blogs`; no direct SQL is used against sitemeta, network options, or a network-global ownership table. `get_sites()`/`WP_Site_Query` are non-authoritative convenience only and never decide that all blogs were processed or that cleanup is COMPLETE. On a single-site install, exactly the current blog is processed.

Each Family C traversal initializes its cursor once and never restarts itself. A newly created site with a higher blog ID is included if it appears before the terminal empty batch; a site added afterward is a concurrent lifecycle residual. A site removed during traversal may make switching or processing fail and therefore produces INCOMPLETE. Memory is bounded by fixed site and per-site option batches; total work is O(site count + owned rows), and very large networks may exceed one request's execution budget.

On a single-site install, exactly the current blog is processed.

## 12. Concurrent uninstall residuals

A request that is already executing can write mutation state after uninstall cleanup has visited the affected site. There is no safe global uninstall mutex and no new persistent runtime state in this architecture. Therefore the guarantee is:

- all owned state visible and removable during the bounded uninstall traversal is handled according to the rules above;
- a concurrent request may leave a residual option or audit event after the final check;
- cleanup does not claim an absolute zero-residual guarantee across concurrent PHP processes.

One final read-only verification pass is recommended because it catches ordinary deletion failures and rows observed during traversal. It does not close the race with a request that writes after that pass, so it is not a transaction, lock, retry loop, takeover, or exactly-once promise. If that race leaves an exact-valid idempotency record, the record remains authoritative after reinstall; only successfully removed authority is reset.

## 13. Access, security, and privacy boundaries

The cleanup is reachable only through the WordPress uninstall mechanism guarded by defined('WP_UNINSTALL_PLUGIN'). There are no HTTP, REST, MCP, admin-form, CLI, or client parameters. The user cannot supply names, prefixes, patterns, table names, columns, cursors, limits, regular expressions, site IDs, or deletion operations.

Stored data is already constrained by the active mutation contracts:

- idempotency records contain version, actor, Ability, fingerprint, state, target ID, and GMT timestamps;
- audit records contain version, operation/Ability, actor, target, timestamp, Create fingerprint, and Update expected/result modified_gmt;
- raw idempotency keys, titles, content, excerpts, request bodies, credentials, SQL, and stack traces are not stored.

Uninstall removes only these private WP-Auto-owned families. It does not remove WordPress core data, user data, arbitrary options, unrelated metadata, or another plugin's state.

## 14. WordPress.org compatibility

This design follows the Handbook's lifecycle distinction: deactivation preserves settings/state, while explicit uninstall is the user-controlled point for deleting plugin-owned entities. The narrow option inventory, fixed meta key, no external service, no remote code, no credentials, no public endpoint, and Core deletion APIs support reviewer expectations for a GPL WordPress.org plugin.

The design explicitly documents the practical trade-offs: read-only SQL enumeration is needed because Core has no prefix enumerator; multisite traversal can be time-consuming; cleanup may be partial under concurrent requests or resource limits; and no claim of universal WordPress.org mandate is made. Before implementation, the exact SQL shape, all allowlist rules, privacy behavior, and large-network limitation must be included in the reviewer-facing ADR and validation evidence.

## 15. Rejected alternatives

- Delete every option that begins with wp_auto_connector_: rejected because similar/future state would be destroyed and because a broad wildcard is not a contract.
- A registry option or transient of owned names: rejected because it adds persistent runtime state and can itself become stale or non-atomic.
- Deactivation cleanup: rejected because it breaks the active idempotency and recovery contract.
- Direct SQL DELETE for convenience: rejected because deletion must use Core APIs and it would broaden the runtime/database exception.
- TTL, takeover, retries, or background cleanup: rejected because lifecycle reset is not an ownership failure and would create new mutation semantics.
- Network-global direct SQL: rejected because per-site Core lifecycle traversal is safer and reviewable.
- get_sites()/WP_Site_Query as the completeness authority: rejected because filters and short-circuiting can hide or alter the physical blog set.
- OFFSET pagination for blog traversal: rejected because positional pages can skip a blog when the set changes; Family C uses the monotonic blog_id keyset instead.

## 16. Forecast implementation files (not created here)

A later implementation review may require only:

- uninstall.php for the guarded, bounded lifecycle orchestration;
- tests/UninstallTest.php and, if needed, tests/bootstrap.php for deterministic Core/database/cache stubs;
- docs/ADR-004-UNINSTALL-PRIVATE-STATE-CLEANUP.md for the normative supplemental decision.

Seal/current-status documents and any implementation-specific helper files require separate scope approval. No file in this forecast is changed by this task.

## 17. Mandatory future test and live-probe matrix

Before implementation can be approved, tests must prove:

- exact valid option rows are removed;
- unrelated options and malformed/uppercase/63/65-character/appended/embedded-separator/similar names remain;
- valid-looking names with trailing LF, trailing CRLF, embedded newline, or leading newline remain because the allowlist uses absolute `\A`/`\z` anchors;
- only prepared current-options SELECT is used for enumeration;
- $wpdb->esc_like() precedes wildcard append and $wpdb->prepare() binds both patterns and cursor/limit;
- no SQL DELETE, UPDATE, INSERT, REPLACE, TRUNCATE, DROP, ALTER, transaction, row lock, or advisory lock occurs;
- delete_option(false) distinguishes absent from unresolved without a second delete;
- multiple fixed-size batches advance by option_id with no skip or restart;
- persistent options cache (notoptions/alloptions) is invalidated by Core and readback is respected;
- all values of the audit key are removed by delete_post_meta_by_key, unrelated meta remains, and Core cache behavior is preserved;
- WordPress plugin deletion flow is modeled accurately: uninstall.php has no WP-Auto result channel consumed by delete_plugins(), and no custom WP_Error/status is assumed;
- an exact-valid idempotency record that survives an incomplete uninstall remains authoritative after reinstall and is processed by normal persisted-state logic without duplicate creation;
- delete_post_meta_by_key() returning false with zero verification rows is PROVEN ABSENT;
- a Core true result that is short-circuited while a row remains is CLEANUP INCOMPLETE;
- an actual metadata delete followed by zero verification rows is PROVEN ABSENT;
- metadata verification failure is CLEANUP INCOMPLETE with no second delete;
- plugin direct SQL is limited exactly to three read-only families: options-name enumeration/final verification, exact-key postmeta verification, and multisite blog-ID enumeration;
- `sites_pre_query`, `pre_get_sites`, and `the_sites` changes cannot hide cleanup targets because Family C reads physical blog IDs directly;
- multiple Family C batches visit every blog ID exactly once, with no OFFSET or self-restart;
- site addition/deletion during traversal, Family C query failure, invalid/non-monotonic blog IDs, and switch failure produce INCOMPLETE;
- multisite site batching switches and always restores blogs, bounds memory, and records partial failure;
- `switch_to_blog()` remains inside the guarded switch/restore control flow; if WordPress has pushed/switched context before a Throwable, `restore_current_blog()` still runs exactly when a switch context was established and is not skipped merely because the call did not return normally;
- a direct request to uninstall.php is blocked;
- a concurrent post-cleanup write is reported as a possible residual rather than overstated as zero.

A disposable WordPress 6.9.x / PHP 8.1 / MariaDB probe must exercise single-site and representative multisite cleanup, cache behavior, malformed rows, Core return values, and state snapshots. It must not retain users, credentials, fixtures, containers, or generated files.

## 18. Public and normative deltas

- Public MCP tools: NONE.
- Ability contracts: NONE.
- Runtime endpoint, authentication, transport checks, allowlist, schemas, and annotations: NONE.
- Mutation Contract delta: NONE.
- ADR-002 delta: NONE.
- ADR-003 delta: NONE.
- New ADR-004: REQUIRED before implementation, limited to exactly three uninstall-only read-only SQL families: options enumeration/final verification, exact-key postmeta verification, and multisite blog-ID enumeration.
- Phase 1.3.3 runtime status: unchanged; this document is architecture-only and does not seal the phase.

## 19. Phase status and stop rule

After this candidate is reviewed, the intended status is:

- Phase 1.3.3: AUDIT FINAL REVIEW PASSED / GOV-1 APPROVED / GOV-2 POLICY A APPROVED / GOV-2 ARCHITECTURE APPROVED / DOCS-ONLY LANDING CANDIDATE PENDING REVIEW / NOT SEALED.
- Phase 1.3.4: BLOCKED.

If inventory, authorization, privacy, SQL shape, deletion proof, multisite restoration, lifecycle semantics, authoritative blog enumeration, or the WordPress deletion-flow boundary cannot be demonstrated, the outcome is BLOCKED — remediation required. Do not proceed to uninstall.php, tests, ADR-004 landing, phase seal, or Phase 1.3.4.

## 20. Scope and Git freeze

This landing candidate must leave the repository index untouched and contain no staged files. The expected changes are limited to the explicitly approved architecture document, ADR-004, and the narrow AGENTS.md governance alignment:

docs/PHASE_1_3_3_UNINSTALL_CLEANUP_ARCHITECTURE.md
docs/ADR-004-UNINSTALL-PRIVATE-STATE-CLEANUP.md
AGENTS.md

Required checks are:

~~~text
git status --short --branch
git diff --check
git diff --name-only
git diff
~~~

Protected paths (uninstall.php, src/, tests/, README.md, readme.txt, docs/ROADMAP.md, docs/MCP_TOOL_CATALOG.md, ADR-002, ADR-003, the Mutation Contract, Composer, CI, version metadata) must have no diff. No commit, push, pull request, merge, tag, release, staging, reset, clean, or history rewrite is authorized.

## 21. Verdict

APPROVED — Phase 1.3.3 GOV-2 Uninstall Cleanup Architecture

Final Consolidated Security / Normative Review:
APPROVED

Next task:

Phase 1.3.3 GOV-2 / ADR-004 Docs-Only Landing Candidate Review Gate
