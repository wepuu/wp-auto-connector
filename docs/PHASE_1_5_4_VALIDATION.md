# Phase 1.5.4 Taxonomy Integration and Security Validation

Status: **VALIDATION CANDIDATE; SEAL BLOCKED**

Validation date: 2026-09-09

Validation head: `b5b77e675ebc893857f89b29351e03c0fde4fc30`

The Phase 1.5.4 run validates the Category Create, Tag Create, and draft Post
Taxonomy Assignment candidates without changing the frozen twenty-one-tool
runtime. It records the completed automated and local authenticated MCP smoke
checks. The formal Phase 1.5 seal remains blocked until a supported Plugin
Check 2.1.0 artifact and the required higher-version WordPress matrix are
available.

## Git and automated baseline

The working tree was clean before validation. The candidate branch contains
the following unpublished taxonomy implementation and validation commits:

- `37a701b9f446685dd4677745ad4dede79bcf760d` — Category/Tag Create;
- `79fde2986926fd18e7759c3488f98d492e048bee` — Taxonomy Assignment;
- `421094873a3c93f0435a01b6ad517bc7ba6fe37b` — initial validation record;
- `b5b77e675ebc893857f89b29351e03c0fde4fc30` — cross-request idempotency evidence.

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

Two independent authenticated Streamable HTTP sessions were then used for
cross-request Create arbitration. For Category Create, one request observed
`wp_auto_idempotency_in_progress` while the other completed; a later matching
request returned `idempotency_replayed=true`, and a same-key different-payload
request returned the idempotency conflict. The exact Category name mapped to
one Term. For Tag Create, two concurrent sessions converged on one Term with
one fresh result and one `idempotency_replayed=true` result. These checks
confirmed the shared persistent ownership path across real HTTP requests.

All temporary Application Passwords, fixtures, containers, volumes, and the
network were confined to the disposable environment and removed afterward.

## Real assignment concurrency and state integrity

Two independent authenticated MCP sessions repeatedly submitted mutually
exclusive Category replacements against the same draft Post and the same
`expected_term_ids` set. The final classified 10-round matrix produced:

- 9 successful changed responses;
- 9 stale-set conflicts;
- 2 fail-closed `wp_auto_taxonomy_state_uncertain` responses;
- 9 single-winner Category sets and 1 interleaved two-Category union.

The union is direct evidence of the ADR-006 limitation: WordPress Core term
replacement is not an atomic compare-and-swap, so two authorized writers can
interleave after both precondition reads. The service did not claim a
serializable result for the uncertain request. Every round re-read the actual
Core relationship set and restored the baseline with that exact set as the
next precondition; no blind rollback was used.

Across the matrix, all protected Post fields, the protected custom metadata,
and the non-target Tag set remained byte-for-byte/logically unchanged. The
private assignment audit remained bounded (19 events after the final run,
within the newest-20 limit). This passes the real-runtime assignment-race gate
under the explicitly documented best-effort concurrency contract; callers
must still treat `wp_auto_taxonomy_state_uncertain` as requiring a fresh read.

## Uninstall verification

The plugin uninstall procedure was run with WP-CLI's `--skip-delete` option
because the plugin source was mounted read-only. Post-uninstall verification
returned no rows for:

- WP-Auto private option names;
- the three post audit metadata keys;
- the taxonomy term audit metadata key.

The temporary database was then destroyed with the rest of the isolated
environment.

## Real Multisite private-state cleanup

A fresh isolated WordPress 6.9 installation was converted to a two-site
subdirectory Multisite network. The plugin was network-activated, and each
physical site received a fresh exact taxonomy-idempotency option, an invalid
same-prefix near-miss option, one taxonomy assignment audit in postmeta, and
one taxonomy Create audit in termmeta.

After explicit network deactivation and `wp plugin uninstall --skip-delete`,
both sites independently proved:

- the exact taxonomy-idempotency option was absent;
- the near-miss option was preserved;
- the exact taxonomy post audit key was absent;
- the exact taxonomy term audit key was absent.

The plugin remained present but inactive because `--skip-delete` was required
for the read-only source mount. Successful cleanup of both physical sites and
preservation of both near-miss options confirms the real Multisite traversal,
site isolation, exact-name filtering, and context restoration path.

## Exact-diff security review

Codex Security diff scan
`9a12e2ea-4f4a-4b22-9323-529682dc177f` reviewed the exact runtime range
`487cdaf8051d4465480b16bec473c6fc8de77338..b5b77e675ebc893857f89b29351e03c0fde4fc30`.
All 15 changed executable source files were reviewed across MCP exposure,
WordPress authorization, strict schemas, taxonomy mutation, persistent
idempotency, audit minimization, and explicit uninstall cleanup. The scan
completed with full recorded coverage and **zero reportable findings**.

The review retained the documented assignment-concurrency limitation as
residual product risk rather than treating it as an atomic compare-and-swap
guarantee. The dedicated real-runtime assignment-race validation above
confirmed that behavior without identifying a security bypass.

## Remaining release blockers

1. WordPress 7.1 was not available in the local Docker cache. The 6.9 run
   cannot be presented as 7.1 compatibility evidence.
2. Plugin Check 2.1.0 is not installed locally. Repository policy prohibits
   downloading and executing a remote PHP plugin during this run. A supported
   local official artifact is required; no Plugin Check result is inferred
   from PHPUnit, lint, or the MCP smoke test.

The real assignment race/state-integrity, Multisite private-state cleanup,
and exact runtime diff security gates are complete. If runtime code changes,
the affected gates must be repeated against the new candidate head.

## Verdict

```text
Automated quality gates = PASS
WordPress 6.9 activation + taxonomy smoke = PASS
Authenticated Streamable HTTP discovery = PASS (exactly 21 tools)
Permission and stale-precondition smoke = PASS
Cross-request Create idempotency (Category + Tag) = PASS
Assignment race + protected state integrity = PASS (best-effort non-CAS semantics confirmed)
Uninstall private-state cleanup = PASS
Real two-site Multisite private-state isolation/cleanup = PASS
Exact-diff security review = PASS (15/15 surfaces, zero findings)
WordPress 7.1 compatibility = BLOCKED (image unavailable)
Plugin Check 2.1.0 = BLOCKED (approved local artifact unavailable)
Full Phase 1.5.4 seal = PENDING
Next action = obtain approved local WordPress 7.1 and Plugin Check 2.1.0 artifacts, then rerun the blocked release matrix on main
```
