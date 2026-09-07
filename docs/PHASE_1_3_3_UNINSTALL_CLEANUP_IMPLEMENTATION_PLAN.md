# Phase 1.3.3 Uninstall Cleanup Implementation Plan

Status: IMPLEMENTED AND VALIDATED — PENDING GIT LANDING

This document governed the implementation of the uninstall-only private-state cleanup
approved by ADR-004. The implementation and validation evidence are recorded in
`PHASE_1_3_3_UNINSTALL_CLEANUP_VALIDATION.md`. The public MCP surface remains unchanged.

## A. Baseline

Planning was performed from a fresh worktree created from the approved remote baseline.

- origin/main: 95091d732be7bf08d963e2cb2ae0635673652502
- parent: 96f0828c4d73968ea73054a6863d30739cef813d
- tree: 2625f3d15272758cf0e73c5c499a72d7f26885ba
- subject: docs: accept uninstall cleanup boundary
- main CI: PHP Quality, run 33843200111, push event, completed/success
- planning branch: chore/phase-1-3-3-uninstall-implementation-plan
- planning worktree: D:\Codex\wp-auto-connector-phase-1-3-3-uninstall-implementation-plan

The source worktree was already user-owned and dirty; it was not changed, cleaned,
stashed, reset, or used for this plan. The planning worktree is based exactly on
origin/main.

## B. Evidence and Core boundary

The following WordPress 6.9 Core behavior was inspected against the exact upstream
sources:

- uninstall_plugin() defines WP_UNINSTALL_PLUGIN, includes the plugin uninstall
  file, and has no plugin-defined completion result channel.
- delete_plugins() invokes uninstall and then continues its own plugin-file cleanup;
  an uninstall script cannot safely turn a private incomplete result into a custom
  deletion veto.
- delete_option() owns normal option-cache coherence and returns a boolean supporting
  result. A false result alone is not authoritative failure when the physical row is
  subsequently proven absent.
- delete_post_meta_by_key() delegates to Core metadata deletion.
- wpdb::esc_like() must run before appending %. wpdb::prepare() is the only query
  construction boundary.
- wpdb::get_results( ..., ARRAY_A ) may return an array, null, or a false-like result;
  rows must be validated. get_var() is not used because it collapses useful state.
- wpdb::suppress_errors() returns prior state and must be restored in finally.
- switch_to_blog() and restore_current_blog() mutate the switched stack and global
  database context; hooks can throw after context has been partially established.

Official source references:

- https://raw.githubusercontent.com/WordPress/wordpress-develop/6.9/src/wp-admin/includes/plugin.php
- https://raw.githubusercontent.com/WordPress/wordpress-develop/6.9/src/wp-includes/option.php
- https://raw.githubusercontent.com/WordPress/wordpress-develop/6.9/src/wp-includes/post.php
- https://raw.githubusercontent.com/WordPress/wordpress-develop/6.9/src/wp-includes/ms-blogs.php
- https://raw.githubusercontent.com/WordPress/wordpress-develop/6.9/src/wp-includes/class-wpdb.php

The plan relies on documented Core APIs for deletion and blog switching. Direct
inspection of _wp_switched_stack, switched, $wpdb table fields and
$table_prefix is limited to failure-recovery proof; it is not treated as a new
public API or as permission to perform manual context surgery.

## C. Persistence inventory

A repository-wide audit of persistent writes found exactly these WP-Auto state families:

1. wp_auto_connector_idempotency_[0-9a-f]{64} rows in the current blog's options
   table, written by CreateIdempotencyStore.
2. wp_auto_connector_mutation_audit_lock_[0-9a-f]{64} rows in the current blog's
   options table, written by the audit critical-section lock.
3. _wp_auto_connector_mutation_audit post metadata, written by MutationAuditStore.

No WP-Auto transients, cron events, site/network options, user meta, term meta,
custom tables, filesystem state, external service state, or other persistent family
was found. Core's update_term_meta_cache=false is a read flag, not a WP-Auto
write. If implementation discovers a fourth family, it must stop and report
inventory drift rather than widen cleanup.

Current runtime stores remain authoritative during requests. Uninstall cleanup must
not reuse ADR-003 runtime SQL write methods as a shortcut.

## D. Current uninstall state and implementation shape

