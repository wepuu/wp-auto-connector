# Phase 1.5.3 Draft Post Taxonomy Assignment Validation

Status: **IMPLEMENTED; VALIDATED; PHASE 1.5 FORMALLY SEALED**

Validation date: 2026-09-09

Runtime target: exactly twenty-one explicitly allowlisted tools, with
`wp-auto/taxonomy-assign` appended after the Category Create and Tag Create
abilities.

## Scope verdict

This checkpoint implements one bounded, ID-only exact replacement operation for
the built-in `category` or `post_tag` taxonomy on an authorized draft Post.
The operation requires the taxonomy object's actual `assign_terms` capability,
the built-in Post type's actual `edit_posts` baseline, and final object-level
`edit_post`. It reads both approved relationship sets with a 51-item probe,
rejects stale expected sets and overflow, performs one `append=false` Core
replacement, verifies protected Post fields/metadata and the non-target
taxonomy, and records private bounded assignment attribution. Empty desired
sets, custom taxonomies, Pages, publishing, term update/delete, SEO, Cloud,
telemetry, and outbound requests remain out of scope.

## Automated quality gates

- `composer validate --strict`: passing.
- PHPUnit 9.6: 440 tests / 2,459 assertions, passing.
- `composer lint`: passing (with the installed PHP 8.2 runtime explicitly
  selected because the system PHP shim is unavailable).
- `git diff --check`: required before commit.

Coverage includes strict schemas and annotations, exact 21-tool registration,
capability composition, existence hiding, ID validation, no-op and stale-set
semantics, 51-item overflow, Core failure/partial-state classification,
operation-scoped invariant guards, relationship divergence, audit failure,
bounded postmeta audit history, and regression coverage for uninstall counts.

## Disposable real WordPress validation

A disposable Docker Desktop stack used cached `wordpress:6.9-php8.1-apache`,
`wordpress:cli-php8.1`, and `mariadb:11.8` images with this repository mounted
as the plugin. WordPress initialized and the plugin activated successfully.
The site registered exactly 21 `wp-auto/*` Abilities.

Using an administrator identity and real Core APIs:

1. Category assignment replaced the default Category set on draft Post 4 and
   returned `target_type=post`, `status=draft`, `changed=true`, and the exact
   resulting ID set.
2. Tag assignment replaced the Tag set on the same draft and returned the same
   fixed output shape for `post_tag`.
3. A repeated Tag request with a stale `expected_term_ids` matching the already
   satisfied desired set returned `changed=false` without another replacement.
4. The private assignment event contained only the documented IDs, actor,
   taxonomy, timestamp, and operation fields; the Post remained a draft.
5. The explicit uninstall handler removed the Post assignment audit metadata;
   the final read returned no stored value.

The temporary containers and network were removed after validation.

## Plugin Check and final seal (completed in Phase 1.5.4)

Plugin Check 2.1.0 and the final release matrix are recorded in
`docs/PHASE_1_5_4_VALIDATION.md`; the verified local artifact passed both
static and runtime-enabled checks with zero errors and zero warnings.

## Verdict

```text
Taxonomy Assignment implementation = COMPLETE IN WORKING TREE
Automated tests/lint = PASS
Real WordPress assignment (Category + Tag) = PASS
No-op and draft-state proof = PASS
Assignment audit + uninstall cleanup = PASS
Plugin Check 2.1.0 = PASS (see Phase 1.5.4 seal)
Direct MCP tools = 21
Phase 1.5.3 = FORMALLY SEALED
Phase 1.5.4 evidence = `docs/PHASE_1_5_4_VALIDATION.md`
Next checkpoint = Phase 1.6 (not started)
```
