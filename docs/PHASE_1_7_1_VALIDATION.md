# Phase 1.7.1 Local Client Compatibility Validation

Status: **local client acceptance complete; Phase 1.7 release and remote HTTPS
gates remain open**

Validation date: 2026-09-12

Baseline: `main@c48ba31` plus the uncommitted Phase 1.7 documentation and probe
changes. This checkpoint does not modify production PHP, public schemas,
Composer manifests, persistence, or the ordered twenty-three-tool runtime.

## Scope and admitted artifacts

The disposable local lane used Docker Desktop, WordPress Core 7.1, PHP 8.1,
and the local endpoint `http://127.0.0.1:8889/wp-json/wp-auto/mcp`. Rank Math,
Yoast SEO, All in One SEO, and Plugin Check were loaded only from the five
user-supplied archives. Plugin Check was enabled only while its checks ran.

The archives were admitted before any plugin code was executed. SHA-256 and
embedded versions were:

| Artifact | SHA-256 | Version |
| --- | --- | --- |
| WordPress Core | `D1AE02B5AE18428031FFC3943659FA87AB361D827F4AA804ADF9276E4DC75DF6` | 7.1 |
| Plugin Check | `6FF4BD2145F3BEFCF907DF158CC466B1649DAFED5686DE8369907403C3013FC4` | 2.1.0 |
| Rank Math SEO | `06B7B30B0D7F350FAE929519C9C5265488C7B1F24EBE5650988DE90975A1E73F` | 1.0.278 |
| Yoast SEO | `93EBA5AFC65149967A4BB4906BC8FDEBF92F4A65EE02BEC97F5C01A7E14E7028` | 28.4 |
| All in One SEO | `22599202E4F9CEAFA71084D4B1CEAD8FA0A1938ABFE3706B54ECAD8D9A0227C2` | 5.0.1.1 |

The release-like package contained only production source, the plugin entry
and uninstall files, license/readme, Composer manifests, production
dependencies, and private MCP Adapter 0.6.1. Tests, development tools,
documentation, archives, logs, and the public Adapter entry point were
excluded.

## Installed clients

| Client/tool | Version | Result |
| --- | --- | --- |
| Node.js | 24.19.0 | PASS |
| npm / npx | 11.17.0 / 11.17.0 | PASS |
| `@wordpress/env` | 11.15.0 | PASS |
| MCP Inspector | 2.6.0 | PASS |
| Codex CLI | 0.154.0 | PASS for the canonical 23-tool workflow |
| WorkBuddy bundled CLI | 2.137.1 | PASS for the canonical 23-tool workflow |
| Claude Code | 2.1.267 | Optional; free plan is not a gate |

Codex CLI used process-local `WP_AUTO_MCP_AUTHORIZATION` through the
`env_http_headers` override. WorkBuddy used a temporary JSON configuration with
`${WP_AUTO_MCP_AUTHORIZATION}` and `--strict-mcp-config`. No client
configuration or evidence file contains a plaintext Application Password.

## Protocol evidence

- The repository protocol probe passed Docker, authenticated initialize,
  protocol `2025-06-18`, exact count `23`, and frozen order.
- MCP Inspector 2.6.0 strict `tools/list` exited 0 and returned this exact
  order:

  `wp-auto-site-health`, `wp-auto-site-info`, `wp-auto-posts-search`,
  `wp-auto-post-get`, `wp-auto-pages-search`, `wp-auto-page-get`,
  `wp-auto-categories-list`, `wp-auto-tags-list`,
  `wp-auto-post-create-draft`, `wp-auto-page-create-draft`,
  `wp-auto-post-update`, `wp-auto-page-update`, `wp-auto-media-search`,
  `wp-auto-media-get`, `wp-auto-media-upload`, `wp-auto-media-update`,
  `wp-auto-media-set-featured`, `wp-auto-media-import-url`,
  `wp-auto-category-create`, `wp-auto-tag-create`, `wp-auto-taxonomy-assign`,
  `wp-auto-seo-get`, `wp-auto-seo-update`.

- Anonymous initialize returned HTTP 401.
- With all three SEO plugins active, discovery contained no provider-native
  tools or provider names.

## Real client smoke evidence

