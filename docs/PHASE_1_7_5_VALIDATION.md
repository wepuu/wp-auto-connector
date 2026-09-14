# Phase 1.7.5 MCP Error Logging Validation

Status: **Phase 1.7.5 formally sealed; immutable package, Plugin Check, remote
site-scoped log, Git landing, and credential-cleanup gates complete**

Development baseline: `main@864da2df1daea0326798cec2ffd50fb7204cd937`.
Implementation commits: `cb02fd2`, `6431d04`, `bdafbab`; the final package
manifest records the complete candidate commit.

## Scope and decision

The locked MCP Adapter 0.6.1 reports every tool-level `WP_Error` through its
configured error handler. The previous configuration used the Adapter's
`ErrorLogMcpErrorHandler`, so expected not-found, permission, and stale-state
results were written to PHP stderr and surfaced as Nginx FastCGI error lines.

Phase 1.7.5 supplies a connector-owned `McpErrorHandler` through the supported
custom-server configuration. It does not patch the bundled Adapter. The
handler:

- suppresses unknown-tool, normal transport-denial, and structured
  `wp_auto_*` 4xx results as expected client control flow;
- retains unknown error families, 5xx results, state-uncertain results,
  transport failures, and thrown exceptions;
- maps retained failures to fixed event names and allows only bounded
  identifiers, error code, status, method/type, component, filter, and server
  identifiers into the log;
- never logs request arguments, content, URLs, credentials, Authorization,
  raw exception text, raw provider state, or arbitrary Adapter context.

The MCP `CallToolResult`, `isError` behavior, public error codes, permissions,
persistence, and exact twenty-three-tool order are unchanged.

## Automated validation

| Gate | Result |
| --- | --- |
| MCP error-handler unit tests | PASS - 5 tests, 20 assertions |
| MCP registrar unit tests | PASS - 5 tests, 25 assertions |
| `composer validate --strict` | PASS |
| PHPUnit | PASS - 491 tests, 3,194 assertions |
| WordPress Coding Standards | PASS - 141 files |
| `composer audit --locked` | PASS - no advisories |
| Production install dry-run | PASS |
| Working-tree security diff scan | PASS - complete coverage, zero findings |
| Immutable-range security scan (`864da2df..bdafbab`) | PASS - complete coverage, zero findings |
| Plugin Check 2.1.0 default strict | PASS - exit 0, zero errors/warnings |
| Plugin Check 2.1.0 runtime-enabled `cli.php` | PASS - exit 0, zero errors/warnings |

The unit matrix proves that normal connector 403/404/409 results and transport
denials produce no PHP log line, while state-uncertain/5xx and unexpected
integration errors remain visible. It also proves severity normalization,
newline neutralization, and removal of supplied secrets, content, exception
text, and arbitrary nested context.

Codex Security scan `79f76cc1-0a09-45aa-9cbb-d7a86ac7ebe0` reviewed all five
security-relevant working-tree files reported by its deterministic inventory:
the Composer command registration, custom error handler, MCP server registrar,
release builder, and plugin bootstrap. Coverage was complete with zero
findings. The final immutable range review `176a2f58-3d9c-462a-9868-68293e4be0e3`
also completed with zero findings across all five security-relevant files.

## Real WordPress validation

The accepted local archives were rechecked before use:

| Artifact | SHA-256 | Version |
| --- | --- | --- |
| WordPress Core | `D1AE02B5AE18428031FFC3943659FA87AB361D827F4AA804ADF9276E4DC75DF6` | 7.1 |
| Rank Math SEO | `06B7B30B0D7F350FAE929519C9C5265488C7B1F24EBE5650988DE90975A1E73F` | 1.0.278 |

An isolated official wp-env 11.15.0 environment ran WordPress 7.1 on PHP
8.1.34 with Rank Math 1.0.278 and WePuu Auto Connector 0.1.0 active. An
authenticated Streamable HTTP probe negotiated MCP 2025-06-18, discovered
exactly 23 tools with SEO Get/Update last, successfully called Site Health,
and called Post Get for a deliberately absent object. The client still
received the expected MCP error result for the absent object.

The WordPress debug log was truncated immediately before the probe. After the
successful read and expected 404 path it remained zero bytes, with zero
connector event, PHP Warning, Notice, Deprecated, Parse, or Fatal entries. The
short-lived Application Password was revoked and a final listing found no
matching credential. All task-specific containers, volumes, networks,
extracted archives, configuration, and probe files were removed.

## Remaining release gates

The immutable ZIP contains 651 production entries, and its extracted payload
activated on WordPress 7.1. Both Plugin Check 2.1.0 modes passed with exit code
0 and no errors or warnings. The ignored package `manifest.json` records the
authoritative source commit and SHA-256 for the current build.

Two independent clean builds from the same immutable commit, lock file, PHP /
Composer toolchain, and `SOURCE_DATE_EPOCH=946684800` produced the same ZIP
SHA-256 (`9f57fff4caebd5e6eb599ffea3762740c208d7bd368d0264f822c59cb71e5b79`)
and identical per-file manifests. The build environment must keep these inputs
fixed; an earlier comparison used different environment inputs and was not a
valid reproducibility test.

The fixed candidate has now been installed on the remote HTTPS test site, and
the site-scoped post-cleanup log review passes for the recorded window. Git
operations, versioning, tagging, and WordPress.org submission are not performed
by this checkpoint.

## Remote HTTPS candidate spot-check

The fixed release candidate was installed on the administrator-provided remote
HTTPS test site and exercised with process-local credentials. The redacted
results below contain no usernames, object IDs, authorization values, URLs
beyond the documented endpoint, or response bodies:

| Gate | Result |
| --- | --- |
| Repository protocol probe | PASS - HTTPS, MCP 2025-06-18, required client commands present |
| Authenticated `initialize` and strict `tools/list` | PASS - exactly 23 tools in catalog order |
| `wp-auto-site-health` | PASS - WordPress 7.1, PHP 8.2.28, HTTPS and Adapter 0.6.1 reported healthy |
| Absent `wp-auto-post-get` target | PASS - expected `isError` existence-hidden result |
| Anonymous `initialize` | PASS - HTTP 401 |
| Mutation/deletion side effects | PASS - none requested |
| Local credential cleanup | PASS - DPAPI file removed after the run |
| Site-scoped PHP-FPM/Nginx review | PASS - no matching records in PHP-FPM or site/global Nginx error logs; WordPress `debug.log` absent |
| Remote credential cleanup | PASS - administrator confirmed the temporary Application Password was revoked |

The final authenticated read-only request completed in the server-log review
window `2026-09-14T08:51:08Z` through `2026-09-14T08:51:12Z`. The root SSH
review of `/www/server/php/82/var/log/php-fpm.log`,
`/www/wwwlogs/www.itravoce.com.error.log`, and `/www/wwwlogs/nginx_error.log`
returned no records in that window; the WordPress `debug.log` file was absent.
The site administrator subsequently confirmed that the temporary remote
Application Password was revoked. Phase 1.7.5 is formally sealed on `main`;
public release tagging and WordPress.org submission remain separate decisions.
