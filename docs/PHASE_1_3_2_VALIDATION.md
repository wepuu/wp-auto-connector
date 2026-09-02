# Phase 1.3.2 Validation — Post/Page Draft Update

Date: 2026-09-01
Branch: `feat/phase-1-3-2-draft-update`
Baseline: `main@6c80d5b879ce072ee8d5ef66c5ae9f430a6e3a48` (clean and synchronized with `origin/main`)
Status: **FORMALLY SEALED ON MAIN**
Merged PR: `#10`
Merge method: rebase merge
Merged runtime baseline: `main@b37cf3d80bf97fff5e01305f125b4f9b2ff7711b`
Post-merge main PHP Quality: Run `33575020188` — completed / success

## Scope

This reviewed implementation adds only the two frozen Draft Update abilities:

| Ability | MCP tool | Review status |
| --- | --- | --- |
| `wp-auto/post-update` | `wp-auto-post-update` | Implemented and locally validated |
| `wp-auto/page-update` | `wp-auto-page-update` | Implemented and locally validated |

The reviewed Direct MCP server exposes exactly twelve tools: the eight Phase 1.2 read-only tools, the two validated Create Draft tools, and these two Update tools. Publishing, deletion, arbitrary status changes, media, taxonomy mutation, SEO, Cloud, telemetry, automation, and arbitrary WordPress mutation remain out of scope.

## Automated evidence

- PHPUnit: **194 tests / 1100 assertions** — pass.
- `composer lint`: **53 PHP files** — pass.
- Focused Update contract, Ability, shared service, plugin bootstrap, registrar, and audit tests: **32 tests / 174 assertions** — pass.
- Create-focused regression: **33 tests / 174 assertions** — pass.
- Existing read-only and Create Draft suites pass unchanged in the full run.
- Strict service validation covers required fields, unknown fields, types, bounds, at least one mutable field, final all-empty content, real calendar timestamps, and the sole `0000-00-00 00:00:00` sentinel.
- Authorization tests cover fixed-type `cap->edit_posts`, final `edit_post`, existence-hiding missing/wrong-type/unauthorized errors, and authorized non-draft status conflict.
- Concurrency tests cover strict raw token matching, stale conflict before Core, and a second target read immediately before the write.
- Invariant tests cover allowlisted Core arguments, protected row fields, malicious filters, unrelated nested updates, `try/finally` filter cleanup, final re-read, definitive failure, possible-write uncertainty, and audit failure.
- Audit tests verify the fixed content-free Update event and reuse of the bounded 20-event local store.
- Registrar tests freeze the exact twelve-item allowlist, with resources and prompts still empty.

## Implementation Review remediation

Human review blocked the first candidate on four findings. All four were remediated without changing the frozen public schema, sentinel, error set, annotations, allowlist, or concurrency model:

1. **Omitted mutable fields:** the operation snapshot now includes each of `post_title`, `post_content`, `post_excerpt`, and `post_name` whose public field was omitted from that specific request. The guard and final verification preserve those values exactly, while fields explicitly supplied by the client remain open to legitimate Core/filter canonicalization. Multiple hostile-filter request shapes and the all-four-fields case are covered.
2. **Core slashing lifecycle:** protected raw strings are restored to `wp_insert_post_data` using `wp_slash()` while integers retain their type. The Update test stub now passes slashed data into the filter and persists `wp_unslash()` output, matching Core 6.9's relevant lifecycle. Password, filtered-content, and ping fields containing real backslashes survive byte-for-byte.
3. **Core-compatible empty content:** the editorial non-whitespace regex was removed. Validation now mirrors Core's falsy title/content/excerpt test together with the fixed post type's editor/title/excerpt support. Ordinary all-empty and string `"0"` combinations are rejected, while a whitespace-only `post_content` combination accepted by Core is not rejected.
4. **Post-write Throwables:** final get/verification, output and link generation, and audit finalization now execute inside a narrow post-write `Throwable` boundary. Any exception after Core may have written returns `wp_auto_mutation_state_uncertain` without leaking details or rolling content back. Deterministic permalink/output and audit-throw tests confirm the written content remains visible. Artificial Throwable injection was intentionally limited to unit tests rather than unsafe live hooks.

The target-scoped guard still requires an Update operation, exact target ID, and private operation token. The unrelated nested-update test remains green.

## Public contract evidence

Both tools expose a strict object input with `additionalProperties=false`. Required fields are `id` and `expected_modified_gmt`; optional mutable fields are only `title`, `content`, `excerpt`, and `slug`. Service validation requires at least one mutable field.

The exact output is:

```text
id
type
status
slug
link
edit_url
modified_gmt
```

