# Phase 1.3.3 Uninstall Cleanup Validation

Status: LANDED ON MAIN; LIVE CLEANUP AND SECURITY GATES PASS

This record covers the explicit-uninstall private-state cleanup implementation
authorized by ADR-004. It does not seal Phase 1.3.3, advance Phase 1.3.4, change
the twelve-tool MCP surface, or authorize a release.

## Validated scope

- `uninstall.php`
- `src/Uninstall/PrivateStateCleanup.php`
- `tests/UninstallTest.php`
- the uninstall fixtures in `tests/bootstrap.php`

The closed persistence inventory remained unchanged:

1. `wp_auto_connector_idempotency_[0-9a-f]{64}` options;
2. `wp_auto_connector_mutation_audit_lock_[0-9a-f]{64}` options;
3. `_wp_auto_connector_mutation_audit` post metadata.

No new MCP tool, REST route, external request, telemetry, dependency, runtime
SQL authority, publish ability, or delete ability was introduced.

## Automated gates

The final candidate passed:

- focused uninstall suite: 27 tests, 122 assertions;
- complete PHPUnit suite: 288 tests, 1506 assertions;
- `composer validate --strict`;
- `composer lint`: 57 of 57 PHP files;
- `composer audit --locked`: no known advisories;
- `git diff --check`.

The worktree reused the locked Composer vendor directory from the source checkout;
no dependency file changed and no production dependency was installed.

## Disposable WordPress probe

The live probe used a disposable `@wordpress/env` 11.14.0 environment with:

- WordPress 6.9;
- PHP 8.1.34;
- MariaDB 12.3.3;
- Plugin Check 2.1.0.

### Single site

Both an in-process WordPress uninstall include and the WP-CLI
`plugin uninstall --skip-delete` lifecycle passed. Each path proved that:

- exact-valid idempotency and audit-lock options were physically absent;
- the exact mutation-audit metadata key was physically absent;
- uppercase and otherwise malformed prefix-like options remained;
- unrelated options and metadata remained;
- an option primed into the Core cache read back as absent after deletion;
- the uninstall handler emitted no output.

A direct HTTP request to `uninstall.php` returned HTTP 200 with a zero-length body
and performed no cleanup.

### Multisite

The same environment was converted to a three-site subdirectory network. The
in-process path and a correctly network-deactivated WP-CLI uninstall both passed.
For blogs 1, 2, and 3, exact private options and audit metadata were absent while
malformed and unrelated fixtures remained. The original blog and switch stack
were restored.

The first formal WP-CLI attempt exposed two real Core compatibility defects that
the test double had hidden:

1. a fresh multisite request can begin without `_wp_switched_stack` and
   `switched` globals; absence must be treated as Core's empty/false initial
   state rather than an unrecoverable context;
2. WordPress 6.9 can begin with `$wpdb->blogid` as canonical string `"1"` and
   restore it as integer `1`; the identifier must be validated and normalized
   before semantic context comparison.

The implementation now normalizes only these Core-equivalent initial states.
It does not edit the switch stack manually, weaken table-name comparison, or
continue after an unproven restoration. A deterministic regression test starts
with both missing switch globals and a string `wpdb->blogid`.

All probe posts, options, metadata, sites, credentials, containers, volumes,
networks, local build images, configuration, and generated files were removed.

## Plugin Check result

A full scan of the Git worktree was non-authoritative because it correctly
reported development-only hidden files, tests, and the disposable probe. A
second scan used a release-like copy containing only the plugin entry files,
`src`, `readme.txt`, and `LICENSE`.

The release-like scan reported one error and six warnings:

- error: `Tested up to: 6.9` is behind the checker current version, WordPress 7.1;
- warnings: the existing WP-Auto name/slug trademark checks and missing
  `languages` directory;
- warnings: the two already reviewed direct-database boundaries in
  `AtomicOwnershipStore` (ADR-003) and `PrivateStateCleanup` (ADR-004).

The ADR-004 warning is expected because Plugin Check cannot infer that the query
argument was produced by one of three fixed prepared read families. Source review
confirmed there is no raw uninstall SQL write and no caller-controlled SQL syntax.

The `Tested up to` error is a repository-wide release metadata blocker. This task
must not silently raise the declared compatibility target from 6.9 to 7.1, change
the approved slug/name, or add release-only structure. Those decisions require a
separate, explicitly authorized release/readiness task.

Engineering disposition for this candidate:

- the WordPress 7.1 declaration, naming/trademark review, and release packaging
  warnings remain release blockers and are not silently changed here;
- the two DirectDB warnings remain bounded by ADR-003 and ADR-004 and were
  re-reviewed as part of the current-worktree security gate;
- none of the Plugin Check findings was introduced by a broadened public contract,
  new external service, telemetry, or an additional mutation ability;
- these release-wide findings do not require mixing unrelated release metadata into
  the ADR-004 implementation commit, but they must be resolved or formally accepted
  before a public release.

## Security review

Two security reviews bind the runtime candidate snapshot taken before the
documentation-only validation-record updates:

`codex-security-snapshot/v1:sha256:4a77c0cc540df08653ed3a8c47dccdb1290e1c56194b0cdfe04306e237d50cad`:

1. Current-snapshot diff scan `9afb5b44-cc77-4196-860b-019af28b6f8a` completed
   with zero findings.
2. Repository Standard scan `184a9ea9-6ebc-472c-9875-6c45848d15bd` completed
   with complete coverage and zero findings. It reviewed the authenticated
   transport and exact twelve-tool allowlist, mutation Ability contracts, Create
   and Update services, atomic ownership and audit storage, the explicit uninstall
   lifecycle, and the remaining executable/support surfaces.

The Standard scan preflight was ready with one degraded-capacity warning: the
session provided three usable worker slots instead of the preferred six. The
independent baseline and architecture workers then failed before source review
because the workspace reported exhausted credits. The documented parent fallback
completed those reviews sequentially; this limitation is retained in the sealed
scan report and did not produce a deferred coverage surface.

The Standard report is at:

`C:\Users\admin\AppData\Local\Temp\codex-security-scans-ZAVnfL\wp-auto-connector-phase-1-3-3-uninstall-implementation-plan\95091d732be7bf08d963e2cb2ae0635673652502_20260907T035903Z_1aue2ud8\report.md`

Runtime source did not change after these scans. Documentation-only record updates
are closed by the landing handoff's diff-hygiene, secret, generated-junk, and
external-call checks; TAC enrollment is advisory and is not a project gate.

## Verdict

The scoped ADR-004 implementation, WordPress 6.9 single-site/multisite cleanup,
diff-security, and repository-security gates pass. The implementation landed on
`main` in `223c845`; exact-main CI and the final Phase 1.3.3 seal gates passed.
Release-wide Plugin Check findings remain release blockers and are not waived by
the phase seal.
