# Phase 1.7 Client Acceptance Contract

Status: **Phase 1.7.1 local-client acceptance complete; Codex and WorkBuddy are the required free-client lane**

Baseline: `main@c48ba31`

This contract freezes client evidence without changing production PHP,
dependencies, persistence, public schemas, or the ordered twenty-three-tool
runtime. MCP Inspector is the independent protocol oracle.

## Required environment and actors

- WordPress 7.1 with Rank Math 1.0.278 is the primary acceptance environment.
- The WordPress 6.9/7.1 single-site and Multisite matrix remains the regression
  baseline.
- A disposable administrator identity uses an Application Password. Credentials,
  fixtures, logs, and screenshots never enter source control.
- Docker is the deterministic local lane. Remote HTTPS validation remains a
  separate release gate.

## Canonical scenario

Codex CLI and WorkBuddy must each independently complete this sequence and
produce redacted JSON evidence showing actual `wp-auto` MCP calls:

1. Initialize and discover exactly twenty-three tools in catalog order; SEO
   Get and SEO Update are the final two and no provider-native tools appear.
2. Read site, post, and page data.
3. Create and update a draft with idempotency and `modified_gmt` preconditions.
4. Create Category and Tag terms and assign an exact bounded taxonomy set.
5. Upload an allowlisted image and set it as the draft's featured image.
6. Read SEO, perform Update, no-op, and stale-token checks.
7. Re-read and verify draft status, author, content, taxonomy, media, and SEO.
8. Confirm publishing, deletion, arbitrary meta, and administration tools are
   unavailable.

Codex Desktop additionally performs a smoke test that discovers and calls one
read-only `wp-auto` tool. On the validated Desktop build, the temporary server
was registered at user scope because project-scoped MCP entries were not
surfaced by `/mcp`; a loopback capability filter limited the session to
initialize, discovery, and `wp-auto-site-health`.

## Negative and security matrix

Record stable, redacted outcomes for anonymous 401; low-privilege denial;
inaccessible and password-protected object hiding; Rank Math unavailable or
unsupported; provider-neutral coexistence with Yoast/AIOSEO; malformed input;
metadata normalization failure; write failure; Origin/authentication failures;
uninstall and Multisite isolation. Provider conflict remains unit-test covered
because production admits only the pinned Rank Math adapter. No raw meta,
provider names, secrets, or protected state may be returned.

## Client matrix

- **Codex CLI 0.154.0 (required):** use temporary `-c` overrides and
  `env_http_headers` so `WP_AUTO_MCP_AUTHORIZATION` remains process-local.
- **WorkBuddy 2.137.1 (required):** use `--mcp-config` and
  `--strict-mcp-config` with an HTTP server and `${WP_AUTO_MCP_AUTHORIZATION}`;
  do not write user or project configuration.
- **Codex Desktop (smoke):** use a temporary user-level entry only when the
  Desktop build does not surface project MCP entries; keep the WordPress
  credential in process memory and limit a loopback filter to read-only
  discovery and `wp-auto-site-health`; remove all artifacts after the smoke.
- **MCP Inspector 2.6.0 (required oracle):** strict HTTP `tools/list` and
  `initialize` checks with a process-local header.
- **Claude Code:** optional Pro/Max/API-authorized compatibility lane; a free
  account is not a Phase 1.7.1 blocker.

All clients must preserve Streamable HTTP framing, authentication, and Origin
handling. No client configuration may contain a plaintext Application Password.

## Evidence and cleanup

Record client/version, transport, endpoint class, discovered count/order,
redacted tool-call events, exit status, and summarized fixture outcomes. Do not
record usernames, IDs, passwords, Authorization headers, or machine temp paths.
After every run revoke Application Passwords, remove users and fixtures, delete
temporary client/DPAPI files, destroy containers/volumes/networks and ignored
build directories, and verify a clean evidence tree.

## Acceptance gate

Phase 1.7.1 local acceptance is complete: Codex CLI and WorkBuddy completed
the canonical and core negative scenarios, Codex Desktop passed its read-only
smoke, Inspector confirmed the exact protocol, and all quality gates passed.
Remote HTTPS and formal Phase 1.7 release sealing remain subsequent gates.
