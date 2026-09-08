# Phase 1.4.4 Validation — Featured Image Assignment

Status: **IMPLEMENTED AND VALIDATED ON THE FEATURE BRANCH**

Validation date: 2026-09-08

Base runtime: main@fce5ec7

Validation branch: feat/phase-1-4-featured-image

This checkpoint adds only wp-auto/media-set-featured. It does not add remote
URL import, publishing, deletion, taxonomy mutation, SEO, Cloud, telemetry,
outbound HTTP, resources, prompts, dependencies, or a generic post/meta
surface. The Direct MCP server exposes exactly seventeen explicitly
allowlisted tools.

## Implementation boundary

- MediaFeaturedContract owns the strict three-integer input and exact
  five-field output schemas.
- MediaFeaturedService accepts only a supported image attachment and a
  post/page target in draft status. It repeats upload_files, the actual post
  type cap->edit_posts baseline, target edit_post, and attachment read_post
  checks without disclosing unauthorized object existence.
- The desired relationship is an idempotent no-op when already present. Other
  requests compare the latest relationship to expected_featured_media_id
  immediately before calling Core set_post_thumbnail(). This is best-effort
  optimistic concurrency, not an atomic compare-and-swap.
- The target row is re-read after Core and every protected Post/Page field is
  verified unchanged. A proven unchanged failed relation returns
  wp_auto_featured_media_update_failed; an ambiguous relation, object, or
  audit finalization returns wp_auto_media_state_uncertain without rollback.
- Featured audit events use operation set_featured, belong to the target
  draft, contain only expected/result relationship IDs, and share the private
  twenty-event media audit container with upload/update events.
- The dedicated allowlist appends only wp-auto/media-set-featured after
  Media Update.

## Automated evidence

The implementation passed:

- composer validate --strict;
- PHPUnit: **367 tests / 1,890 assertions**;
- composer lint;
- composer audit --locked with no known advisories; and
- production dependency install dry-run with no required package change.

Tests freeze the exact schema and annotations, seventeen-tool order, plugin
registration, strict integer validation, Post/Page baseline capabilities,
existence hiding, supported-image authorization, no-op ordering, stale
relationship conflict, Core failure classification, post-write uncertainty,
target invariant preservation, exact featured audit shape, and private audit
retention.

## Disposable live runtime

The local wp-env/Node CLI was not installed. The validation therefore used
the locally cached equivalent WordPress image with:

- WordPress 6.9;
- PHP 8.1.34;
- Docker Engine 29.6.1;
- MariaDB 11.8.9;
- bundled official MCP Adapter 0.6.1;
- WP_ENVIRONMENT_TYPE=local with local HTTP only; and
- endpoint /index.php?rest_route=/wp-auto/mcp on disposable port 8899.

No remote package, image, plugin, or executable was downloaded for this run.
The temporary administrator Application Password, containers, anonymous data
volumes, and isolated Docker network were removed after validation.

## Live MCP evidence

- authenticated MCP initialization succeeded with protocol 2025-11-25;
- tools/list returned exactly seventeen tools, including
  wp-auto-media-set-featured with readOnlyHint=false,
  destructiveHint=true, and idempotentHint=true;
- authenticated Media Upload created two supported PNG attachments;
- authenticated Post Create Draft created a draft target;
- first featured assignment returned the exact five-field response with
  changed=true;
- repeating the same assignment with a stale expected value returned
  changed=false and did not add an audit event;
- changing to another image with expected ID 0 returned the stale
  relationship error and did not write the relation;
- the target draft contained exactly one eight-field set_featured audit event;
- _thumbnail_id matched the requested media ID; and
- unauthenticated access to the MCP endpoint returned HTTP 401.

Plugin Check was not installed and was not downloaded for this checkpoint. It
remains a required Phase 1.4.6/pre-release gate.

## Verdict

    Automated tests = 367 / 367 PASS
    Automated assertions = 1,890 PASS
    Direct MCP tools = 17 exact
    External requests added by implementation = NONE
    Live WordPress/MCP validation = PASS
    Live featured assignment = PASS
    Live no-op/conflict behavior = PASS
    Live target audit event = 1 exact
    Disposable credentials/containers/volumes/network = REMOVED
    Next implementation checkpoint = Phase 1.4.5 Remote URL Import
