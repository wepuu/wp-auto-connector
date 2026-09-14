# Phase 1.7.4 Remote WorkBuddy Validation

Status: **WorkBuddy remote workflow, fixture cleanup, and temporary-account revocation complete; formal release sealing remains pending**

This record contains redacted acceptance evidence only. It does not contain
credentials, authorization headers, object identifiers, state tokens, raw
request bodies, or machine temporary paths.

## Runtime and client

- WordPress core: 7.1
- PHP: 8.2.28
- WePuu Auto Connector: 0.1.0
- Private MCP Adapter runtime: 0.6.1
- WorkBuddy: 2.137.1
- MCP protocol: 2025-06-18
- Transport: authenticated Streamable HTTP over HTTPS

## Protocol and allowlist

- The WorkBuddy run used `--strict-mcp-config` and a literal 23-tool MCP
  allowlist.
- The independent MCP Inspector 2.6.0 CLI run completed `tools/list --strict`
  over the same authenticated HTTPS endpoint and returned exactly 23 tools in
  the frozen catalog order, with no error result.
- WorkBuddy normalized the configured server/tool names to its
  `mcp__wp_auto__wp_auto_*` event names; no provider-native tool was exposed.
- The administrator stream contained no tool errors and ended with a success
  result.
- The low-privilege stream parsed completely and ended with a success result;
  `success` here means the negative checks passed, not that protected calls
  were authorized.

## Administrator workflow

The real WorkBuddy stream contained 96 parsed JSON events, including 30 actual
MCP tool calls. It proved:

- site health and site information reads;
- Post and Page draft creation with deterministic idempotent replay;
- Post and Page updates with stale `modified_gmt` rejection;
- Category and Tag creation with idempotent replay;
- draft taxonomy assignment, including the documented best-effort
  `state_uncertain` response followed by a fresh read and final-state proof;
- synthetic 1x1 PNG upload and featured-image assignment;
- SEO Get, SEO Update, no-op, and stale state-token rejection;
- final Post, Page, taxonomy, media, featured-image, and SEO reads.

No publish, delete, remote import, plugin administration, arbitrary metadata,
or non-MCP write path was invoked.

## Low-privilege workflow

The low-privilege stream contained 24 parsed JSON events and made eight
protected MCP calls:

- Post Get and Page Get returned existence-hidden results;
- Post Update returned the protected-object not-found result;
- both taxonomy assignment attempts returned permission denied;
- featured-media assignment returned permission denied;
- SEO Get and SEO Update returned permission denied.

No protected operation succeeded, and no raw metadata or exception detail was
returned.

## Cleanup and quality gates

- The five synthetic objects passed exact REST-side type, status (where
  available), slug, and generated-name checks before deletion.
- Deletion verification returned HTTP 404 for every object.
- The WorkBuddy JSONL streams, Inspector output, DPAPI files, and temporary
  scripts are removed after evidence review.
- The two temporary users were deleted by the site administrator; their
  Application Passwords are therefore revoked.
- Local quality gates already pass: PHPUnit 486 tests / 3,172 assertions,
  `composer validate --strict`, `composer lint`, `composer audit --locked`,
  production install dry-run, and `git diff --check`.

## Server log review

The administrator-provided PHP-FPM excerpt contains tracing notices and
slow-request warnings. Entries for `surelock.co` and `travoce.co` are unrelated
sites. Entries for `itravoce.com` are slow `wp-admin/user-new.php` requests
during temporary-user creation; they are not PHP Warning, Notice, Deprecated,
Parse, or Fatal messages from the connector. Because the supplied excerpt is a
shared host log and includes slow-request warnings, the global clean-log release
gate is not certified by this record. Before release sealing, capture a
site-scoped, post-cleanup window around one authenticated read-only MCP request
and require no new connector or PHP runtime warnings.

The connector entries in the excerpt are expected application-level rejection
paths, not runtime failures:

| Result | Interpretation |
| --- | --- |
| `wp_auto_content_not_found` (404) on Post/Page Get or Update | Existence-hidden response for an absent or inaccessible target. |
| `wp_auto_content_conflict` (409) on Post/Page Update | Stale `modified_gmt` precondition; the client must re-read before retrying. |
| `wp_auto_taxonomy_conflict` (409) | Taxonomy relationships changed after the client read them; the client must re-read and reconcile. |
| `wp_auto_seo_conflict` (409) | SEO `state_token` is stale; the client must call SEO Get and retry from the fresh state. |

These expected WP_Error results are currently emitted at the connector's
`[ERROR]` application-log level, which explains the Nginx `error` lines without
indicating a PHP fatal. Changing their severity or routing is an observability
change outside this acceptance checkpoint and is not applied here.

Phase 1.7.5 subsequently implements and locally validates that observability
change through a connector-owned handler. The remote package used by this
record predates the handler and must be replaced before the final remote
site-scoped clean-log gate.

## Boundary

This checkpoint adds no production PHP, dependency, persistence, schema, or
MCP tool. Remote Codex and WorkBuddy client acceptance and fixture cleanup are
complete; final exact-main security/release review, PR landing, versioning, and
WordPress.org submission remain separate gates.