Ability annotations are `readonly=false`, `destructive=true`, and `idempotent=false`. Adapter 0.6.1 mapped these to `readOnlyHint=false`, `destructiveHint=true`, and `idempotentHint=false` in the live wire contract.

## Mutation safety evidence

- Update targets only an existing object of the fixed Post/Page type.
- The Ability and service both require the actual fixed type edit baseline; the service then requires Core `edit_post` for the target.
- Only `draft` targets are accepted. The client cannot supply status, type, author, parent, dates, password, comments, pings, menu order, taxonomy, media, meta, template, or other Core arguments.
- Immediately before Core, the service re-fetches the object, repeats type/object authorization/draft checks, and compares the latest raw `post_modified_gmt` using strict string equality.
- The check is best-effort and non-atomic. Second-level precision and the check-to-write race documented in ADR-002 remain unchanged.
- The operation-scoped final data guard is correlated by a private token and target ID. It restores protected row invariants only for the intended update and does not rewrite nested or unrelated writes.
- The final object is re-read and protected invariants are verified before success is reported.
- Definitive Core failure uses `wp_auto_content_update_failed`; exceptions, failed final verification, and failed audit after a possible write use `wp_auto_mutation_state_uncertain`.

## Live WordPress and MCP evidence

Disposable environment, removed after validation:

- WordPress `6.9`;
- PHP `8.1.34`;
- MariaDB `11.8.9`;
- official MCP Adapter `0.6.1`;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only;
- Application Password authentication;
- Streamable HTTP protocol `2025-11-25`.

Observed results:

- authenticated `initialize` returned HTTP 200;
- `notifications/initialized` returned HTTP 202;
- `tools/list` returned exactly twelve names, ending with `wp-auto-post-update` and `wp-auto-page-update`;
- both live wire schemas were strict and exactly matched the frozen input/output contracts;
- both live wire Hint annotations matched the frozen Ability annotations;
- a newly created Post draft with Core's `0000-00-00 00:00:00` sentinel updated successfully and returned the final raw Core timestamp;
- a child Page draft updated successfully while preserving its original author and nonzero parent;
- `wp-auto-post-get` and `wp-auto-page-get` immediately returned the updated stored fields, the same target IDs, and the exact `modified_gmt` values returned by their corresponding Update calls;
- a retry with the stale sentinel returned the content-conflict message and did not write;
- an authorized published target returned the draft-only status-conflict message;
- an unknown `status` property was rejected by Adapter schema validation;
- an Author identity attempting to update another Author's draft received the same existence-hiding message as a missing target;
- Post Update invoked with a Page ID returned the same existence-hiding message and left the Page unchanged;
- anonymous transport returned HTTP 401 and an authenticated identity without `read` returned HTTP 403;
- Post and Page Update each wrote exactly one private audit event with only the frozen attribution/timestamp fields;
- the unrelated published target, other author's draft, Page parent, and object authors remained unchanged;
- MCP session DELETE returned HTTP 200.

Targeted remediation smoke additionally confirmed:

- title-only Update under a temporary hostile `wp_insert_post_data` filter allowed the explicitly requested title to be filtered while preserving omitted content, excerpt, and slug exactly;
- protected password, filtered-content, and ping values containing backslashes survived the real Core filter/slashing lifecycle;
- a fresh Core sentinel draft round-tripped successfully through Update;
- the stale pre-update token was rejected after the successful write;
- a Post update setting title/excerpt empty and content to spaces, a tab, and a line break succeeded because Core treats that content as non-empty;
- the normal Page Update → Page Get path continued to return matching IDs, content, and `modified_gmt`.

The canonical endpoint remains `/wp-json/wp-auto/mcp`. The disposable install used the equivalent query route `/index.php?rest_route=/wp-auto/mcp` because pretty-permalink rewrites were not enabled.

## Cleanup

All temporary users, roles, Application Passwords, Posts, Pages, audit metadata, and database state were removed with the disposable environment. The uniquely named WordPress, WP-CLI, and MariaDB containers, named database volume, and Docker network were deleted. No credentials, response files, validation scripts, containers, volumes, or generated artifacts remain in the repository.

## Formal seal

Local verdict:

```text
PASS — Phase 1.3.2 formally sealed on main
```

PR #10 passed its Implementation Review and Merge Gates and was merged by rebase. The merged runtime baseline is `main@b37cf3d80bf97fff5e01305f125b4f9b2ff7711b`; the exact post-merge main `PHP Quality` Run `33575020188` completed successfully. This baseline contains the validated twelve-tool runtime before the documentation-only seal-normalization commit.

## Next checkpoint

Phase 1.3.3 — Mutation Security / Audit Freeze

Phase 1.3.3 implementation is not started by this seal-normalization task.