uninstall.php currently contains only the WP_UNINSTALL_PLUGIN direct-entry gate and
does not boot the normal plugin. The repository has no Composer PSR-4 autoload that
an uninstall script may safely assume.

Two shapes were considered.

### Option A — monolithic uninstall.php

Advantages are a single file and no explicit include. Disadvantages are poor unit
testability, a large global-scope file with collision risk, difficult PHPCS review,
interleaving of three SQL families with multisite recovery, and a tempting
dependency on normal bootstrap globals. This shape makes failure isolation and
independent review harder.

### Option B — thin uninstall.php plus one dedicated uninstall-only helper

uninstall.php remains a gate and deterministic relative require_once; the helper
owns all cleanup, query validation, switching, and the internal completion value.
The helper can be instantiated directly by tests without making uninstall.php a
public API. Its namespace isolates symbols and keeps the three SQL families visible.
It is never loaded by wp-auto-connector.php, Plugin, MCP Adapter, Abilities, or
normal plugin bootstrap.

Selected shape: thin uninstall.php plus dedicated uninstall-only helper.

This is the smallest shape that is independently testable, reviewable under PHPCS,
safe when Composer is unavailable, and able to isolate multisite context recovery.
No generic DatabaseService, repository framework, or runtime refactor is proposed.

## E. Proposed implementation scope

The later implementation task should change only these paths:

- uninstall.php
- src/Uninstall/PrivateStateCleanup.php
- tests/UninstallTest.php
- tests/bootstrap.php

src/Uninstall/PrivateStateCleanup.php is final, namespace
WPAuto\Connector\Uninstall, uninstall-specific, and not registered or booted at
runtime. No other production file is expected. In particular, do not modify
wp-auto-connector.php, Plugin.php, ContentMutationService.php,
AtomicOwnershipStore.php, CreateIdempotencyStore.php, MutationAuditStore.php,
Composer files, CI, version metadata, ADRs, or contracts.

## F. Entry point and internal completion model

The future entry point is conceptually:

    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
    }

    require_once __DIR__ . '/src/Uninstall/PrivateStateCleanup.php';
    ( new WPAuto\Connector\Uninstall\PrivateStateCleanup() )->run();

The gate must execute before the require, SQL, Core deletion, switching, output, or
logging. With the gate defined, the script performs cleanup and returns normally to
WordPress. It must not echo, print, dump, call wp_die, throw an uncaught exception,
return WP_Error, or create an MCP/admin result channel.

PrivateStateCleanup::run(): bool is the exact internal result shape:

- true means all approved families were proven absent and all required context
  restoration completed (COMPLETE);
- false means any approved condition remained unresolved (INCOMPLETE).

The uninstall entry point intentionally ignores this boolean. It exists only for
control flow, deterministic tests, and validation evidence; it is not a public API,
MCP output, REST output, admin output, log payload, or delete_plugins() contract.

The outer entry point catches unexpected Throwable so no private state, SQL, path,
or stack information escapes. Cleanup records a sticky internal false before
returning. A catch must never conceal an unrestored multisite context: restoration
runs in the helper finally path before the result is returned.

## G. Sticky completion and failure matrix

The helper starts each run with complete=true. Once an approved unresolved
condition sets it to false, it never becomes true again.

Sticky INCOMPLETE conditions are:

- prepare() or query Throwable, false/null result, non-empty last_error, or
  malformed/non-monotonic result rows;
- an invalid positive database identifier or cursor progress failure;
- a residual exact-valid option row in Family A Pass 2;
- delete_option() Throwable (whether before or after a possible delete);
- Family B verification failure, residual audit metadata, or delete Throwable;
- switch/restore Throwable or any context state that cannot be proven restored;
- a skipped/unprocessed multisite blog, a non-terminal Family C enumeration, or a
  top-level unexpected Throwable;
- an unexpected row shape/count or any inability to establish physical absence.

delete_option() === false by itself is supporting evidence only. If the subsequent
physical Pass 2 proves no exact-valid row remains, the result is complete for that
row. If a residual row remains, or verification is unresolved, the sticky result is
incomplete. A delete Throwable remains sticky even when a later read happens to
appear empty; no second delete is attempted.

Family B uses the same conservative rule: true or false plus a verified zero-row
result is complete; true or false plus a residual row is incomplete; a Throwable or
verification failure is unresolved even if no row is observed. The implementation
never clears a sticky false after later success.

