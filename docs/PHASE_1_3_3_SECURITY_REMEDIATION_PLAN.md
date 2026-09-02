# Phase 1.3.3 SEC-1 Security Remediation Plan

Status: **SEC-1 REMEDIATION REVIEW APPROVED — PENDING PR / MERGE / MAIN VERIFICATION**

Baseline: `main@8f45359f553bd346ee29b62e5524fbf047746c4e`

Implementation branch: `fix/phase-1-3-3-sec-1-create-fail-closed`

## SEC-1 statement

**SEC-1 — Create-side Throwable fail-closed boundary is incomplete** is a BLOCKER. After a Create claim is acquired, `ContentMutationService` invokes persistence, recovery, result reconstruction, and finalization operations that may execute WordPress or plugin code. Several of those calls are outside a `Throwable` boundary. A raw exception can therefore bypass the frozen `wp_auto_mutation_state_uncertain` error and make error privacy dependent on the MCP Adapter or another outer handler.

The confirmed reproduction caused `get_permalink()` to throw `RuntimeException( 'sensitive-internal-detail' )` after Core returned a created target. The exception escaped `ContentMutationService` instead of producing the stable sanitized `WP_Error`.

This plan restores the already frozen contract. It introduces no new public or persistent behavior.

## Confirmed production paths and root cause

The new-Create path is:

```text
normalize and authorize
→ claim
→ prepare and install invariant guard
→ wp_insert_post()
→ remove guard in finally
→ release claim on definitive Core failure
→ record_target_in_progress()
→ get_post() and verify invariants
→ output() / get_permalink() / get_edit_post_link()
→ append one Create audit event
→ mark_audit_recorded()
→ complete()
→ return output
```

Only the current `wp_insert_post()` call has an explicit `Throwable` conversion. Claim acquisition, claim release, target correlation, post-write verification, output generation, audit finalization, and idempotency finalization can throw outside that boundary.

The same root cause exists in `handle_existing_claim()`:

- completed replay calls `get_post()`, authorization, and `output()` without a service-level `Throwable` conversion;
- `audit_recorded` recovery additionally calls `MutationAuditStore::has_create_event()` and `CreateIdempotencyStore::complete()` without that conversion.

Normal `WP_Error` values and boolean failure returns already express frozen semantic control flow. They must remain unchanged. SEC-1 concerns only escaping `Throwable` values.

## Security impact

Severity: **BLOCKER**.

- The frozen Create error mapping is violated.
- Internal WordPress/plugin exception text may escape the domain service.
- A post-write or persistent-state outcome can be represented outside the stable uncertain-state contract.
- Sanitization becomes dependent on an outer Adapter/error handler instead of the domain service.

The confirmed evidence does not prove content corruption or credential disclosure. The security defect is the missing fail-closed semantic and privacy boundary.

## Frozen contract requirement

No `Throwable` from a Create-side persistence, recovery, result reconstruction, or finalization path may escape `ContentMutationService`. When a safe definitive result cannot be proven, the service returns:

```text
wp_auto_mutation_state_uncertain
semantic status 500
```

The stable translated public message must contain no exception message, stack, path, Core/plugin error detail, content, credential, or raw idempotency key.

The definitive failure distinction remains:

```text
Core returns a definitive failure before a successful write
+ authoritative claim release is verified
→ wp_auto_content_create_failed / 500

write may exist
or claim/audit persistence is indeterminate
or result/finalization unexpectedly fails
→ wp_auto_mutation_state_uncertain / 500
```

## Failure-boundary matrix

