# ADR-010: Signed Revocation State

- Status: accepted for Phase 2.0.4B implementation
- Date: 2026-09-24

The connector accepts revocation events only at the fixed paired-site REST
endpoint. Each body is a bounded compact RS256 JWS with
`typ=wepuu-revocation+jwt`, a trusted platform `kid`, the exact platform issuer,
the exact single-string MCP resource audience, and exact tenant/site bindings.
Per-site sequence numbers are monotonic. Exact duplicates are idempotent;
older, conflicting, cross-site, expired, or unverifiable events are rejected.

## 2026-10-11 pairing-state clarification

The persisted sequence and deny markers are bound to the active platform
`site_id`. A newly completed explicit pairing initializes version 2 state with
that site ID, sequence zero and no deny markers before the connection becomes
active. This is the only runtime path allowed to reset the state.

An active version 2 pairing fails closed if the state is missing, malformed or
bound to another site. Existing active version 1 state remains readable and
all existing deny markers are preserved when a later valid event upgrades it
to version 2. Plugin upgrade alone never clears or rewrites deny state.

The connector persists only bounded non-autoloaded deny metadata for grant,
token-JTI hash, or key ID, plus the last accepted sequence. It never stores an
access token, refresh token, MCP body, WordPress content, or platform account
identity. Uninstall removes the entire private family.

JWKS uses a five-minute fresh period and a twenty-minute maximum safe-stale
period. An unknown `kid` causes at most one bounded refresh and then fails
closed. Bearer authentication of MCP requests remains Phase 2.0.5.