No SQL error text, Core exception text, option name/value, meta value, stack trace,
credential, or path is emitted or logged.

## H. Narrow read-query boundary

All direct SQL is confined to three obvious read families (A options, B audit
metadata, C multisite blogs). One private helper, conceptually
read_rows(prepared_sql, columns, limit), is the only execution boundary. It:

1. calls wpdb->prepare() before execution and treats a Throwable or non-string
   result as unresolved;
2. saves the current error-suppression state, enables suppression only for this
   read, calls get_results(prepared_sql, ARRAY_A), and restores suppression in
   finally;
3. treats Throwable, null/false-like result, a non-array result, or non-empty
   wpdb->last_error as unresolved;
4. validates every row is an array with exactly the selected columns and validates
   the expected row shape/count; no extra columns are accepted;
5. returns rows only after all validation succeeds.

The helper never logs wpdb->last_error, never uses get_var(), never emits SQL,
and never treats suppression as success. prepare() receives only fixed table names,
fixed literals, keyset cursor values, fixed limits, and escaped fixed
prefixes/keys. There is no client input.

esc_like() is applied to each raw fixed prefix before appending %. The resulting
patterns are passed through prepare(). All table identifiers come from trusted
Core wpdb properties (options, postmeta, blogs) and are never constructed from
request data.

## I. Scalar database-ID normalization

A private normalizer accepts only canonical positive database integers:

- an integer 1 .. PHP_INT_MAX, or a string matching \A[1-9][0-9]*\z;
- strings are length-checked and compared lexically to the decimal PHP_INT_MAX
  before conversion;
- after conversion, the value must be positive and represent the exact original
  decimal form (no leading zeroes).

It rejects zero, negatives, plus signs, whitespace/newlines, floats, scientific
notation, booleans, arrays/objects, non-digits, overflow, and values that cannot be
represented safely. Every keyset result must be strictly greater than the prior
cursor; duplicates, out-of-order IDs, and non-monotonic batches are unresolved.
No blind (int) cast is used.

## J. Family A — options ownership rows

The fixed conceptual query is:

    SELECT option_id, option_name
    FROM <trusted current $wpdb->options>
    WHERE option_id > %d
      AND ( option_name LIKE %s OR option_name LIKE %s )
    ORDER BY option_id ASC
    LIMIT %d

Only option_id and option_name are selected. Raw prefixes are exactly
wp_auto_connector_idempotency_ and wp_auto_connector_mutation_audit_lock_; the
construction sequence is raw prefix, esc_like(), append %, then prepare().

Exact allowlists are case-sensitive:

- \Awp_auto_connector_idempotency_[0-9a-f]{64}\z
- \Awp_auto_connector_mutation_audit_lock_[0-9a-f]{64}\z

Prefix-like malformed names (uppercase hash, 63/65 characters, appended suffix,
leading/embedded newline, LF, CRLF, or other non-exact forms) are skipped,
preserved, and advance the cursor; they do not by themselves make cleanup
incomplete.

### Pass 1

For each processed blog the cleanup starts cursor=0 exactly once. It reads fixed
batches, validates shape and strictly increasing IDs, advances the keyset cursor,
then calls delete_option(name) exactly once for each exact-valid name. It never
uses OFFSET, restarts a cursor, retries a batch, issues SQL writes, calls get_option
for absence authority, or manually edits option caches.

A normal true return is supporting evidence. A false return is resolved only by Pass
2 physical proof. A Throwable is sticky unresolved and no second delete is attempted.
If a batch cannot be read or validated, the family stops without claiming absence.

### Pass 2

After Pass 1, a fresh verification cursor=0 is initialized exactly once. Pass 2
uses the same read-only query and never deletes, repairs, normalizes, retries, or
restarts. An empty set of exact-valid rows proves physical absence. Any exact-valid
row is residual and makes the run incomplete. Query, row, or cursor failure is
unresolved/incomplete. SQL verification, not get_option() or cache state, is the
absence authority.

## K. Fixed batch constants

The helper will use fixed, non-configurable constants:

- OPTION_BATCH_SIZE = 100
- SITE_BATCH_SIZE = 50
- MAX_RESTORE_ATTEMPTS = 16

