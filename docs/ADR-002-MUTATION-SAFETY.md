# ADR-002: Draft Mutation Safety Model

- Status: Accepted — Phase 1.3.0 Mutation Contract Freeze
- Date: 2026-08-31
- Decision scope: Phase 1.3 draft content mutation

## Context

WP-Auto Connector needs to create and edit WordPress drafts through the same WordPress Abilities layer used by Direct MCP and future optional Cloud MCP. Mutation magnifies risks that do not exist in the Phase 1.2 read-only surface: duplicate creates, lost updates, capability mistakes, Core hook side effects, partial failures, and accidentally exposing publishing or generic WordPress write access.

WordPress post updates are not a database compare-and-swap API. Core modification timestamps have second-level precision, filters and hooks execute inside the write path, and successful Core writes can be followed by failures in connector bookkeeping. The architecture must represent those limits honestly while providing useful retry and attribution behavior.

## Decision

### Draft-only, field-allowlisted operations

Phase 1.3 exposes separate abilities for Post/Page Create Draft and Post/Page Update. Create fixes type, draft status, current-user author, and root Page parent. Update accepts only title, content, excerpt, and slug, and operates only on an already authorized draft. Publishing, status transitions, deletion, arbitrary metadata, taxonomy, media, template, parent, dates, comments, and generic Core arguments remain unavailable.

The implementation will send only contract fields to WordPress APIs. An operation-scoped final data guard, removed in `finally`, will reassert immutable type/status/author invariants immediately before persistence. The service will re-read the object after Core returns and verify the invariant set.

### Capability-object and object-level authorization

Create uses the fixed post type object's actual `cap->create_posts` capability in both the Ability and service. Update uses the post type object's `cap->edit_posts` as a baseline and `current_user_can( 'edit_post', $id )` for the final target. Authentication, role names, or broad administrator capabilities never replace these checks.

Unauthorized, wrong-type, and missing Update targets use the same existence-hiding error. Draft status is disclosed only after object authorization.

### Concurrent-safe persistent Create idempotency

Create idempotency is scoped by site, actor, Ability, and client idempotency key. A private, non-autoloaded `wp_auto_connector_idempotency_<scope-hash>` site option is claimed using the atomic uniqueness behavior of `add_option()`.

The option stores no raw key or content. It records a version, actor, Ability, SHA-256 canonical payload fingerprint, state, target ID when known, and GMT timestamps. The conceptual states are claimed/in-progress, completed, conflict, and recoverable-stale-or-failed; their exact persisted representation is deferred to implementation. Completed identical requests deterministically replay the verified original target. Conflicting payloads fail. A live in-progress request returns the stable `wp_auto_idempotency_in_progress` 409 response without creating a second object.

An uncertain or stale claim is not expired or released automatically. Before any new create under the same scope, deterministic resolution must either recover and complete/replay the original target, prove that Core created no object and move through the recoverable state, or retain the blocking claim. If `wp_insert_post()` may have succeeded but correlation or finalization failed, the service reports an uncertain mutation state and preserves authoritative idempotency state. This favors preventing duplicate drafts over automatic recovery.

Atomic claim acquisition and unconditional exactly-once object creation are different guarantees. The decision is concurrent-safe persistent idempotency with deterministic completed replay, not transactional exactly-once delivery across every Core hook, process termination, or database failure. Its authority is persistent local WordPress state and survives HTTP retries, PHP process restarts, and ordinary request failures; request memory and process-local caches are insufficient.

### Best-effort optimistic stale-write detection, not atomic CAS

Update requires the caller's exact `expected_modified_gmt`. After authorization and draft validation, the service re-reads the object and performs the final exact comparison immediately before invoking the guarded WordPress Core update path. If the values already differ, WP-Auto attempts no update and returns `wp_auto_content_conflict` with semantic status 409.

This is a best-effort optimistic concurrency precondition. `post_modified_gmt` has second-level granularity, so multiple writes in one second may be indistinguishable. A concurrent browser, background process, plugin, or other writer can also change the object after the final comparison but before Core finishes the update. Phase 1.3 therefore does not guarantee prevention of every lost-update race or claim that stale writes are always prevented.

Phase 1.3 intentionally remains on the WordPress Core mutation path so hooks, revisions, sanitization, cache invalidation, capability handling, and plugin interoperability remain intact. It does not introduce direct `$wpdb` conditional updates, `SELECT ... FOR UPDATE`, or custom transaction locking solely to simulate CAS. A stronger revision/version/atomic-CAS model requires a future contract revision; Phase 1.2 Get is not changed for it.

## Phase 1.3.2.0 amendment — Core `modified_gmt` sentinel

- Status: Formally sealed on main
- Scope: semantic compatibility only; no Update runtime or MCP tool is introduced.

WordPress Core can legitimately expose `0000-00-00 00:00:00` for a newly created draft. Create and Get already return the raw Core value, so the Update precondition must round-trip that exact opaque token. The amendment accepts only that exact sentinel or a strictly valid real GMT calendar datetime. It keeps exact raw-string comparison and the existing `wp_auto_invalid_request` (400) and `wp_auto_content_conflict` (409) semantics.

