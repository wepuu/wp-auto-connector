# Phase 1.6.1 Rank Math SEO Get Validation

Status: **Implementation checkpoint complete; Phase 1.6 seal deferred to 1.6.3**

## Scope

Phase 1.6.1 adds only the independent Rank Math 1.0.278 read adapter and the
provider-neutral `wp-auto/seo-get` Ability/MCP tool. It does not implement SEO
Update, mutation audit state, provider REST/MCP delegation, external requests,
Yoast/AIOSEO adapters, or arbitrary metadata access. The ordered Direct MCP
runtime moves from twenty-one to exactly twenty-two tools.

## Candidate and admitted artifact

- Development baseline: `main@daecd1a`; working candidate on
  `feat/phase-1-6-0-seo-contract`.
- WordPress Core: 7.1 official ZIP, SHA-256
  `d1ae02b5ae18428031ffc3943659fa87ab361d827f4aa804adf9276e4dc75df6`.
- Rank Math: 1.0.278, SHA-256
  `06b7b30b0d7f350fae929519c9c5265488c7b1f24ebe5650988de90975a1e73f`.
- Yoast SEO: 28.4, SHA-256
  `93eba5afc65149967a4bb4906bc8fdebf92f4a65ee02bec97f5c01a7e14e7028`.
- All in One SEO: 5.0.1.1, SHA-256
  `22599202e4f9ceafa71084d4b1cead8fa0a1938abfe3706b54ecad8d9a0227c2`.
- Plugin Check: 2.1.0 official ZIP, SHA-256
  `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.
- The provider adapter reads only the five frozen `rank_math_*` metadata keys
  through the WordPress Metadata API and requires the provider's effective
  `rank_math_onpage_general` capability.

## Automated contract evidence

- Strict positive-ID input and provider-neutral output schemas are covered.
- Exact provider-version admission, provider absence/conflict, capability
  denial, missing/unsupported/unauthorized existence hiding, Post/Page status
  boundaries, password protection, metadata bounds, canonical URL syntax,
  keyword uniqueness, robots conflict detection, protected robots state, and
  64-hex state-token behavior are covered.
- The final full PHPUnit run passed **477 tests / 3,129 assertions**.
- `composer validate --strict`, `composer lint` (133 files),
  `composer audit --locked`, production install dry-run, and `git diff --check`
  all passed.

## Real WordPress and MCP evidence

Using local official/admitted packages only, with Rank Math 1.0.278, Yoast SEO
28.4, and AIOSEO 5.0.1.1 active together:

| Environment | Result |
| --- | --- |
| WordPress 6.9 / PHP 8.1 single site | exact ordered 22 tools; anonymous HTTP 401; authenticated SEO Get round trip passed |
| WordPress 7.1 / PHP 8.1 single site | exact ordered 22 tools; anonymous HTTP 401; authenticated SEO Get round trip passed |
| WordPress 7.1 / PHP 8.1 Multisite | exact ordered 22 tools; anonymous HTTP 401; authenticated SEO Get round trip passed |

Each SEO Get fixture was a temporary draft Post containing all five explicit
field classes plus a non-target robots directive. Public output contained no
provider name/key or protected state, and every temporary Post/Application
Password was removed after the scenario.

### Failure-path matrix (WordPress 7.1 / PHP 8.1)

The same isolated single-site container was rerun with Rank Math 1.0.278,
Yoast 28.4, and AIOSEO 5.0.1.1 active. The matrix discovered the exact
ordered 22-tool runtime and exercised the negative paths over real MCP
sessions plus direct Ability calls. Every temporary credential was deleted by
UUID in `finally`, and the provider/version flags were reset:

| Scenario | MCP result | Direct Ability result |
| --- | --- | --- |
| Subscriber without SEO read capability | tool error; no provider/secret text | `wp_auto_seo_not_found` |
| Unreadable draft Post | tool error; existence hidden | `wp_auto_seo_not_found` |
| Password-protected published Post | tool error; existence hidden | `wp_auto_seo_not_found` |
| Rank Math deactivated | tool error; provider-neutral text | `wp_auto_seo_provider_unavailable` |
| Rank Math version forced to unsupported 1.0.277 | tool error; provider-neutral text | `wp_auto_seo_provider_unavailable` |
| Metadata API exception | tool error; no exception text | `wp_auto_seo_state_unsupported` |

The run reconfirmed `tools/list` = 22 with `wp-auto-seo-get` last and
administrator SEO Get success. No Rank Math, Yoast, AIOSEO, raw meta keys, or
the injected exception string appeared in public responses. No PHP Warning,
Deprecated, Parse, Fatal, or unhandled exception diagnostic was emitted by the
plugin request path. The adapter's expected notice-level WP_Error
observability lines are sanitized, provider-neutral, and contain no secret or
raw metadata.

## Plugin Check 2.1.0 evidence

The rebuilt release-like `wepuu-auto-connector/` package was checked in the
WordPress 7.1 Multisite CLI container with the official Plugin Check 2.1.0
package. Both commands exited 0 and reported `Success: Checks complete. No
errors found.` with zero errors and zero warnings:

- default static check: `wp plugin check wepuu-auto-connector --format=strict-json`
- runtime-enabled check: `wp plugin check wepuu-auto-connector
  --require=/var/www/html/wp-content/plugins/plugin-check/cli.php
  --format=strict-json`

The package contained only production PHP, Composer manifests and locked
production dependencies (including private MCP Adapter 0.6.1 runtime); tests,
tools, documentation, ZIPs and provider plugin entry points were excluded.

## Security review and cleanup

- The candidate-baseline security review completed with **0 findings** and
  complete coverage across 14 prepared source items. The final immutable
  `daecd1a..5211948` range review also completed with **0 findings**, complete
  coverage, and no deferred paths (Codex Security scan
  `e1ba9674-a58a-4d89-8f72-dac630425d8a`).
- GitHub Actions `quality` completed successfully for PR #23
  ([run 34457023241](https://github.com/wepuu/wp-auto-connector/actions/runs/34457023241)).
- The review scope covers capability enforcement, arbitrary metadata exposure,
  provider conflict/unavailable handling, dependency/runtime isolation, secret
  handling, and outbound-request regressions.
- Temporary posts and Application Passwords used by the live scenarios were
  removed. The task-scoped Docker project, its containers/volume/network, and
  the ignored failure-matrix and release-like build directories were deleted;
  unrelated historical wp-env projects were left untouched. The user-provided
  source ZIPs under `D:\Codex` are not modified.

## Remaining seal gates

This records a complete Phase 1.6.1 implementation checkpoint, including the
final immutable range review and PR quality gate. The formal Phase 1.6 seal is
deferred to Phase 1.6.3. Phase 1.6.2 remains unimplemented and unauthorized.