One hundred option rows bounds memory while limiting normal large-site scans to
reviewable batches. Fifty blog IDs limits per-batch state while keeping multisite
enumeration query counts predictable. Sixteen bounded restore attempts prevents a
malformed hook from creating an unbounded uninstall loop and is well above normal
nested-switch depth. No administrator or client can change these values.

## L. Family B — audit metadata

For each processed blog, call exactly once:

    delete_post_meta_by_key( '_wp_auto_connector_mutation_audit' );

Always verify physically with the fixed query:

    SELECT meta_id
    FROM <trusted current $wpdb->postmeta>
    WHERE meta_key = %s
    ORDER BY meta_id ASC
    LIMIT 1

Only meta_id is selected and the exact key is bound. meta_value, post content,
and arbitrary meta are never read. A true or false Core return plus a verified
zero-row result is complete; either return plus a residual row is incomplete. A
Core Throwable, verification query failure, malformed row, or inability to establish
zero rows is unresolved/incomplete. There is no second delete, retry, or SQL delete.
Unrelated metadata remains untouched.

## L1. Cache and helper isolation

Actual option deletion remains `delete_option( $name )`; Core owns normal option
cache coherence. The helper never manually edits `alloptions`, `notoptions`, or an
individual option cache, and cache state is never used as physical-absence proof.
Audit deletion remains `delete_post_meta_by_key()` followed by the Family B SQL
verification. No direct SQL write, SQL DELETE, SQL UPDATE, or cache surgery is
introduced.

The dedicated helper is loaded only by uninstall.php through a deterministic
plugin-relative path. It is not included by wp-auto-connector.php, Plugin::boot(),
the MCP Adapter, an Ability, or any normal request bootstrap. Tests instantiate the
helper directly with the existing WordPress test seams; the entry-point gate is
tested separately in an isolated PHP subprocess.

## M. Family C — authoritative multisite enumeration

Family C runs only when is_multisite() === true. On single-site, it is never
queried and the current blog is processed once without switching.

The fixed query is:

    SELECT blog_id
    FROM <trusted $wpdb->blogs>
    WHERE blog_id > %d
    ORDER BY blog_id ASC
    LIMIT %d

Only blog_id is selected. Enumeration starts at cursor=0 exactly once, uses
SITE_BATCH_SIZE, validates strictly increasing positive IDs, and requires a
terminal empty batch before COMPLETE is possible. It does not use OFFSET,
get_sites(), WP_Site_Query completeness, domains/paths/flags, sitemeta, or
network-option reads.

Each canonical blog is processed once. A blog added before the terminal batch is
observed by a later keyset batch; a blog removed before processing becomes a Core
switch/processing outcome that must be classified conservatively. Invalid,
duplicate, out-of-order IDs or a query failure are unresolved. A residual or
unprocessed blog prevents completion.

## N. Guarded multisite switch/restore algorithm

The algorithm never assumes that switch_to_blog() either succeeds atomically or
leaves no state on Throwable.

Before each attempt it snapshots:

- current blog ID;
- exact count and contents of $GLOBALS['_wp_switched_stack'];
- $GLOBALS['switched'];
- $GLOBALS['table_prefix'];
- relevant $wpdb fields: blogid, prefix, base_prefix, options, postmeta,
  and blogs.

It records the pre-switch stack depth and calls switch_to_blog(blog_id) inside a
catch boundary. After normal return or Throwable it inspects the stack depth,
current blog ID, switched flag, table prefix and wpdb fields. Inspection failure
is unresolved.

A normal return is processable only when the target blog and an established frame
are proven. If a Throwable occurs after a frame/context was established, the helper
does not abandon it: it enters bounded restoration. If no frame was established
and the original snapshot is exact, it marks that blog unresolved without
restoring below the pre-depth. A changed context without a provable frame, a target
mismatch, or an uninspectable state is unresolved and stops cross-blog work.

In finally, restoration runs only while observed switched-stack depth is greater
than the pre-switch depth. It calls restore_current_blog() at most
MAX_RESTORE_ATTEMPTS times. Before and after each call it records depth and current
blog; a decrease in depth counts as progress even if Core throws after popping the
frame. A false return, unchanged depth, depth below the pre-depth, uninspectable
state, or no progress at the bound is unresolved. It never manually pops the stack
or edits wpdb/table_prefix.

