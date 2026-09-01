# Phase 1.3.1 Validation — Post/Page Create Draft

Date: 2026-09-01
Branch: `feat/phase-1-3-1-create-draft`
Baseline: `main@10332d0` (clean and synchronized with `origin/main`)
Verdict: **PASS — Phase 1.3.1 implementation is ready for review**

## Scope

This checkpoint implements only the two frozen Create Draft abilities:

| Ability | MCP tool | Runtime status |
| --- | --- | --- |
| `wp-auto/post-create-draft` | `wp-auto-post-create-draft` | Implemented and validated |
| `wp-auto/page-create-draft` | `wp-auto-page-create-draft` | Implemented and validated |

The Direct MCP server now exposes exactly ten tools: the eight Phase 1.2 read-only tools plus these two draft tools. Update, publishing, deletion, media, taxonomy mutation, SEO, Cloud, telemetry, and external WP-Auto requests remain out of scope.

## Automated evidence

- PHPUnit: **165 tests / 944 assertions** — pass.
- `composer validate --strict` — pass.
- `composer lint` — pass (45 PHP files).
- Focused Create Draft, idempotency, audit, Ability, bootstrap, and registrar tests — pass.
- `git diff --check` — pass.
- Service tests cover strict input validation, title semantics, fixed type/status/author/parent, capability mapping, allowlisted Core arguments, operation-scoped guard cleanup (including an unrelated nested insert), persistent atomic claim states, replay/conflict/in-progress behavior, uncertain-state fail-closed handling, audit retention/privacy, and exact schemas/annotations.
- Merge-gate remediation narrowed the final guard so only Page Create enforces `post_parent=0`; Post parent remains Core/plugin-controlled. Create audit finalization is serialized by the authoritative idempotency state: target correlation remains `in_progress`, matching retries are blocked, and only the original claim owner writes the first audit event.
- Audit success transitions the claim to `audit_recorded` and then `completed`. Automatic recovery is permitted only from `audit_recorded`; it verifies the target and existing logical audit event read-only and never appends audit metadata. An interrupted `in_progress` operation may remain blocking and require operator remediation rather than risk duplicate attribution or creation.

The existing Phase 1.2 regression suite remains green in the full PHPUnit run. No Composer, lockfile, CI, Adapter, or plugin-version changes were made.

## Live WordPress/MCP smoke

Disposable environment (removed after testing):

- WordPress `6.9` (`wordpress:6.9-php8.1-apache`)
- PHP `8.1.34`
- MariaDB `11.8.9`
- Official MCP Adapter `0.6.1`
- `WP_ENVIRONMENT_TYPE=local`
- Streamable HTTP endpoint: canonical `/wp-json/wp-auto/mcp`; the disposable install used the equivalent query route `/index.php?rest_route=/wp-auto/mcp` because pretty-permalink rewrites were disabled.

Observed lifecycle and contract evidence:

- authenticated `initialize` returned HTTP 200 and a session header;
- `notifications/initialized` returned HTTP 202;
- `tools/list` returned the exact ten-tool set (no wildcard/default/third-party abilities);
- Post Create returned a draft owned by the authenticated user with the frozen Post invariants (`type=post`, `status=draft`, authenticated author), `edit_url`, and final Core values. The live object happened to have Core's normal parent value `0`; WP-Auto does not define Post parent as a Create invariant;
- Page Create returned a root draft with the enforced Page invariants (`type=page`, `status=draft`, authenticated author, `parent=0`) and the exact output fields;
- replaying a completed key returned the same object with `idempotency_replayed=true`;
- reusing a completed key with a different payload returned an idempotency conflict and did not create another object;
- an unknown `status` input was rejected by the Adapter schema (`additionalProperties=false`);
- a subscriber-like identity without the fixed `create_posts` capability was denied by the Ability permission layer;
- anonymous transport returned HTTP 401; an authenticated identity without `read` returned HTTP 403;
- a temporary `wp_insert_post_data` filter attempted to change type, status, author, and parent; the operation-scoped final guard restored Post type/status/author while leaving the simulated Core parent behavior intact, and Page Create continued to enforce parent `0`;
- `wp-auto-post-get` confirmed Core-managed post behavior (including the default category) and the final stored content;
- MCP session DELETE returned HTTP 200.

## WordPress draft `modified_gmt` sentinel

Live WordPress 6.9 validation confirmed that a newly created draft may legitimately expose Core `post_modified_gmt` as `0000-00-00 00:00:00`. Phase 1.3.1 preserves and returns the final Core value instead of synthesizing or normalizing a timestamp. This does not block the Create Draft contract.

The frozen Phase 1.3 Update contract currently requires `expected_modified_gmt` to pass real GMT calendar validation. Because the Core sentinel is not a valid calendar datetime, Phase 1.3.2 must not begin implementation until a narrow compatibility amendment defines sentinel handling. No Update contract change is made in Phase 1.3.1.

Unit tests provide the deterministic failure, concurrent-claim, known-target recovery, replay, audit-failure, audit-recorded completion recovery, in-progress retry blocking, physical-container consistency, and post-write invariant coverage that is not practical to manufacture in a disposable live site. The test bootstrap triggers a second matching service call during the first audit write and verifies `wp_auto_idempotency_in_progress`, one audit write, one event, and a completed claim.

## State and secret cleanup

Temporary Application Passwords, users, draft fixtures, idempotency options, audit metadata, and the operation guard MU-plugin were removed. The WordPress and database containers, anonymous volumes, network, and temporary scripts were deleted. No credentials or live fixture data were added to the repository.

## Runtime boundary

Production changes are limited to the two Create Draft abilities, their shared mutation/idempotency/audit services, explicit ten-tool registration, and test bootstrap support. No Update Ability is registered. Create fixes post type, draft status, and authenticated author; Page Create additionally fixes parent `0`. Post parent is not client-exposed but is not overwritten as a WP-Auto invariant. Only contract fields reach WordPress Core, and final objects are re-read and invariant-checked before completion. Cross-request serialization comes from the persistent `add_option()` claim; the audit store is attribution only and is not atomic, transactional, or a cross-process lock.

## Next checkpoint

`Phase 1.3.2.0 — modified_gmt Sentinel Compatibility Amendment` is the sole recommended follow-up. Only after that contract review is separately approved may `Phase 1.3.2 — Draft Update + Best-effort Optimistic Concurrency` begin. Neither task is started as part of this validation checkpoint.
