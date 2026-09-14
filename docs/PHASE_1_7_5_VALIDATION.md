# Phase 1.7.5 MCP Error Logging Validation

Status: **local observability hardening candidate complete; immutable release
package, remote site-scoped log evidence, immutable-range review, and Git landing
remain pending**

Development baseline: `main@864da2df1daea0326798cec2ffd50fb7204cd937`.
The implementation is currently an uncommitted working-tree candidate and is
not a release artifact.

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

The unit matrix proves that normal connector 403/404/409 results and transport
denials produce no PHP log line, while state-uncertain/5xx and unexpected
integration errors remain visible. It also proves severity normalization,
newline neutralization, and removal of supplied secrets, content, exception
text, and arbitrary nested context.

Codex Security scan `79f76cc1-0a09-45aa-9cbb-d7a86ac7ebe0` reviewed all five
security-relevant working-tree files reported by its deterministic inventory:
the Composer command registration, custom error handler, MCP server registrar,
release builder, and plugin bootstrap. Coverage was complete with zero
findings. Because the source is not yet an immutable commit, this result does
not replace the final post-commit range review.

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

This run mounted the working source and therefore is not evidence for an
immutable exact-ZIP release candidate. After Git landing authorization, the
candidate must be committed, rebuilt from the immutable commit, and pass exact
ZIP activation, Plugin Check 2.1.0 static/runtime-enabled checks, and the final
immutable security range review.

The fixed candidate must also be installed on the remote HTTPS test site before
capturing the site-scoped post-cleanup log window. The currently installed
remote package predates this handler. Remote deployment, credentials, server
log access, Git operations, versioning, tagging, and WordPress.org submission
are not performed by this checkpoint.