Codex CLI emitted a redacted JSON event sequence proving the canonical workflow:
site and content reads, draft creation/update with a modified-time
precondition, Category and Tag creation, taxonomy assignment, image upload and
featured-image assignment, SEO Get/Update, no-op, stale-token rejection, and
final invariant reads. The event result confirmed 23 tools in catalog order,
draft status, taxonomy/media/SEO persistence, no publish/delete, and no
credential pattern.

WorkBuddy emitted a redacted stream-json sequence proving the same canonical
workflow through actual `mcp__wp-auto__wp-auto-*` calls. It used a strict
per-tool MCP allowlist, `WaitForMcpServers`, and no built-in filesystem, shell,
browser, or web tools. The two taxonomy assignments were intentionally started
concurrently by the client and returned the server's documented
`state_uncertain` response; an immediate fresh Post Get verified both final
term sets, so no unresolved state remained. SEO no-op and stale-token
rejection, featured media, draft preservation, and no publish/delete were
confirmed. The earlier broad server-only allowlist was rejected by deferred
tool discovery and was not counted as a pass.

Both canonical runs used only temporary test-site fixtures. The user explicitly
authorized those fixtures to be sent to Codex/OpenAI and WorkBuddy; credentials,
real site content, and unnecessary object data were not sent or recorded.

Codex Desktop smoke was completed by the user in a new window after the
temporary local MCP registration became available. The client displayed
`wp_auto` and successfully invoked only the read-only `wp-auto-site-health`
tool. No mutation, publish, delete, administration, or provider-native tool
was invoked. The temporary registration used a loopback capability filter that
allowed only initialize, tools/list, and site-health; its URL token was not a
WordPress credential and no Authorization value was persisted.

Claude Code remains an optional Pro/Max/API-authorized compatibility lane.

## Server and negative-path evidence

The server and client runs covered the complete temporary fixture workflow:
draft Post create/update, Category and Tag creation, exact taxonomy assignment,
image upload and featured-image assignment, SEO Get/Update/no-op/stale-token,
and final invariant reads. Negative checks covered subscriber denial, existence
hiding for inaccessible and password-protected objects, Rank Math deactivation
(`provider_unavailable`), provider-neutral coexistence, and absence of
arbitrary metadata or admin tools. Unsupported versions, provider conflict,
and metadata normalization failures remain covered by the unit suite.

## Release and quality gates

| Gate | Result |
| --- | --- |
| Plugin Check 2.1.0 static | Exit 0; zero errors/warnings |
| Plugin Check 2.1.0 runtime-enabled | Exit 0; zero errors/warnings |
| Clean PHP debug-log review | Zero Warning/Notice/Deprecated/Parse/Fatal findings |
| `composer validate --strict` | PASS |
| `composer test` | PASS - 486 tests / 3,172 assertions |
| `composer lint` | PASS - 139 files |
| `composer audit --locked` | PASS - no advisories |
| Production Composer install dry-run | PASS |
| `git diff --check` | PASS |
| Codex Security exact-range diff scan | PASS - scan `5c96482a-ea3a-405a-a17b-374562203517`; immutable `daecd1a..c48ba31`, 18 changed source/config files, complete coverage, zero findings |

## Cleanup and remaining gate

Every temporary Application Password used by the client acceptance runs was
revoked; the final password listing was empty. The temporary smoke-test user,
fixtures, client JSON, DPAPI blob, proxy logs, and event captures were removed.
The named wp-env containers, volumes, and network were destroyed, and the
temporary build and project `.codex` directories were removed. No credential,
machine path, test object identifier, or client transcript is intended for
source control.

The current uncommitted Phase 1.7.1 documentation/probe changes were checked
separately for diff whitespace, credential literals, generated files, and
unapproved external calls; no finding was observed. The immutable security
scan intentionally excludes those uncommitted files and records that boundary
in its coverage manifest.

Phase 1.7.1 local client acceptance is complete: MCP Inspector, Codex CLI,
WorkBuddy, and Codex Desktop all passed their required local lanes. Remote
HTTPS validation and formal Phase 1.7 release sealing remain separate gates;
Phase 2/Cloud, telemetry, and new MCP tools remain out of scope.
