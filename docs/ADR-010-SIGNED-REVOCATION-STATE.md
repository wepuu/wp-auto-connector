# ADR-010: Signed Revocation State

- Status: accepted for Phase 2.0.4B implementation
- Date: 2026-09-24

The connector accepts revocation events only at the fixed paired-site REST
endpoint. Each body is a bounded compact RS256 JWS with
`typ=wepuu-revocation+jwt`, a trusted platform `kid`, the exact platform issuer,
the exact single-string MCP resource audience, and exact tenant/site bindings.
Per-site sequence numbers are monotonic. Exact duplicates are idempotent;
older, conflicting, cross-site, expired, or unverifiable events are rejected.

The connector persists only bounded non-autoloaded deny metadata for grant,
token-JTI hash, or key ID, plus the last accepted sequence. It never stores an
access token, refresh token, MCP body, WordPress content, or platform account
identity. Uninstall removes the entire private family.

JWKS uses a five-minute fresh period and a twenty-minute maximum safe-stale
period. An unknown `kid` causes at most one bounded refresh and then fails
closed. Bearer authentication of MCP requests remains Phase 2.0.5.
