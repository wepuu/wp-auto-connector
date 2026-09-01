# Phase 1.3.2.0 Validation — `modified_gmt` Sentinel Compatibility Amendment

Date: 2026-09-01
Branch: `chore/phase-1-3-2-0-modified-gmt-sentinel`
Baseline: `main@6fdb6ea4f3ba176029e9e64aab65305b0d047a9b`
Status: **Approved — pending merge/seal**

## Baseline and problem discovered

Phase 1.3.1 live WordPress 6.9 validation confirmed that a legitimate newly created draft may expose the raw Core value `post_modified_gmt = 0000-00-00 00:00:00`. The Create and Get paths preserve that value. The previously frozen Update contract, however, required every `expected_modified_gmt` value to pass real Gregorian calendar validation, so an agent could not round-trip a valid token returned by Core.

WordPress 6.9's `wp_insert_post()` path can retain the zero date for draft-related date handling. WP-Auto deliberately does not copy the WordPress REST response shim that substitutes a presentation timestamp for zero `modified_gmt`; mutation concurrency uses the raw Core field. References: [WordPress 6.9 `wp_insert_post()`](https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/post.php#L4435-L4463) and [WordPress 6.9 REST date response](https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php#L1767-L1778).

## Scope

This checkpoint amends only the semantic validation of `expected_modified_gmt`. It does not implement Post Update or Page Update, register an Ability, add an MCP tool, change Create/Get behavior, or alter the optimistic-concurrency architecture.

## Exact contract delta

| Property | Existing contract | Phase 1.3.2.0 approved amendment |
| --- | --- | --- |
| Field name | `expected_modified_gmt` | unchanged |
| Type | string | unchanged |
| Length | exactly 19 characters | unchanged |
| Structural pattern | `^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$` | unchanged |
| Semantic validation | real GMT calendar validation | exact Core sentinel **or** real GMT calendar validation |
| Comparison | best-effort timestamp precondition | exact raw string comparison, unchanged in architecture |
| Errors | invalid request 400 / content conflict 409 | unchanged |

## Schema delta

The planned Update JSON Schema remains the same strict object schema. The existing numeric pattern already admits the exact sentinel, so no `oneOf`, `anyOf`, nullable type, or new public field is needed. There is no Update Ability or Update wire schema in the current ten-tool runtime.

## Semantic-validation delta

The only newly accepted value is the exact opaque WordPress Core sentinel:

```text
0000-00-00 00:00:00
```

All other values must be real GMT calendar datetimes in the existing shape. The sentinel is not parsed as a date, generalized to other zero-date variants, or treated as a stronger version token.

## Comparison semantics

Phase 1.3.2 must eventually compare the client token and the latest raw Core `post_modified_gmt` using strict string equality. Neither side is normalized, converted, parsed and reformatted, or replaced with a synthesized timestamp.

| Expected token | Current Core token | Result |
| --- | --- | --- |
| `0000-00-00 00:00:00` | `0000-00-00 00:00:00` | precondition match |
| `0000-00-00 00:00:00` | `2026-09-01 01:02:03` | `wp_auto_content_conflict`, semantic 409 |
| `2026-09-01 01:02:03` | `0000-00-00 00:00:00` | `wp_auto_content_conflict`, semantic 409 |
| `2026-09-01 01:02:03` | `2026-09-01 01:02:03` | precondition match |
| `2026-09-01 01:02:03` | `2026-09-01 01:02:04` | `wp_auto_content_conflict`, semantic 409 |

## Accepted and rejected examples

Accepted:

- `0000-00-00 00:00:00` (the sole sentinel exception);
- `2026-09-01 12:34:56`;
- `2024-02-29 00:00:00`;
- `2030-12-31 23:59:59`.

Rejected with `wp_auto_invalid_request` (semantic 400):

- `0000-00-00 00:00:01`;
- `0000-00-01 00:00:00`;
- `0000-01-01 00:00:00`;
- `2026-02-29 12:00:00`;
- `2026-13-01 12:00:00`;
- `2026-04-31 12:00:00`;
- `2026-09-01T12:00:00`;
- `2026-09-01 12:00`;
- `2026-09-01 12:00:00Z`.

No new error code is introduced. A valid token that differs from current Core state continues to use `wp_auto_content_conflict` with semantic status 409.

## Output behavior

Create and Get remain unchanged. Mutation outputs continue to expose the final raw Core `post_modified_gmt`; therefore `modified_gmt` may be the exact sentinel or a normal GMT datetime. No REST presentation shim or timestamp synthesis is permitted.

## Concurrency limitations

This amendment does not strengthen concurrency guarantees. `modified_gmt` remains second-precision, same-second writes may be indistinguishable, and the final check remains separate from the Core write (TOCTOU). The model is best-effort optimistic stale-write detection, not an atomic CAS, transaction, lock, revision token, or ETag protocol. The sentinel is not unique and does not improve that model.

## Public-contract delta

- Public field additions = NONE.
- Public field removals = NONE.
- Field names = unchanged.
- JSON structural schema = unchanged.
- MCP names = unchanged.
- Annotations = unchanged.
- Error-code set = unchanged.
- Capability model = unchanged.
- Runtime tool count = unchanged at 10.

Only semantic validation of the existing `expected_modified_gmt` field is amended.

## Runtime and regression boundary

- Current Direct MCP runtime remains exactly 10 tools.
- `wp-auto/post-update` and `wp-auto/page-update` remain unimplemented and unregistered.
- Phase 1.2 read-only tools and schemas are untouched.
- Phase 1.3.1 Create Draft runtime and sealed validation evidence are untouched.
- No production PHP, tests, Composer files, lockfile, CI, Adapter, version, or generated artifact changes are permitted.

### Phase 1.2 regression boundary

All eight read-only tools, their schemas, permissions, privacy rules, bounded queries, and the ten-tool registrar surface remain unchanged. No Phase 1.2 runtime or validation document is reopened.

### Phase 1.3.1 regression boundary

Post/Page Create Draft behavior, persistent idempotency, audit finalization, raw `modified_gmt` output, and sealed live evidence remain unchanged. `docs/PHASE_1_3_1_VALIDATION.md` is not modified.

## No-runtime-delta evidence

The approved amendment must produce no output for:

```text
git diff -- src
git diff -- tests
git diff -- wp-auto-connector.php
git diff -- composer.json composer.lock
git diff -- .github
```

The contract diff is limited to exact sentinel acceptance, raw-token comparison/output clarification, and related rationale/status wording.

## Quality gates

The documentation-only amendment is validated with the repository quality gates:

- `composer validate --strict`: PASS;
- `composer test`: PASS — 165 tests / 944 assertions;
- `composer lint`: PASS — 45 PHP files;
- `composer audit --locked`: PASS — no security advisories;
- `git diff --check`: PASS.

Static review must show no diff under `src/`, `tests/`, `wp-auto-connector.php`, `composer.json`, `composer.lock`, or `.github/`. The existing Phase 1.3.1 live WordPress evidence and official WordPress 6.9 Core source are sufficient for this contract-only checkpoint; no new live environment is required.

## Review verdict

`PASS — Phase 1.3.2.0 contract review approved; ready for commit / PR / merge gate`

The approved amendment is pending merge/seal on `main`. It is not formally sealed on `main` and does not unlock Update implementation until the PR is merged and main CI passes.

## Next checkpoint

Merge/seal Phase 1.3.2.0 on `main`.

After PR #9 is merged and the main PHP Quality run passes, Phase 1.3.2 - Draft Update + Best-effort Optimistic Concurrency becomes the next implementation checkpoint.
