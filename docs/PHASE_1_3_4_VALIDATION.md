# Phase 1.3.4 Full Mutation Integration Validation

Status: **COMPLETE — FORMALLY SEALED ON MAIN WHEN THIS RECORD LANDS**

Validation date: 2026-09-07

Runtime baseline: `main@2738768f0fb135c9d0c1a96277d2cd2acf41ccbd`

Validation branch: `chore/phase-1-3-4-integration-validation`

This checkpoint validates and seals the Phase 1.3 draft-mutation runtime. It
adds no production code, Ability, MCP tool, schema, error, permission, external
service, or dependency. The Direct MCP surface remains exactly twelve tools.

The seal does not complete Phase 1, authorize a public release, or start media,
taxonomy mutation, SEO, publishing, deletion, Cloud, telemetry, Skills, or
automation work.

## Automated baseline

The clean runtime baseline passed before live validation:

- `composer validate --strict`;
- PHPUnit: 288 tests / 1506 assertions;
- `composer lint`: 57 of 57 PHP files;
- `composer audit --locked`: no known advisories.

Composer installed the exact locked development dependencies in the isolated
worktree. No manifest or lock-file change was made.

## Disposable runtime

The live matrix ran through official `@wordpress/env` 11.14.0 against:

- WordPress 6.9;
- PHP 8.1.34;
- MariaDB LTS;
- bundled official MCP Adapter 0.6.1;
- `WP_ENVIRONMENT_TYPE=local` with local HTTP only;
- Application Password authentication;
- Streamable HTTP protocol `2025-11-25`;
- endpoint `/index.php?rest_route=/wp-auto/mcp` on
  `http://localhost:8891`.

The fixtures represented an Editor, Author, Subscriber, and an authenticated
identity with an explicit false `read` capability. Content canaries included a
Post draft, child Page draft, published target, authorization-hidden draft,
unrelated published Post, Category, Tag, attachment, featured-image relation,
SEO-style metadata, arbitrary metadata, settings, users, plugin/theme state,
and an outbound-HTTP guard.

## MCP protocol and contract evidence

The run completed 25 MCP checks with no failure:

- `initialize` and `notifications/initialized` succeeded, and every client
  issued its session DELETE cleanup request;
- `tools/list` returned the exact ordered twelve-tool set;
- resources and prompts were empty;
- all four mutation schemas retained `additionalProperties=false`;
- Create and Update annotations retained their frozen hint values;
- Post and Page Create Draft succeeded with fixed draft status and actor;
- a completed matching Create replay returned the same object with
  `idempotency_replayed=true` and did not append another audit event;
- reuse of the key with a different payload was rejected;
- Post and Page Update succeeded and immediately matched their Get results;
- a stale pre-update `modified_gmt` token was rejected without another write;
- an authorized published target was rejected by the draft-only boundary;
- attempted `status` and Page `parent` injection was rejected by schema;
- missing, wrong-type, and unauthorized targets preserved existence hiding;
- an Author could not create a Page and a Subscriber could not mutate;
- anonymous transport returned 401 and authenticated transport without `read`
  returned 403;
- two simultaneous Streamable HTTP sessions using the same Create scope and
  idempotency key converged on one target identity with bounded results.

## Normalized state-integrity evidence

The post-run verifier completed 56 checks with no failure. It compared semantic
WordPress state rather than requiring byte-for-byte database identity.

Requested changes were limited to:

- three intended new objects: one Post draft, one root Page draft, and one
  concurrently requested Post draft;
- the requested title of the existing Post draft;
- the requested content of the existing child Page draft.

Permitted Core lifecycle effects remained permitted, including default Post
category behavior, revisions, timestamps, canonical slugs, caches, hooks, KSES,
and internal lifecycle metadata.

The verifier confirmed no WP-Auto change to:

- any unrelated, published, authorization-hidden, root Page, or attachment
  object;
- target type, status, author, Page parent, password, comment/ping state, menu
  order, omitted mutable fields, taxonomy relationships, featured media, SEO
  metadata, or arbitrary metadata;
- attachment membership or attachment metadata;
- users, role/capability state, user count, the setting canary, active plugins,
  template, stylesheet, or site URL;
- Cloud, telemetry, or outbound WordPress HTTP state.

Every successful mutation wrote exactly one fixed-schema private audit event.
Create replay and concurrent arbitration did not duplicate the event. Audit
values contained no content or raw idempotency key. Exactly three persistent
Create records remained, each in `completed` state without raw keys or content,
and no audit-ownership lock remained.

## Credential and environment cleanup

The four temporary Application Password collections were explicitly checked at
zero after cleanup. The temporary validation files were removed from the
worktree and were not committed.

Because the fixed wp-env prompt did not accept non-interactive confirmation in
this Windows shell, cleanup used Docker's native commands only after every
target name had been verified against the unique environment prefix. Cleanup
removed exactly six containers, four volumes, one network, and four generated
images. Follow-up queries returned zero matching resources.

## Plugin Check and security boundary

No audited local Plugin Check installation was present. Installing Plugin Check
from WordPress.org during this run was denied because repository policy forbids
downloading and executing remote PHP. Phase 1.3.3's existing release-like result
therefore remains the current Plugin Check evidence: one error and six warnings
covering the future tested-version target, name/slug trademark review, missing
languages directory, and the two reviewed ADR-003/ADR-004 DirectDB warnings.
These remain release-readiness work. This checkpoint changes readme status
text, so a future supported Plugin Check environment must rerun the checks;
no PHP runtime or packaging change was made here.

The Phase 1.3.3 exact-main repository scan remains the authoritative runtime
security audit. This Phase 1.3.4 patch is documentation-only after removal of
the disposable harness and receives a final changed-file diff review before
landing.

## Seal verdict

```text
Automated baseline = PASS
MCP checks = 25 / 25 PASS
Normalized state checks = 56 / 56 PASS
Direct MCP tools = 12
Production runtime change = NONE
Phase 1.3.4 = COMPLETE
Phase 1.3 = FORMALLY SEALED WHEN THIS RECORD LANDS ON MAIN
Next checkpoint = Phase 1.4 Media planning, not started
```
