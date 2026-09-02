# Phase 1.3.3 SEC-1 Validation

Status: **SECURITY REMEDIATION REVIEW APPROVED — PENDING PR / MERGE / MAIN VERIFICATION**

Date: 2026-09-02

Baseline: `main@8f45359f553bd346ee29b62e5524fbf047746c4e`

Branch: `fix/phase-1-3-3-sec-1-create-fail-closed`

## Finding and root cause

SEC-1 identified that unexpected `Throwable` values could escape several Create-side idempotency, replay, result-generation, audit, and finalization calls in `ContentMutationService`. This violated the frozen fail-closed error and privacy boundary after an operation might have changed persistent WordPress state.

The confirmed path was a `RuntimeException( 'sensitive-internal-detail' )` thrown by `get_permalink()` after Core had created and correlated a draft. The exception escaped the service instead of becoming the existing generic uncertain-state error.

## Production remediation

The service now uses narrow `Throwable` boundaries around:

1. persistent claim acquisition;
2. completed replay and `audit_recorded` recovery;
3. Create preparation, guard registration, Core Create, and mandatory guard cleanup;
4. claim release following a definitive Core failure;
5. target correlation, final target read/invariant verification, output construction, audit append, audit-state transition, and claim completion.

The successful sequence remains unchanged:

```text
claim
-> Core Create under operation-scoped guard
-> record target
-> verify final target
-> construct output
-> append one audit event
-> mark audit_recorded
-> complete
-> return
```

A definitive Core failure still returns `wp_auto_content_create_failed` only after claim release returns true. Release false or `Throwable`, a possible Core write, or any indeterminate post-write/finalization operation returns `wp_auto_mutation_state_uncertain` with semantic status 500.

No caught exception is logged, interpolated, rethrown, or returned. No automatic retry, release retry, audit retry, rollback, deletion, or trash operation was added.

## Idempotency, replay, and audit preservation

- Scope and fingerprint are unchanged.
- Persistent states remain exactly `in_progress`, `audit_recorded`, and `completed`.
- A claim exception after the option write remains blocking and cannot enter Core Create on retry.
- A valid Core target is never deleted or followed by claim release.
- Completed replay performs no Core Create and appends no audit event.
- `audit_recorded` recovery performs no Core Create and appends no audit event.
- Ambiguous audit persistence is not retried blindly.
- The private audit schema and per-object maximum of 20 events are unchanged.

## Deterministic test evidence

The focused mutation suites passed with **76 tests / 418 assertions**:

- `ContentMutationServiceTest`: 39 tests / 217 assertions;
- `CreateIdempotencyStoreTest`: 3 tests / 21 assertions;
- `MutationAuditStoreTest`: 4 tests / 31 assertions;
- Post/Page Create Ability tests: 4 tests / 19 assertions;
- Update service and Post/Page Update Ability regressions: 26 tests / 130 assertions.

The SEC-1 cases cover:

- claim exception before persistence and mutate-then-throw after persistence;
- Core Create exception after a simulated write and mandatory guard cleanup;
- definitive Core failure plus successful release;
- release false and release exception;
- target-correlation exception;
- post-write `get_post()` exception;
- `get_permalink()` and `get_edit_post_link()` exceptions;
- audit append exception;
- `mark_audit_recorded()` and `complete()` exceptions;
- completed replay target/output exceptions;
- `audit_recorded` audit-evidence, completion, and output exceptions;
- absence of `sensitive-internal-detail` from every public service error;
- no duplicate Create, audit append, release, or rollback on uncertain paths.

Full repository results:

```text
composer validate --strict: PASS
PHPUnit: 211 tests / 1214 assertions, PASS
composer lint: 53 PHP files, PASS
composer audit --locked: PASS, no advisories
git diff --check: PASS
```

Composer reported only that its user cache directory was not writable and proceeded without cache. This did not affect the audit result.

## Live WordPress and Direct MCP evidence

Disposable environment:

```text
WordPress: 6.9
PHP: 8.1.34
Database: MariaDB 11.8.9
MCP Adapter: 0.6.1
Transport: Streamable HTTP, protocol 2025-11-25
Authentication: temporary WordPress Application Password
Environment type: local; HTTP used only inside the disposable local environment
```

Observed results:

- authenticated `initialize` returned HTTP 200;
- `notifications/initialized` returned HTTP 202;
- `tools/list` returned exactly the frozen twelve-tool set;
- Post Create Draft and Page Create Draft succeeded;
- completed Post replay returned the same operation without another object or audit event;
- the exact normal Post and Page fixture counts were one each;
- the normal Post contained exactly one logical Create audit event;
- MCP session DELETE returned HTTP 200.

The controlled SEC-1 fixture was a temporary MU-plugin `post_link` filter. It deleted its one-shot trigger and threw `RuntimeException( 'sensitive-internal-detail' )` when Create output invoked `get_permalink()` after target correlation.

Direct MCP returned `isError=true` with only the generic translated uncertain-state message. The response contained no marker, exception class, stack, path, line, plugin detail, or credential. MCP Adapter 0.6.1 does not serialize the underlying `WP_Error` code/status in this wire result; a same-environment invocation of the production domain service verified the underlying stable semantic result as:

```text
code: wp_auto_mutation_state_uncertain
status: 500
```

After removing the one-shot failure condition, the same authenticated actor retried the exact Ability, key, and payload through MCP. The operation remained in progress and did not re-enter Core Create. The exact-title draft count remained **one**.

## Cleanup

The temporary MU-plugin, users, Application Passwords, Posts, Pages, audit metadata, idempotency options, database, WordPress files, containers, named volumes, network, and credential state were deleted. Post-cleanup Docker queries returned no resource with the validation prefix. The temporary local fixture file was also deleted from the repository working tree.

## Contract and lifecycle result

```text
public schema delta = NONE
error-set delta = NONE
idempotency-state delta = NONE
audit-schema delta = NONE
ADR delta = NONE
12-tool surface delta = NONE
```

Review verdict: **PASS — Phase 1.3.3 SEC-1 Security Remediation Review Gate approved**.

Phase 1.3.3 remains paused pending PR, CI, merge, and main verification of SEC-1. Phase 1.3.4 remains blocked. The next gate is Phase 1.3.3 SEC-1 PR Merge Gate; the broader mutation audit has not resumed.
