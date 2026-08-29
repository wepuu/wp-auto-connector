# Phase 1 Specification - Direct WordPress MCP MVP

## User outcome

After installing WP-Auto Connector, a site administrator can connect a compatible MCP client directly to the WordPress site and perform explicitly permitted operations using normal WordPress authorization.

## Transport target

Preferred remote transport: Streamable HTTP through the official WordPress MCP Adapter.

Target WP-Auto endpoint:

```text
https://example.com/wp-json/wp-auto/mcp
```

Do not implement a parallel custom MCP protocol stack unless an approved ADR replaces the official adapter decision.

## Authentication baseline

Phase 1 baseline for remote direct connections: WordPress Application Password over HTTPS.

The authenticated WordPress user is not automatically trusted for every WP-Auto tool. Every ability also enforces its own WordPress capability.

## Phase 1.1 proof tool

Canonical ability:

```text
wp-auto/site-health
```

Purpose: prove ability registration, MCP exposure, transport authentication, permission evaluation, execution, and structured result handling.

Initial result should contain only non-secret operational fields needed to verify the connector, for example:
- WordPress version;
- PHP version;
- WP-Auto Connector version;
- Abilities API availability;
- MCP Adapter availability/version;
- REST API availability;
- HTTPS state.

Do not return admin email, usernames, filesystem paths, secrets, database details, or plugin inventory in Phase 1.1.

Required WordPress capability: `read`.

## Planned Phase 1 tool catalog

See `docs/MCP_TOOL_CATALOG.md`.

## Mutation safety

Before Phase 1.3 is considered complete:
- create operations must be idempotent;
- updates must use optimistic concurrency/version checking;
- draft creation is separate from publish;
- published content updates require explicit later policy decisions;
- every mutation is attributable to the authenticated WordPress user.

## Media safety

Remote URL media import is not a generic URL fetcher. It must include SSRF controls, redirect re-validation, private/reserved IP blocking, file-size/type limits, WordPress media validation, and timeouts.

## Phase 1 final acceptance scenario

From a compatible AI client:

1. Connect to the site's WP-Auto MCP endpoint.
2. Inspect site information and search existing content.
3. Create a new post as `draft` only.
4. Update the draft content.
5. Ensure/create relevant category/tag terms and assign them.
6. Upload/import an allowed image and set it as featured image.
7. Read/update supported SEO metadata.
8. Return the post ID and WordPress edit URL.
9. Confirm the post remains a draft.

The scenario must succeed without exposing publish, permanent delete, arbitrary code execution, plugin/theme management, or user-management capabilities.