After restoration it requires exact equality with the pre-switch snapshot:
current blog, stack contents/depth, switched, table prefix and the selected wpdb
fields. Any mismatch is unresolved. If original context cannot be proven restored,
it stops all later cross-blog enumeration and cleanup. It may continue to the next
blog only after exact restoration proof. Pre-existing switched depth is preserved;
the helper never restores below it.

## O. Single-site sequence

When is_multisite() !== true, process exactly the current blog once:

1. Family A Pass 1.
2. Family A Pass 2.
3. Family B Core deletion.
4. Family B verification.

No Family C query and no unnecessary blog switch occur. The internal result is COMPLETE
only if every step proves absence and context remains unchanged.

## P. Test-bootstrap impact

tests/bootstrap.php will receive only backwards-compatible test seams needed for
the helper. Existing behavior and the current 261 tests / 1384 assertions suite must
remain unchanged. Planned additions are:

- per-blog option and postmeta stores plus physical blog IDs;
- $wpdb->postmeta, $wpdb->blogs, blogid, prefix, and base_prefix;
- deterministic wpdb::esc_like(), prepare(), get_results(), and suppress_errors()
  stubs with query history and injected false/null/Throwable/last_error cases;
- is_multisite(), get_current_blog_id(), switch_to_blog(), restore_current_blog(),
  and delete_post_meta_by_key() seams;
- faithful _wp_switched_stack and switched behavior, including throw-before and
  throw-after-context switch and restore throw-before and restore throw-after-pop;
- Core delete_option() true/false/Throwable injection;
- subprocess helpers using PHP_BINARY/proc_open() and temporary files for the direct
  uninstall gate;
- assertions that no WP-Auto direct SQL INSERT/UPDATE/DELETE/REPLACE occurs.

No bootstrap seam may become production behavior or add external services.

## Q. Deterministic test matrix

tests/UninstallTest.php will map every ADR-004 requirement to executable tests.

### Entry and completion

- no WP_UNINSTALL_PLUGIN constant reaches no cleanup, no require, no output;
- defined constant runs the helper and produces no output;
- COMPLETE and INCOMPLETE are internal only and never exposed by uninstall.php;
- an outer Throwable becomes INCOMPLETE without secret/error output.

### Inventory and allowlist

- both exact option families are deleted;
- unrelated options and similar prefixes remain;
- uppercase, 63/65-character, appended-suffix, leading-newline, embedded-newline,
  LF and CRLF names remain byte-exact.

### Family A query/deletion

- only option_id/option_name, trusted options table, keyset cursor and fixed LIMIT;
- esc_like precedes wildcard append and prepare receives final patterns;
- no OFFSET, SQL write, get_option authority, second delete, restart or retry;
- multiple batches never skip rows;
- Pass 1 and Pass 2 each begin at cursor zero exactly once;
- malformed rows, invalid IDs, duplicate/out-of-order IDs and read failures are
  sticky incomplete;
- delete true + absent, false + absent, true/false + residual, and Throwable paths
  match the matrix.

### Family B

- exact key, only meta_id, LIMIT 1, current postmeta table;
- true/false + zero row complete;
- true/false + residual row, Throwable, query failure, malformed row incomplete;
- exactly one Core delete and no second delete; unrelated meta preserved.

### Family C

- single-site never queries blogs;
- multisite spans multiple batches and processes every physical blog once;
- no get_sites authority or OFFSET;
- invalid/duplicate/non-monotonic ID and Family C query failure incomplete;
- added-before-terminal and removed-during-processing are conservative;
- terminal empty batch is required.

### Switching and security

- ordinary switch/restore;
- processing Throwable restores;
- switch throw before and after context establishment;
- restore throw before and after progress;
- no restore below original depth;
- nested/pre-existing stack preserved;
- unresolved context stops later blogs;
- no arbitrary table, meta_value, option_value, external call, telemetry, secret or
  content logging; only the three approved SQL families and no direct SQL writes.

## R. Live integration validation

After implementation, run a fresh disposable WordPress 6.9.x / PHP 8.1 / MariaDB
environment, with a disposable object cache such as Redis only if available. Fresh
evidence is required because uninstall.php is a new lifecycle path; prior runtime
mutex evidence cannot substitute for it.

Topology and fixtures:

- single-site;
- multisite with more than SITE_BATCH_SIZE blogs;
- enough owned options to force more than OPTION_BATCH_SIZE rows;
- valid exact and malformed option names;
- audit metadata across several posts;
- before/after normalized snapshots of options, postmeta, blogs and relationships;
- observed SQL family and absence of WP-Auto direct SQL writes;
- an injected incomplete cleanup case and reinstall/residual idempotency authority case.

Exercise the real uninstall entrypoint, not only the helper. Verify that the plugin
file deletion flow continues normally and that no output or secrets appear. Verify
that exact-valid rows and audit metadata are removed, unrelated state remains, and
an unresolved run remains conservative on reinstall. If a persistent object cache is
used, record cache setup and clean it after the run.

Destroy all temporary containers, volumes, networks, users, fixtures, scripts,
Application Passwords, cache state and credentials. Preserve no evidence containing
secrets.

## S. Plugin Check

Run Plugin Check after implementation only when an existing local WordPress +
Plugin Check environment is available. If unavailable, record
NOT RUN — environment limitation; do not download a remote plugin merely for this
phase. Plugin Check is supplementary and cannot replace deterministic uninstall
tests or the fresh live probe.

## T. Public and normative deltas

Expected deltas are all NONE:

- Public MCP delta: NONE.
- Schema delta: NONE.
- Tool delta: NONE.
- Ability delta: NONE.
- Mutation error-set delta: NONE.
- Request-time idempotency delta: NONE.
- Audit event schema delta: NONE.
- Update concurrency delta: NONE.
- ADR-002, ADR-003, ADR-004, Mutation Contract and Architecture: NONE.

Any need for a public contract or accepted normative rule change blocks
implementation and requires a separate review.

## U. Sequencing, stop conditions and phase state

Later implementation must proceed in this order:

1. recheck inventory and exact baseline;
2. add helper and gated entry point;
3. implement single-site Family A;
4. implement Family A Pass 2;
5. implement Family B;
6. implement multisite Family C;
7. implement guarded switch/restore;
8. add deterministic tests;
9. run the full existing suite;
10. run PHPCS and dependency audit;
11. run the disposable WordPress 6.9 live probe;
12. run Plugin Check if available;
13. assemble evidence;
14. obtain independent Security / Implementation Review Gate.

Implementation is blocked by baseline drift, persistence inventory drift, a fourth
SQL family, any direct SQL write, changing ADR-003 authority, get_sites() as
completeness authority, inability to prove option absence, inability to restore
multisite context, lifecycle epoch/generation/TTL requirements, normal runtime
boot during uninstall, public/API output, or any Mutation Contract change.
Unexpected malformed state must fail closed, not widen cleanup.

Phase state after implementation validation:

- Phase 1.3.3: AUDIT FINAL REVIEW PASSED; GOV-1 POLICY APPROVED; GOV-2 POLICY
  APPROVED; GOV-2 ARCHITECTURE APPROVED; ADR-004 LANDED; UNINSTALL IMPLEMENTATION
  AND CURRENT-WORKTREE SECURITY GATES PASS; PENDING GIT LANDING; NOT SEALED.
- Phase 1.3.4: BLOCKED.

No staging, commit, push, PR, merge, tag, release, reset, or Phase 1.3.4 work is
authorized without a separate explicit instruction.

## Completion report

- Baseline: as recorded in section A.
- Persistence inventory: exactly the three families in section C.
- Current uninstall: thin gated entry point with no normal runtime bootstrap/autoload dependency.
- Implemented shape: thin uninstall.php plus dedicated uninstall-only helper.
- Implemented paths: uninstall.php, src/Uninstall/PrivateStateCleanup.php,
  tests/UninstallTest.php, tests/bootstrap.php.
- Completion model: internal sticky bool (true COMPLETE / false INCOMPLETE), ignored
  by WordPress.
- Families A/B/C, query failures, scalar validation, batch constants and guarded
  switch/restore: sections H–N.
- Test, live probe and Plugin Check plans: sections P–S.
- Public and normative deltas: all NONE.
- Stop conditions and phase state: section U.

Final verdict:

PASS — Phase 1.3.3 Uninstall Cleanup implementation and current-worktree security gates pass

Next task:

Git landing review, explicit-path staging, commit/PR authorization, and exact-main
post-merge verification. Phase 1.3.4 remains blocked.