| Stage | Write may exist? | Persistent claim may have changed? | Throwable or failure result | Claim release allowed? | Retry Create allowed? |
| --- | ---: | ---: | --- | --- | --- |
| Claim acquisition | No content write by WP-Auto yet; claim may exist | Yes | `uncertain` | No; acquisition outcome may be indeterminate | No |
| Post-claim preparation / guard registration | No Core Create yet | Yes | `uncertain` | No automatic release in this remediation | No |
| Core Create throws | Maybe | Yes | `uncertain` | No | No |
| Guard cleanup throws | Maybe | Yes | `uncertain` | No | No |
| Core returns `WP_Error`/invalid result and release succeeds | No proven content | Released and verified absent | `create_failed` | Already released | A later request may retry |
| Claim release returns false | No proven content | Uncertain | `uncertain` | No further assumption | No |
| Claim release throws | No proven content | Uncertain | `uncertain` | No further assumption | No |
| Record target returns false | Yes | May remain uncorrelated | `uncertain` | No | No |
| Record target throws | Yes | May be uncorrelated or correlated | `uncertain` | No | No |
| Final `get_post()` / invariant verification | Yes | Correlated or correlation attempted | `uncertain` | No | No |
| Output/permalink/edit-link generation | Yes | Correlated | `uncertain` | No | No |
| Audit append returns false | Yes | Correlated, normally `in_progress` | `uncertain` | No | No |
| Audit append throws | Yes | Audit write outcome unknown | `uncertain` | No | No; do not retry append blindly |
| `mark_audit_recorded()` returns false or throws | Yes | May be `in_progress` or `audit_recorded` | `uncertain` | No | No |
| `complete()` returns false or throws | Yes | May be `audit_recorded` or `completed` | `uncertain` | No | No; recovery inspects persisted state |
| Completed replay target/auth/output throws | Historical write exists | `completed` or read outcome unknown | `uncertain` | No | No new Create |
| `audit_recorded` recovery verification/completion/output throws | Historical write exists | `audit_recorded` or completion outcome unknown | `uncertain` | No | No new Create or audit append |

## Planned fail-closed implementation

The implementation should remain in `ContentMutationService` and use narrow boundaries around stateful phases:

1. Wrap `CreateIdempotencyStore::claim()` in `try/catch ( \Throwable )`; return `uncertain` without inspecting or releasing an indeterminate claim.
2. Wrap the call to `handle_existing_claim()` so every replay/recovery `Throwable` becomes `uncertain`; normal conflict, in-progress, replay, and recovery return values remain unchanged.
3. Enclose post-claim preparation, guard installation, Core Create, and guard cleanup in a boundary that preserves `remove_filter()` in `finally`. A `Throwable` from Core or cleanup returns `uncertain`.
4. On a definitive Core `WP_Error`/invalid result, wrap only `release()`. Return `create_failed` only when release returns true; false or `Throwable` returns `uncertain`.
5. After a valid Core post ID, wrap target correlation through final completion in one post-write finalization boundary. Existing false/`WP_Error` branches remain normal control flow; every `Throwable` returns `uncertain`.

The post-write boundary retains the successful order:

```text
record target
→ verify target and invariants
→ construct output
→ append exactly one audit event
→ mark audit_recorded
→ complete
→ return output
```

No catch block logs, rethrows, interpolates, or returns the caught exception. The existing `uncertain()` factory remains the only public representation.

Avoid a repository-wide or Adapter-level catch. The domain service must enforce its own contract. Also avoid a catch that changes ordinary `WP_Error` or boolean-return semantics.

## New-Create state consequences

- Claim acquisition uncertainty remains blocking; local PHP variables are not authority.
- Before Core returns a valid ID, `wp_insert_post()` `Throwable` continues to mean that a write may exist.
- After a valid ID, target correlation failure never releases the claim or deletes the target.
- A post-write output or audit failure does not roll content back.
- An unknown audit write outcome is not retried automatically because doing so could duplicate an event.
- `mark_audit_recorded()` or `complete()` uncertainty preserves whatever state WordPress persisted; a later same request follows the existing state machine.

## Existing-claim and replay mapping

### Completed replay

A valid completed replay continues to perform no Core Create and append no audit event. It verifies the target, invariants, and `edit_post`, then reconstructs the current output with `idempotency_replayed=true`. Any `Throwable` during target lookup, authorization, permalink/edit-link generation, or output reconstruction returns sanitized `uncertain`. It does not release the claim or retry Create.

Deterministic target/invariant/authorization failure continues to use the existing `wp_auto_idempotency_conflict`; the remediation does not convert normal conflict control flow.

### `audit_recorded` recovery

Recovery continues to verify the target, invariants, authorization, and existing logical Create audit event, then transitions the authoritative claim to `completed` and returns replay output. It never appends another audit event. A `Throwable` from lookup, capability mapping, `has_create_event()`, `complete()`, or output reconstruction returns sanitized `uncertain`; there is no release, Create retry, or audit retry.

