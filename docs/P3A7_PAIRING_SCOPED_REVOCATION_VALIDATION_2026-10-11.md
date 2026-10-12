# P3-A7 Pairing-Scoped Revocation Validation — 2026-10-11

Status: local Connector candidate; not packaged or deployed.

## Defect

The production test site retained a sequence from an older pairing because the
Connector's revocation option was not bound to `site_id` and pairing activation
did not initialize it. The new platform site began at sequence 1 while the
Connector still held sequence 2, so the signed event was correctly verified
but rejected by stale local ordering state.

## Candidate behavior

- Explicit pending pairing initializes version 2 revocation state before the
  connection becomes active.
- Version 2 stores the current opaque `site_id`, sequence zero and empty deny
  marker sets.
- Active version 2 state that is missing, malformed or cross-site denies all
  token use and refuses events.
- The only reset path requires a pending pairing; normal plugin startup and
  upgrade cannot clear deny state.
- Existing active version 1 state remains compatible. A later valid event
  upgrades it while preserving sequence and every deny marker.
- Events from an old pairing cannot alter the new pairing's state.

Application Password authentication, MCP transport, the 23-tool catalog,
WordPress permissions and platform HTTP contracts are unchanged.

## Validation

The focused tests cover pairing initialization failure, same-pair duplicate,
stale and out-of-order events, sequence 1 for a new site, cross-pair rejection,
legacy deny preservation, and fail-closed missing/malformed/mismatched state.
`composer validate --strict`, Composer advisory audit, all 534 PHPUnit tests
with 3,699 assertions, and all 178 PHPCS checks passed. The release builder
produced and reopened a 789-entry cross-platform package with SHA-256
`f59406eaa07c8d6d8518ef5d5d71cd496a7952fa781b263d7bc306ec5fd3d960`.
Plugin Check and live WordPress compatibility remain required before a fixed release package can be deployed.
No production site or option was modified during local development.
