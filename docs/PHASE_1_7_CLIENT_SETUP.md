# Phase 1.7 Client Setup Guide

Status: **Phase 1.7.1 local client acceptance complete; remote HTTPS and release gates remain open**

This guide uses only process-local credentials and temporary configuration.
Never paste an Application Password into a committed file or recorded log.

## Process-local authentication

```powershell
$env:WP_AUTO_MCP_AUTHORIZATION = 'Basic <base64-user-colon-application-password>'
$endpoint = 'http://127.0.0.1:8889/wp-json/wp-auto/mcp'
```

Plain HTTP is allowed only for localhost development. Use HTTPS elsewhere.

## Protocol probe and Inspector

```powershell
& .\tools\phase-1-7-client-probe.ps1 -Endpoint $endpoint -Probe -RequireDocker -RequireClients
npx @modelcontextprotocol/inspector --cli $endpoint `
  --transport http --method tools/list `
  --header "Authorization: $env:WP_AUTO_MCP_AUTHORIZATION"
```

Inspector 2.6.0 is the protocol oracle and must report exactly 23 tools in the
catalog order. Do not capture the command line or Authorization value.

## Codex CLI

Codex 0.154.0 accepts temporary configuration overrides. The header map names
the environment variable; the secret stays in the current process:

```powershell
codex exec --json `
  -c 'mcp_servers.wp_auto.url="http://127.0.0.1:8889/wp-json/wp-auto/mcp"' `
  -c 'mcp_servers.wp_auto.env_http_headers.Authorization="WP_AUTO_MCP_AUTHORIZATION"' `
  -c 'mcp_servers.wp_auto.startup_timeout_sec=90' `
  -c 'mcp_servers.wp_auto.tool_timeout_sec=120' `
  -c 'mcp_servers.wp_auto.required=true' `
  'Use only the wp-auto MCP server and complete the documented acceptance workflow.'
```

Use `codex login status` without printing its output when checking account
readiness. A paid/API-authorized account is not required for the local Codex
client if the existing Codex Desktop authentication is reusable.

## WorkBuddy

WorkBuddy 2.137.1 supports Streamable HTTP MCP configuration. Put the JSON in a
temporary ignored file (the inline form is rewritten by some Windows shims),
write it as UTF-8 without a BOM, expand the header from the process environment,
and allow only MCP tools. Run the command from the admitted workspace root so
the temporary config is accepted:

```powershell
$mcpConfig = Join-Path $env:TEMP 'wp-auto-mcp.json'
$mcpJson = '{"mcpServers":{"wp-auto":{"type":"http","url":"http://127.0.0.1:8889/wp-json/wp-auto/mcp","headers":{"Authorization":"${WP_AUTO_MCP_AUTHORIZATION}"},"timeout":120000,"alwaysLoad":true}}}'
[System.IO.File]::WriteAllText($mcpConfig, $mcpJson, [System.Text.UTF8Encoding]::new($false))
$repo = (Get-Location).Path
Push-Location $repo
codebuddy -p --output-format stream-json --strict-mcp-config --mcp-config $mcpConfig `
  --allowedTools='mcp__wp-auto__wp-auto-site-health' `
  --tools='WaitForMcpServers,ToolSearch,DeferExecuteTool' `
  'Use only wp-auto MCP tools and complete the documented acceptance workflow.'
Pop-Location
```

For the full acceptance workflow, replace the single tool above with the exact
23-name allowlist generated from the catalog; do not use a wildcard. The
`alwaysLoad` setting and the initial `WaitForMcpServers` call avoid deferred
discovery races. If the installed build normalizes the server name differently,
use the exact MCP server/tool name shown by the client; never broaden
permissions. Interactive approval is preferred. A permission-bypass mode is
acceptable only in an isolated, network-restricted test harness with the exact
allowlist and no built-in tools enabled.

## Codex Desktop smoke test

The validated Desktop build did not surface project-scoped MCP entries in
`/mcp`. For a local-only smoke test, register a temporary user-level server
pointing to a loopback capability filter. The filter must require a random
one-time URL token, keep the WordPress Authorization value in process memory,
and allow only `initialize`, `tools/list`, and `wp-auto-site-health`. Remove
the user-level entry, filter, and protected credential blob after the smoke.

## Cleanup

Revoke the Application Password, delete temporary users and fixtures, remove
the ignored build directory, and destroy wp-env containers, volumes, and
networks. Finally clear the process variable:

```powershell
Remove-Item Env:WP_AUTO_MCP_AUTHORIZATION -ErrorAction SilentlyContinue
```

Claude Code remains an optional compatibility lane for Pro/Max/API-authorized
accounts and is not required for this free-client acceptance.