## Definitive versus uncertain mapping

- Invalid input remains `wp_auto_invalid_request`.
- Same-scope different fingerprint remains `wp_auto_idempotency_conflict`.
- Live `in_progress` remains `wp_auto_idempotency_in_progress`.
- Definitive Core failure plus verified claim release remains `wp_auto_content_create_failed`.
- Every indeterminate claim, possible write, post-write reconstruction, audit, or finalization exception becomes `wp_auto_mutation_state_uncertain`.

No new error is required.

## Audit consequences

The private audit key, event schema, per-object maximum of 20, and replay behavior remain unchanged. A successful new Create still appends exactly one event before the claim becomes completed. Completed replay and `audit_recorded` recovery append none.

If audit append throws, the implementation cannot assume whether metadata committed. It returns uncertain and does not retry the append, delete metadata, release the claim, or roll back content. If state reaches `audit_recorded`, the existing recovery path can deterministically verify the logical event before completion.

## No-rollback, no-duplicate, and no-sensitive-error rules

- Never call `wp_delete_post()` or `wp_trash_post()` to compensate for finalization failure.
- Never automatically expire, replace, or delete an uncertain claim.
- Never retry Core Create under the same unresolved claim.
- Never retry audit append when its write outcome is unknown.
- Never append an audit event on completed replay or `audit_recorded` recovery.
- Never expose a caught exception message or stack. Tests use `sensitive-internal-detail` and assert it is absent from the returned `WP_Error` message.

## Planned production scope

Expected production change:

```text
src/Content/ContentMutationService.php
```

No change is currently justified in `CreateIdempotencyStore.php` or `MutationAuditStore.php`; their existing return semantics are sufficient when the service catches their `Throwable` values. If implementation proves otherwise, stop for human review rather than widening scope silently.

## Planned test scope

Expected test changes:

```text
tests/ContentMutationServiceTest.php
tests/bootstrap.php
```

The bootstrap should add deterministic, one-shot exception injection for only the required WordPress primitive boundaries: claim option operations, target lookup, edit-link generation, audit metadata writes, and option state transitions. Injection flags must reset after throwing and must not alter ordinary test behavior.

## Planned deterministic test matrix

Each `Throwable` test uses marker text `sensitive-internal-detail` and asserts:

- result is `WP_Error`;
- code is `wp_auto_mutation_state_uncertain`;
- semantic status is 500;
- public message does not contain the marker;
- target/claim/audit counts and states match the relevant row below.

Required cases:

1. `claim()` throws, including a practical mutate-then-throw option primitive if the stub can model it faithfully: uncertain, no retry assumption.
2. `record_target_in_progress()` throws after a valid Core ID: target remains, claim is not released, retry creates no second draft.
3. Post-write `get_post()` throws: uncertain and target remains.
4. Create `get_permalink()` throws: uncertain and no raw detail.
5. Create `get_edit_post_link()` throws: uncertain and no raw detail.
6. Audit append throws after content exists: uncertain, no rollback, no blind audit retry.
7. `mark_audit_recorded()` throws: uncertain; persisted state is treated as unknown.
8. `complete()` throws: uncertain; one target and one logical audit event remain.
9. Definitive Core `WP_Error` plus successful release remains `wp_auto_content_create_failed`.
10. Definitive Core failure plus release false returns uncertain.
11. Definitive Core failure plus release `Throwable` returns uncertain without marker leakage.
12. Completed replay `get_post()` or output throws: no Core Create, no new audit, uncertain.
13. Completed replay permalink/edit-link generation throws: same target count and audit count, uncertain.
14. `audit_recorded` recovery `has_create_event()` throws: no new Core Create/audit/release, uncertain.
15. `audit_recorded` recovery `complete()` throws: no duplicate target or audit, uncertain.
16. `audit_recorded` recovery output throws after deterministic completion: uncertain; persisted completed state is preserved.

Regression groups to rerun:

