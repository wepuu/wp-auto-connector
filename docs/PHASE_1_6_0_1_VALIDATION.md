# Phase 1.6.0.1 MCP Adapter Coexistence Validation

Status: **Implementation checkpoint landed on `main`; Phase 1.6 seal deferred to 1.6.3**

Validation date: 2026-09-10

Baseline: `611a6269069d978eca00d832d296bb43a50bdc90`

## Outcome

WePuu now builds the locked official MCP Adapter 0.6.1 and PHP MCP Schema
0.1.3 into the private `WPAuto\\Connector\\PrivateMcp` namespace. Adapter
hooks, filters, CLI command, and session usermeta use the private
`wp_auto_connector_mcp_adapter_` family. A provider's public Adapter is neither
accepted nor initialized by WePuu. The public MCP endpoint and ordered
twenty-one-tool allowlist are unchanged; no SEO Ability has been added.

The deterministic build runs after Composer install/update, preserves the
locked upstream sources and licenses, and generates no runtime code during a
WordPress request. Tests compare all 503 transformed upstream PHP files plus
the manifest, hooks, namespaces, CLI command, and session key against the
locked source trees.

## Admitted local artifacts

| Artifact | Verified version | SHA-256 |
| --- | --- | --- |
| `seo-by-rank-math.1.0.278.zip` | 1.0.278 | `06b7b30b0d7f350fae929519c9c5265488c7b1f24ebe5650988de90975a1e73f` |
| `wordpress-seo.28.4.zip` | 28.4 | `93eba5afc65149967a4bb4906bc8fdebf92f4a65ee02bec97f5c01a7e14e7028` |
| `all-in-one-seo-pack.5.0.1.1.zip` | 5.0.1.1 | `22599202e4f9ceafa71084d4b1cead8fa0a1938abfe3706b54ecad8d9a0227c2` |
| `plugin-check.2.1.0.zip` | 2.1.0 | `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |

The ZIP files and extracted providers remained under ignored temporary paths
and are not part of the plugin package.

## Live matrix

WordPress 6.9 and 7.1 on PHP 8.1 passed authenticated Streamable HTTP
`initialize` and `tools/list` with WePuu alone, with each provider separately,
and with all providers together. Both provider-first and WePuu-first ordered
`active_plugins` sets passed. Every response contained exactly the frozen 21
tools in catalog order, no provider-named tool, and anonymous requests returned
HTTP 401.

WordPress 7.1 Multisite passed both all-provider load orders. The same process
reported private Adapter 0.6.1 alongside Rank Math's public Adapter 0.5.0.
Session state used `wp_auto_connector_mcp_adapter_sessions_1`. An explicit
uninstall probe removed that exact WePuu key while preserving the seeded
provider key `mcp_adapter_sessions_1`; the provider fixture was then removed.

Final clean-log probes on WordPress 6.9 single-site and WordPress 7.1
Multisite produced no PHP Warning, Fatal, Parse, Deprecated, or Notice entry.
Temporary Application Passwords were resolved by generated name and deleted by
their exact UUID in `finally` after every probe.

## Release and repository gates

The correctly named release-like root `wepuu-auto-connector/` contained only
the production plugin files, Composer manifests, production dependencies, and
generated private runtime. Official Plugin Check 2.1.0 passed both the default
static command and the runtime-enabled command loading `cli.php`, each with
exit code zero and `No errors found`.

```text
composer validate --strict                                      PASS
composer test                                                   PASS (445 tests, 2,986 assertions)
composer lint                                                   PASS (123 files)
composer audit --locked                                         PASS (no advisories)
composer install --no-dev --prefer-dist --optimize-autoloader
  --dry-run --no-interaction                                    PASS
git diff --check                                                PASS
```

Codex Security diff scan
`d6843854-3164-48cd-a8fc-b4dc5e064d15` reviewed all six changed
source/build surfaces with complete coverage and reported zero candidates and
zero findings. The scan used the working-tree snapshot over the baseline above;
no independent worker was available, so the parent completed every review row.

## Verdict and next checkpoint

Phase 1.6.0.1 satisfies the coexistence prerequisite as a complete local
implementation checkpoint and preserves the exact twenty-one-tool runtime.
SEO Get and provider writes remained outside this coexistence checkpoint;
the separately authorized SEO Get and SEO Update implementation records are
`PHASE_1_6_1_VALIDATION.md` and `PHASE_1_6_2_VALIDATION.md`.
