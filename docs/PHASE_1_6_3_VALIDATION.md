# Phase 1.6.3 SEO Integration and Security Validation

Status: **implementation complete; Phase 1.6 seal pending final Git landing**

Validation date: 2026-09-11

This checkpoint completes the integration and security validation for the
provider-neutral SEO Get and Draft Update tools. It adds no Ability or MCP
tool. The candidate runtime remains exactly twenty-three ordered tools, with
`wp-auto-seo-get` followed by `wp-auto-seo-update` at the end of the dedicated
allowlist.

## Scope and admitted artifacts

- Baseline: `main@189090f0b5cb4d04893806aa7da576be32dad133`.
- Candidate branch: `feat/phase-1-6-2-seo-update`.
- WordPress 7.1 Core ZIP: `D1AE02B5AE18428031FFC3943659FA87AB361D827F4AA804ADF9276E4DC75DF6`.
- Plugin Check 2.1.0 ZIP: `6FF4BD2145F3BEFCF907DF158CC466B1649DAFED5686DE8369907403C3013FC4`.
- Rank Math 1.0.278 ZIP: `06B7B30B0D7F350FAE929519C9C5265488C7B1F24EBE5650988DE90975A1E73F`.
- Yoast SEO 28.4 ZIP: `93EBA5AFC65149967A4BB4906BC8FDEBF92F4A65EE02BEC97F5C01A7E14E7028`.
- All in One SEO 5.0.1.1 ZIP: `22599202E4F9CEAFA71084D4B1CEAD8FA0A1938ABFE3706B54ECAD8D9A0227C2`.
- WordPress 6.9 used the locally cached official `wordpress:6.9-php8.1-apache`
  image; the running Core version was checked with WP-CLI.

No package or executable code was downloaded for this validation. The
release-like package was assembled under an ignored build directory and used
only the locked production Composer dependencies. The package contained the
private MCP Adapter 0.6.1 runtime; the nested public Adapter entry point,
tests, tools, documentation, ZIPs, and development dependencies were absent.

## Defect found and fixed

The first live HTTP probe exposed a real ordering defect in SEO Update. JSON
object members are unordered, but an HTTP client could decode `robots` as
`follow,index`. The service validated the set of keys and then retained that
order in the desired state. Final state verification uses the canonical
`index,follow` order and consequently returned
`wp_auto_seo_state_uncertain` after a successful robots write.

Normalization now rewrites the accepted pair to the fixed `index,follow`
shape before merging and writing. A regression test covers the reversed input
order. No public schema, error family, metadata key, provider behavior, or
tool order changed.

## Automated gates

```text
composer validate --strict                         PASS
PHPUnit                                             PASS (486 tests, 3,172 assertions)
composer lint                                      PASS (139 files)
composer audit --locked                            PASS (no advisories)
production Composer install dry-run                PASS
git diff --check                                   PASS
```

The focused SEO Update suite passed after the fix with 7 tests and 22
assertions. The new test proves that reversed `robots` member order produces a
successful update and preserves non-target directives.

## Real WordPress and authenticated Streamable HTTP

The local Docker matrix used PHP 8.1, MariaDB 11.8.9, the final release-like
package, Rank Math 1.0.278, Yoast SEO 28.4, and AIOSEO 5.0.1.1 active together.

| Environment | Discovery | SEO Get/Update | Auth boundary |
| --- | --- | --- | --- |
| WordPress 6.9 single site | exactly 23 ordered tools; SEO Update last | multi-field update, robots-only update, stale no-op, stale conflict | anonymous initialize 401 |
| WordPress 7.1 single site | exactly 23 ordered tools; SEO Update last | multi-field update, robots-only update, stale no-op, stale conflict | anonymous initialize 401 |
| WordPress 7.1 Multisite | exactly 23 ordered tools; SEO Update last | multi-field update, robots-only update, stale no-op, stale conflict | anonymous initialize 401 |

The successful update response contained the complete provider-neutral record,
all five changed fields, a new 64-hex state token, and `no_op=false`. A second
request with the stale pre-update token and the already-applied target state
returned `no_op=true`; a stale request with a different title returned the
generic conflict. The response did not contain provider names, metadata keys,
or protected state.

The three providers were loaded together without duplicate MCP routes or
provider-native tools/resources/prompts. Rank Math was temporarily deactivated
in the 7.1 site and the same authenticated Get call returned the generic
provider-unavailable result; Rank Math was restored before the remaining
checks. Provider conflict, unsupported-version, and metadata-exception paths
remain covered by the deterministic unit suite and fail closed.

The 7.1 negative matrix also covered:

- Subscriber access to a draft Post: permission denied and no existence leak;
- Subscriber access to a password-protected published Post: permission denied
  and no existence leak;
- Administrator access to the password-protected Post: successful Get after
  the required `edit_post` check;
- malformed state and write failures: stable semantic errors without raw meta
  or exception text.

The Multisite release-like package also passed both Plugin Check modes with the
same zero-error/zero-warning result. Explicit uninstall was exercised on the
6.9 single-site, 7.1 single-site, and 7.1 Multisite fixtures: the private
`_wp_auto_connector_seo_mutation_audit` postmeta and connector options were
absent afterwards, while Rank Math's `rank_math_*` metadata remained intact.

The first live probe found and corrected the robots ordering issue before this
matrix was accepted. After the fix, no live request returned an uncertain state
for a proven successful write.

## Plugin Check 2.1.0

The release-like package passed both official checks on WordPress 6.9 and 7.1:

```text
default static check:       exit 0, Success: Checks complete. No errors found.
runtime-enabled check:      exit 0, Success: Checks complete. No errors found.
```

The runtime-enabled command loaded Plugin Check's `cli.php`. The package did
not include the Plugin Check plugin, provider ZIPs, tests, tools, or generated
diagnostic scripts.

## State, privacy, and cleanup

- Protected Post fields, status, author, taxonomy, featured image, and content
  remained unchanged by SEO metadata writes.
- Robots writes replaced only `index`/`follow` directives and retained the
  provider's non-target directives.
- SEO attribution remained private, value-free, and bounded to the newest 20
  events.
- No WePuu outbound HTTP request, provider REST/MCP call, Content AI call, or
  external service was made.
- Temporary Posts, users, Application Passwords, provider toggles, diagnostic
  scripts, containers, named volumes, networks, and release-like directories
  are removed after the final validation run.

## Security review and final disposition

The completed working-tree Codex Security scan
(`eac0f814-41de-4c47-9eb3-4b039aa2f171`) reviewed the changed SEO service with
complete coverage and zero findings. The final immutable Codex Security scan
(`5f45bdd7-318a-4b3d-a535-282013871f39`) covered the complete production
source range `origin/main@189090f0b5cb4d04893806aa7da576be32dad133..e02b31a9b4c6c9988e51d93312465241502eebfa`
with 13 changed production files, complete coverage, and zero findings.
Delegated workers were unavailable, so the parent performed the review
sequentially. The post-scan evidence commit changes documentation only. The
review specifically checked capability enforcement, arbitrary-meta exposure,
provider coexistence, state-token handling, secret isolation, dependency
loading, uninstall scope, and outbound-request absence.

After that review and GitHub Actions success, PR #24 may be changed from Draft
to Ready. Phase 1.6 becomes formally sealed only after the review evidence and
final commit range are recorded here. Phase 1.7 remains unauthorized.