- new Create success and exact output;
- completed replay;
- different fingerprint conflict;
- live in-progress conflict;
- `audit_recorded` recovery;
- one physical and logical audit event;
- target-correlation and audit false-return uncertainty;
- guard cleanup on success, Core failure, and exception;
- nested insert isolation;
- Post parent remains Core/plugin-controlled;
- Page parent remains zero;
- fixed type/status/author;
- full Update and read-only suites.

## Planned narrow live validation

Use one disposable WordPress 6.9 environment with the current Adapter and a temporary validation-only plugin/filter that throws `RuntimeException( 'sensitive-internal-detail' )` from permalink or edit-link generation after one draft is created.

Invoke the matching Create tool through Direct MCP. Verify:

- MCP application error represents `wp_auto_mutation_state_uncertain` with semantic status 500;
- no response contains the marker, stack, path, or plugin detail;
- exactly one draft exists;
- the authoritative claim remains fail-closed;
- retrying the same actor, Ability, key, and payload does not create a second draft;
- draft count remains one;
- temporary user, Application Password, fixture, plugin, containers, volume, network, and credentials are removed.

This is a narrow SEC-1 reproduction only, not Phase 1.3.4 integration validation.

## Non-goals

- no new public error or field;
- no new idempotency state;
- no audit schema or retention change;
- no automatic recovery, retry, TTL, cron cleanup, or claim expiration;
- no content rollback, trash, or deletion;
- no publishing or status transition;
- no CAS, transaction, lock, revision token, or ETag;
- no dependency, Adapter, transport, allowlist, or version change;
- no Phase 1.3.4 work;
- no rewrite of sealed Phase 1.3.2 evidence.

## Post-remediation audit resume

Phase 1.3.3 remains incomplete and blocked. After the SEC-1 implementation is reviewed, merged, and its exact main CI succeeds:

1. record the remediation commit, PR, focused tests, live probe, and main CI;
2. synchronize from the new `main` baseline without rewriting history;
3. resume the Phase 1.3.3 security audit from the first unaudited area;
4. re-run all mutation security regressions and repository quality gates;
5. create the Phase 1.3.3 audit candidate only when BLOCKER and MAJOR counts are zero.

Fixing SEC-1 alone does not complete or formally seal Phase 1.3.3 and does not unlock Phase 1.3.4.

## Planning verdict

The remediation requires no public contract, ADR, error-set, idempotency-state, or audit-schema change. It is ready for Planning Review Gate before implementation.

## Implementation evidence

The approved service-only remediation is implemented on the dedicated branch. `ContentMutationService` now converts unexpected Create-side `Throwable` values at five narrow boundaries: claim acquisition, existing-claim replay/recovery, guarded Core Create and cleanup, definitive-failure claim release, and post-write correlation/finalization. Normal semantic `WP_Error` and boolean-return branches remain unchanged.

Deterministic tests cover exceptions before and after claim persistence, Core Create after a possible write, release false/exception, target correlation, target re-read, permalink/edit-link generation, audit append, audit-state transitions, completion, completed replay, and `audit_recorded` recovery. Every injected exception contains `sensitive-internal-detail`; the returned service error is always the generic `wp_auto_mutation_state_uncertain` with semantic status 500 and never contains the marker.

Validation results:

- focused mutation suites: 76 tests / 418 assertions;
- full PHPUnit: 211 tests / 1214 assertions;
- Composer validation, PHPCS over 53 PHP files, dependency audit, and `git diff --check`: pass;
- disposable WordPress 6.9 / PHP 8.1.34 / MariaDB 11.8.9 / MCP Adapter 0.6.1 validation: pass;
- Direct MCP retained exactly twelve tools, normal Post/Page Create and completed replay passed, and replay retained one logical audit event;
- a temporary `post_link` exception during `get_permalink()` produced a sanitized MCP `isError` response; Adapter 0.6.1 exposed only the generic message at the wire layer, while the same live WordPress domain path returned `wp_auto_mutation_state_uncertain` with status 500;
- retrying the same actor, Ability, key, and payload returned the in-progress semantic result and the exact-title draft count remained one;
- all disposable users, Application Passwords, fixtures, containers, volumes, networks, and credentials were removed.

The SEC-1 remediation passed Security Remediation Review. It remains pending PR, CI, merge, and main verification. The broader Phase 1.3.3 audit stays paused and this implementation does not start or complete that audit.
