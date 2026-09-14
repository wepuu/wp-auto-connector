# Phase 1.7.3 Remote Codex Validation

Status: **Remote Codex acceptance complete; WorkBuddy acceptance and
temporary-account cleanup subsequently completed; formal release sealing
remains pending**

This record captures the redacted evidence for the Phase 1.7.3 remote Codex
lane. It contains no credentials, authorization headers, application-password
values, object identifiers, state tokens, temporary filesystem paths, or raw
request payloads.

## Runtime and client

- WordPress core: 7.1
- PHP: 8.2.28
- WePuu Auto Connector: 0.1.0
- Private MCP Adapter runtime: 0.6.1
- Codex CLI: 0.154.0
- MCP protocol: 2025-06-18
- Transport: authenticated Streamable HTTP over HTTPS

## Protocol gate

- Authenticated `initialize`: PASS
- Strict `tools/list`: PASS; exactly 23 tools in the frozen catalog order
- `wp-auto-seo-get` and `wp-auto-seo-update`: present at the end of the list
- Anonymous request: PASS; rejected with HTTP 401
- Site-health read: PASS
- Provider-native tools or arbitrary metadata tools: not exposed

## Administrator workflow

Codex produced real JSON event evidence for MCP tool calls. The isolated
synthetic workflow passed all of the following:

- Post and Page draft creation, deterministic idempotent replay, and updates;
- stale `modified_gmt` precondition rejection;
- Category and Tag creation and draft taxonomy assignment;
- image upload and featured-image assignment;
- SEO Get, SEO Update, no-op, and stale state-token rejection;
- final reads confirmed draft status, author, taxonomy, media, featured image,
  and SEO state;
- no `state_uncertain` result and no publish, delete, import, plugin-management,
  or non-MCP write path.

## Low-privilege workflow

The low-privilege Codex session passed the negative gate:

- protected object reads were existence-hidden;
- Post update, taxonomy assignment, featured-media assignment, SEO Get, and
  SEO Update were all denied;
- no protected call succeeded and no sensitive metadata or exception detail was
  disclosed.

## Cleanup

- The five synthetic remote fixtures were deleted only after exact type, status,
  and generated-name verification.
- Each deletion was re-read and returned HTTP 404.
- The local remote-acceptance directory, DPAPI credential files, temporary
  scripts, and Codex output logs were removed.
- At the time of the Codex run, temporary WordPress users and their Application
  Passwords were retained for the subsequent WorkBuddy lane. The site
  administrator subsequently deleted both users, which revoked their
  Application Passwords.
- Server-side PHP log review was subsequently supplied by the administrator;
  the shared excerpt contains expected application-level WP_Error entries and
  unrelated slow-request warnings, so it does not certify a site-scoped,
  post-cleanup clean-log window.

## Repository gates

| Gate | Result |
| --- | --- |
| `composer validate --strict` | PASS |
| PHPUnit | PASS - 486 tests, 3,172 assertions |
| `composer lint` | PASS - 139 files |
| `composer audit --locked` | PASS - no advisories |
| Production install dry-run | PASS |
| `git diff --check` | PASS |

## Boundary

Remote Codex acceptance is complete for Phase 1.7.3, and remote WorkBuddy
acceptance plus fixture/account cleanup subsequently completed. Site-scoped
post-cleanup log evidence, final exact-main security/release review, and any
release or WordPress.org submission remain subsequent gates. No production PHP,
Composer dependency, persistence namespace, public schema, or tool-order change
was made by this validation or cleanup.