The sentinel is not unique and does not strengthen stale-write detection. Second-level precision, same-second ambiguity, and the check-to-write race remain unchanged. No normalization, timestamp synthesis, REST presentation shim, ETag, revision token, SQL CAS, transaction, or locking model is introduced.

Rejected alternatives for this amendment:

- convert the sentinel to current time, `post_date_gmt`, or another fabricated value;
- hide or normalize the sentinel in Create/Get;
- add an ETag, revision, version, or new public token field;
- reject the sentinel outright, which would make a valid Core draft token impossible to round-trip;
- replace the Core path with direct SQL CAS, row locks, or custom transactions.

### Bounded local attribution audit

Each mutated content object stores up to its own 20 most recent events in private `_wp_auto_connector_mutation_audit` metadata. This is per object, not site-wide. Events contain attribution and timing, plus a Create fingerprint or Update timestamp pair, but never content, raw idempotency keys, request bodies, or credentials. A safe replay adds no event.

Audit data remains local, is not exposed by the Phase 1.2 read tools, and cannot be supplied by an MCP client. It is attribution history, not authoritative idempotency state. Pruning an object's oldest audit event must never remove, expire, or weaken an active or completed idempotency record. The two lifecycles remain distinct even if a future implementation shares internal infrastructure. If a content write may have succeeded but audit finalization fails, the response is the uncertain-state error rather than a false rollback claim.

### Distinguish requested, expected, and forbidden effects

The requested domain effect is one draft creation or the allowlisted field update of one authorized draft. The connector does not disable Core filters, hooks, KSES, slashing, slug canonicalization, timestamps, cache invalidation, default-category handling, revisions, capability mapping, or Core/plugin-maintained internal metadata. Those expected lifecycle effects are not automatically contract violations, and the client does not gain control of them.

WP-Auto itself must not intentionally publish or promote status, mutate unrelated content, change taxonomy, media, featured images, SEO, arbitrary metadata, users, settings, plugins, themes, Cloud state, or telemetry. Final integration validation compares intended target effects plus documented lifecycle effects; it does not require byte-for-byte database identity.

### Shared WordPress domain service

Mutation behavior belongs in a small WP-Auto domain service behind WordPress Abilities, separate from MCP protocol code. Direct MCP and any future explicitly enabled Cloud MCP path must invoke the same Ability contracts. The implementation must use WordPress Core APIs, not direct SQL, filesystem, shell, or custom remote execution.

## Consequences

### Positive

- The MCP surface remains narrow, draft-only, capability-aware, and auditable.
- Matching completed requests replay deterministically, and live or unresolved claims cannot immediately create a second draft.
- Uncertain failures fail closed instead of silently creating another object.
- Update clients receive a practical stale-write signal without a false atomicity promise.
- Core security, sanitization, plugin hooks, and storage semantics remain in effect.
- Protocol adaptation remains replaceable without changing WP-Auto domain contracts.

### Costs and limitations

- An interrupted Create can leave a claim that requires later operator remediation; automatic retry with that key remains blocked.
- Best-effort `modified_gmt` checking cannot detect every same-second or overlapping write.
- Core can commit content before connector claim or audit finalization fails, requiring an explicit uncertain-state response.
- A bounded per-object audit is attribution, not a complete compliance event ledger.
- Legitimate Core filters can change stored content from the submitted bytes.
- Old per-object audit events can be pruned, but authoritative idempotency records require an independent lifecycle.

## Rejected alternatives

- **Generic post CRUD or REST proxy:** exposes fields and operations outside the product boundary.
- **Authentication-only or role-name authorization:** bypasses WordPress capability mapping and object ownership rules.
- **Transient, memory, or post-meta-only Create deduplication:** does not provide a durable atomic pre-create claim.
- **Automatically expiring uncertain claims:** can create duplicates after an interrupted successful write.
- **Direct SQL conditional updates, row locks, custom transactions, or custom post writes:** bypass or distort Core APIs, hooks, revisions, sanitization, cache invalidation, capability expectations, plugin interoperability, and WordPress.org-compatible architecture.
- **Claiming `modified_gmt` is atomic CAS:** inaccurate because of second-level precision and the separate check/write operations.
- **Disabling revisions, default categories, or content filters:** conflicts with normal WordPress behavior and site policy.
- **Unbounded audit history or content-bearing logs:** adds privacy and storage risk without serving the Phase 1 requirement.
- **Combining draft creation with publishing:** violates the product invariant that publishing is a separate, opt-in future ability.

## Follow-up

Phase 1.3.1 implemented the frozen Post/Page Create Draft contracts, and Phase 1.3.2 implemented the two Update abilities after the Phase 1.3.2.0 sentinel amendment was formally sealed. All are now formally sealed on `main`. Any material change to names, schemas, capability paths, idempotency state, error semantics, audit fields, or Core side-effect policy requires an explicit contract and ADR review before code is exposed.
