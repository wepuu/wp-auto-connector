# Phase 1.1 Manual MCP Validation

## Preconditions

- WordPress 6.9 or later and PHP 8.1 or later.
- A production dependency install has been run with `composer install --no-dev --optimize-autoloader`, and `vendor/` is present in the installed plugin.
- WP-Auto Connector is active.
- Pretty permalinks/REST API requests work.
- The remote site uses HTTPS.
- A WordPress user with the `read` capability has an Application Password.

Open **Settings > WP-Auto Connector** and confirm the endpoint is:

```text
https://example.com/wp-json/wp-auto/mcp
```

The examples below use HTTP Basic authentication where the username is the WordPress login and the password is a WordPress Application Password. Do not use the account's normal password.

## Streamable HTTP session with curl

Set local shell variables without committing them to a file or command transcript:

```bash
MCP_URL="https://example.com/wp-json/wp-auto/mcp"
WP_USER="mcp-user"
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
```

Initialize and inspect the response headers:

```bash
curl --include --request POST "$MCP_URL" \
  --user "$WP_USER:$WP_APP_PASSWORD" \
  --header "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wp-auto-phase-1-1-check","version":"1.0.0"}}}'
```

Copy the `Mcp-Session-Id` response header into a local variable:

```bash
MCP_SESSION_ID="the-returned-session-uuid"
```

Send the initialized notification:

```bash
curl --request POST "$MCP_URL" \
  --user "$WP_USER:$WP_APP_PASSWORD" \
  --header "Content-Type: application/json" \
  --header "Mcp-Session-Id: $MCP_SESSION_ID" \
  --data '{"jsonrpc":"2.0","method":"notifications/initialized"}'
```

List tools:

```bash
curl --request POST "$MCP_URL" \
  --user "$WP_USER:$WP_APP_PASSWORD" \
  --header "Content-Type: application/json" \
  --header "Mcp-Session-Id: $MCP_SESSION_ID" \
  --data '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

Expected result: exactly one tool named `wp-auto-site-health` is present on this server.

Invoke the tool:

```bash
curl --request POST "$MCP_URL" \
  --user "$WP_USER:$WP_APP_PASSWORD" \
  --header "Content-Type: application/json" \
  --header "Mcp-Session-Id: $MCP_SESSION_ID" \
  --data '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp-auto-site-health","arguments":{}}}'
```

The structured result contains only WordPress, PHP, connector, Abilities API, MCP Adapter, REST API, and HTTPS status fields documented in `docs/MCP_TOOL_CATALOG.md`.

Terminate the session:

```bash
curl --request DELETE "$MCP_URL" \
  --user "$WP_USER:$WP_APP_PASSWORD" \
  --header "Mcp-Session-Id: $MCP_SESSION_ID"
```

## Negative checks

Repeat `initialize` without `--user`. The endpoint must reject the request with HTTP 401.

Repeat with an authenticated WordPress identity that lacks `read`. The transport must reject it with HTTP 403. Independently, direct ability execution also evaluates the ability's `permission_callback` and must deny the same identity.

Sending `tools/list` without a valid initialized session ID must fail; authentication does not bypass the Adapter's MCP session protocol.

## Optional WP-CLI inspection

When the official Adapter's WP-CLI integration is available:

```bash
wp mcp-adapter list
```

Confirm server ID `wp-auto-direct`. HTTP is the Phase 1.1 acceptance transport; WP-CLI inspection is only a local diagnostic.
