# Phase 1.3.3 Mutation Security / Audit Freeze Validation

Status: **COMPLETE — FORMALLY SEALED ON MAIN**

Seal date: 2026-09-07

Runtime seal revision: `216dc34b07eb94018addc80c9bc5c8a6807d8d4c`

This record is the authoritative Phase 1.3.3 completion seal. It supersedes
older status statements that describe SEC-1, SEC-2, SEC-3, ADR-003, ADR-004,
Git landing, or the broader audit as pending. Those records remain historical
evidence and retain their original findings and rejected alternatives.

The seal does not complete Phase 1.3, start Phase 1.3.4, authorize a release,
or authorize publishing, deletion, media, taxonomy mutation, SEO, Cloud,
telemetry, arbitrary metadata, arbitrary SQL, filesystem, or code-execution
abilities.

## Landed remediation chain

| Area | Main evidence | Final disposition |
| --- | --- | --- |
| SEC-1 Create fail-closed error privacy | `dd97120` | RESOLVED |
| SEC-2 concurrent mutation-audit integrity | ADR-003 runtime in `9ca1854`, hardened in `96f0828` | RESOLVED |
| SEC-3 concurrent Create initial ownership | ADR-003 runtime in `9ca1854`, hardened in `96f0828` | RESOLVED |
| ADR-004 explicit uninstall cleanup | `223c845` | RESOLVED |
| Remote cleartext Direct MCP | `216dc34` | RESOLVED |

The rejected `add_option()` ownership candidate remains rejected. Active
ownership uses `AtomicOwnershipStore` with a strict prepared insert-if-absent,
exact binary-safe owner release, cache finalization, and fail-closed unresolved
outcomes. No generic database abstraction or arbitrary SQL surface was added.

## Frozen runtime contract

- Direct MCP exposes exactly twelve explicitly allowlisted Abilities.
- The server exposes no resources or prompts.
- Remote Direct MCP requires WordPress-recognized HTTPS. Plain HTTP is accepted
  only when `wp_get_environment_type()` is exactly `local`.
- Transport, WordPress identity, transport `read`, Ability permission, and
  service-level mutation capability checks remain separate defenses.
- Create remains draft-only with fixed type, current actor, and root Page parent.
- Update remains draft-only with object authorization, strict raw
  `expected_modified_gmt`, documented best-effort concurrency, protected-field
  guard, and final invariant readback.
- Create idempotency remains scoped to site, actor, Ability, and key; the raw key
  and content are not persisted.
- Mutation attribution remains private, exact-schema, serialized per object, and
  bounded to the newest twenty events.
- Explicit uninstall removes only the two exact private option families and the
  exact audit metadata key, including every authoritatively enumerated Multisite
  blog, through the ADR-004 boundary.

## Exact-main automated and CI gates

The runtime seal revision passed:

- `composer validate --strict`;
- focused transport suite: 5 tests / 24 assertions;
- full PHPUnit suite: 288 tests / 1506 assertions;
- `composer lint`: 57 of 57 PHP files;
- `composer audit --locked`: no known advisories;
- `git diff --check`;
- GitHub Actions PHP Quality run `34092682639`: success;
- source inspection of the registrar: exactly twelve `::NAME` allowlist entries;
- source scans: no outbound HTTP client, telemetry, dynamic execution, or new
  arbitrary SQL/filesystem surface.

The final TLS-only remediation reused the locked Composer tools from the source
checkout. Composer manifests and dependencies did not change.

## WordPress and lifecycle evidence

The unchanged mutation and uninstall runtime retains the previously completed
WordPress 6.9 / PHP 8.1 / MariaDB live evidence:

- authenticated Direct MCP discovery and invocation with exactly twelve tools;
- capability and object-authorization denial paths;
- Post/Page draft Create, replay, Update, invariant and audit behavior;
- concurrent database arbitration for Create ownership and audit serialization;
- single-site explicit uninstall cleanup;
- three-site Multisite cleanup and exact blog-context restoration;
- direct access to `uninstall.php` performs no cleanup and emits no output.

The final secure-transport change affected only the registrar permission gate and
test fixtures. Its regression suite proves that production HTTP is rejected even
when Application Password support is forced, while HTTPS and authenticated exact
`local` HTTP retain their legitimate behavior. A new disposable wp-env run was
not required for the unchanged mutation/uninstall paths.

## Security audit chain

1. Repository Standard scan `7d3f7451-595d-4cee-a802-bf0694c505e1` bound to
   `223c845` completed with full coverage and found one Medium CWE-319 issue:
   Direct MCP did not independently reject remote cleartext HTTP.
2. The issue was reproduced at the transport callback, fixed by `216dc34`, and
   covered by a forced-support regression test.
3. Working-tree diff scan `c25d9353-8db1-49bf-86b1-d2d712a4c8ba`, bound to
   `codex-security-snapshot/v1:sha256:7deadc620a42ccecd2f3302ffa78f1b9cd9b997567f87f42d27f74d3aa8fb075`,
   completed with full changed-file coverage and zero findings.
4. Final repository Standard scan `06c225ba-69d7-411e-8d7f-8f52322fa088`, bound
   to exact `main@216dc34`, completed with full coverage and zero findings.

Final scan report:

`C:\Users\admin\AppData\Local\Temp\codex-security-scans-rjh9di\wp-auto-connector-phase-1-3-3-uninstall-implementation-plan\216dc34b07eb94018addc80c9bc5c8a6807d8d4c_20260907T065220Z_x306f3zm\report.md`

The Standard preflight was ready with a degraded-capacity warning because the
session provided three usable worker slots rather than the preferred six.
Independent workers were unavailable during the final pass because the workspace
reported exhausted credits. The parent performed the documented sequential
fallback, reconciling the earlier complete baseline and architecture reviews with
the bound zero-finding remediation diff scan. No coverage surface was deferred.

TAC status was `not_granted`. TAC is an advisory account feature, not a project
security gate, and did not authorize or block the audit.

## Plugin Check and release boundary

The existing release-like Plugin Check result remains one error and six warnings:

- `Tested up to: 6.9` trails the checker's WordPress 7.1 target;
- name/slug trademark checks;
- missing `languages` directory;
- the two reviewed ADR-003/ADR-004 direct-database warnings.

These are release-readiness blockers, not Phase 1.3.3 runtime regressions. This
seal does not raise the tested WordPress version, change the plugin identity,
waive WordPress.org review, or authorize a public release.

## Seal verdict

```text
SEC-1 = RESOLVED
SEC-2 = RESOLVED
SEC-3 = RESOLVED
exact-main BLOCKER = 0
exact-main MAJOR = 0
exact-main total findings = 0
Direct MCP tools = 12
Phase 1.3.3 = COMPLETE / FORMALLY SEALED
Phase 1.3.4 = NEXT / NOT STARTED
```

Phase 1.3.4 may now be planned as a separate task. Its work must prove the full
mutation integration matrix without adding later-roadmap capabilities.
