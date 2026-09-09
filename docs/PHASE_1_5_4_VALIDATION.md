# Phase 1.5.4 Taxonomy Integration and Security Validation

Status: **VALIDATION CANDIDATE; SEAL BLOCKED**

Validation date: 2026-09-09

Validation head: `79fde2986926fd18e7759c3488f98d492e048bee`

The Phase 1.5.4 run validates the Category Create, Tag Create, and draft Post
Taxonomy Assignment candidates without changing the frozen twenty-one-tool
runtime. It records the completed automated and local authenticated MCP smoke
checks. The formal Phase 1.5 seal remains blocked until a supported Plugin
Check 2.1.0 artifact and the required higher-version WordPress matrix are
available.

## Git and automated baseline

The working tree was clean before validation. The candidate branch contains
the two unpublished taxonomy implementation commits:

- `37a701b9f446685dd4677745ad4dede79bcf760d` — Category/Tag Create;
- `79fde2986926fd18e7759c3488f98d492e048bee` — Taxonomy Assignment.

The local `main` currently points to `37a701b9f446685dd4677745ad4dede79bcf760d`
and `origin/main` points to `487cdaf8051d4465480b16bec473c6fc8de77338`.
No Git history operation was performed by this validation record.

Quality gates passed:

- `composer validate --strict`;
- PHPUnit 9.6: **441 tests / 2,465 assertions**;
- `composer lint`;
- `composer audit --locked` — no security advisories;
- production dependency install dry-run;
- `git diff --check`.

The diff review found no new runtime shell execution, arbitrary evaluation,
or undocumented outbound request. Direct database access remains limited to
the already approved AtomicOwnership and uninstall verification paths.

## Real WordPress and authenticated Streamable HTTP

The disposable Docker stack used cached images only:

- WordPress 6.9;
- PHP 8.1.34;
- MariaDB 11.8.9;
- bundled MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local`;
- endpoint `http://localhost:8891/index.php?rest_route=/wp-auto/mcp`.

The plugin activated successfully and the authenticated Streamable HTTP
session completed:

- `initialize` — HTTP 200;
- `notifications/initialized` — HTTP 202;
- `tools/list` — HTTP 200 with exactly 21 WP-Auto tools;
- session DELETE — successful;
- anonymous initialize — HTTP 401;
- authenticated Subscriber create attempt — MCP permission error.

The MCP flow created one draft Post, one Category, and one Tag. Category
assignment with the actual Core default Category precondition succeeded;
the subsequent identical relationship request returned `changed=false`.
Tag assignment succeeded from an empty initial set. An intentionally stale
Category precondition was rejected with the generic relationship-divergence
message, proving that Core default Category assignment is not silently
overwritten.

All temporary Application Passwords, fixtures, containers, volumes, and the
network were confined to the disposable environment and removed afterward.

## Uninstall verification

The plugin uninstall procedure was run with WP-CLI's `--skip-delete` option
because the plugin source was mounted read-only. Post-uninstall verification
returned no rows for:

- WP-Auto private option names;
- the three post audit metadata keys;
- the taxonomy term audit metadata key.

The temporary database was then destroyed with the rest of the isolated
environment.

## Remaining release blockers

1. WordPress 7.1 was not available in the local Docker cache. The 6.9 run
   cannot be presented as 7.1 compatibility evidence.
2. Plugin Check 2.1.0 is not installed locally. Repository policy prohibits
   downloading and executing a remote PHP plugin during this run. A supported
   local official artifact is required; no Plugin Check result is inferred
   from PHPUnit, lint, or the MCP smoke test.
3. The full cross-request concurrency, state-integrity, multisite, and
   exact-diff security matrix still needs to be executed on the merged `main`
   baseline.

## Verdict

```text
Automated quality gates = PASS
WordPress 6.9 activation + taxonomy smoke = PASS
Authenticated Streamable HTTP discovery = PASS (exactly 21 tools)
Permission and stale-precondition smoke = PASS
Uninstall private-state cleanup = PASS
WordPress 7.1 compatibility = BLOCKED (image unavailable)
Plugin Check 2.1.0 = BLOCKED (approved local artifact unavailable)
Full Phase 1.5.4 seal = PENDING
Next action = synchronize taxonomy commits, then rerun blocked matrix on main
```
