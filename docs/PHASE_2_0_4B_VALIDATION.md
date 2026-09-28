# Phase 2.0.4B Validation Record

Status: implementation and cross-runtime live validation complete locally;
hosted CI remains open.

## Implemented boundary

- A fixed `POST /wp-json/wp-auto/v1/revocations` endpoint accepts only
  `application/jwt` after explicit active pairing and HTTPS.
- RS256 events require exact `typ`, `kid`, issuer, single-string audience,
  tenant, site, claim set, lifetime, event binding, and monotonic sequence.
- Public JWKS is bounded to five RSA signing keys, fresh for five minutes and
  safe-stale for at most twenty minutes. Unknown `kid` causes one bounded
  refresh. A successful authoritative key removal never falls back to stale.
- Grant, token-JTI, and key deny markers are hashed or already pseudonymous;
  site/subject events set deny-all. State is non-autoloaded, bounded, monotonic,
  duplicate-idempotent, and malformed state fails closed.
- The endpoint is limited to 60 requests per minute per site and returns only
  uniform content-free success or denial responses.
- Existing local grant resolution consults revocation state. Bearer-token MCP
  authentication remains intentionally unwired until Phase 2.0.5.
- Explicit uninstall removes JWKS, revocation, lock, and limiter options.

## Local evidence

- `composer validate --strict`: valid with the repository's existing exact-pin
  warnings.
- `composer test`: 518 tests and 3511 assertions pass.
- `composer lint`: 167 files pass.
- `composer audit --locked`: no security vulnerability advisories.
- production `composer install --no-dev --prefer-dist --optimize-autoloader
  --dry-run`: locked runtime graph resolves without install/update.
- `firebase/php-jwt` is locked at 7.2.0, BSD-3-Clause.
- The branch CI workflow validates the exact-pinned dependency policy, runs
  PHP 8.1 tests/lint, and audits the locked graph. Commit, push, merge, and
  deployment were authorized on 2026-09-28; the hosted run is pending.
- The platform repository now contains a disposable Caddy/WordPress fixture
  that drives a real AWS-KMS-signed event through this REST controller and
  verifies only the resulting opaque grant deny decision. The credentialed
  run passed on 2026-09-28 with `CONNECTOR_GRANT_DENIED=True`,
  `LIVE_HTTPS_REVOCATION_DELIVERY=True`, and
  `CONTROL_PLANE_CONTENT_FREE=True`; temporary hosts and CA trust were
  restored.

No test or trace contains a token, cookie, WordPress content, MCP request body,
or tool result. Phase 2.0.5 work remains outside this record.
