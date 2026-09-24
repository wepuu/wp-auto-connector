# Phase 2.0.3B Validation Record

Date: 2026-09-24

Status: accepted and closed with the platform Phase 2.0.3 exit decision.

## Passed evidence

- Connector branch: `codex/phase-2-0-3b-pairing`.
- Existing ordered 23-tool registration remains unchanged.
- Canonical resource rejects HTTP, credentials, query, fragment, non-default
  port, and trailing-slash variants.
- Pairing state stores only a SHA-256 verifier digest and consumes mismatch,
  expiry, success, and replay terminally.
- Explicit Enable performs no network request. Explicit Connect transfers the
  verifier only in a fragment and the platform start page clears it before a
  same-origin request.
- Site proof uses libsodium Ed25519 plus `lcobucci/jwt` 4.3.0 and binds issuer,
  tenant, attempt, platform site ID, exact resource, challenge, local key,
  pinned platform RSA-key digest, and a 60-second lifetime.
- Local grant lookup binds the current connection, site, resource, signing key,
  exact scopes, and an existing local user without storing platform subject or
  email.
- Disconnect invalidates grants without platform availability; uninstall
  removes exact pairing and grant state.
- Platform consent requests are verified with `lcobucci/jwt` 4.3.0 against the
  pinned RSA public key and `kid`; exact headers/claims, single-string audience,
  canonical scope order, challenge, tenant/site/resource, and 120-second
  lifetime fail closed on deviation.
- The authenticated consent UI keeps the compact request out of query strings,
  binds pending state to one local user, uses separate nonce-protected approval
  and denial POSTs, and consumes state exactly once. Denial creates no grant.
- `composer lint`: 158/158 files pass.
- The real HTTPS wp-admin path completed explicit pairing, approval, denial and
  one-shot completion replay rejection. Approval created one bound local grant;
  denial created none.
- The consent page sends private/no-store cache control and a late
  `Referrer-Policy: no-referrer`, preventing the fragment-derived opaque handle
  from entering subsequent request referrers.
- Plugin deactivation removed the proof route (`404`); reactivation restored
  the fail-closed unauthenticated route (`401`) and preserved the paired-state
  digest.
- Deleting the bound local user immediately made its existing grant
  unresolvable; recreating the login did not revive the old user binding.
- Explicit uninstall removed connection, site identity, grant and pending
  consent state; reactivation did not silently revive any trust.
- Canonical-resource drift now persists `suspended` without rewriting the old
  audience; proof completion is pending-only, grants fail closed, and consent
  decisions recheck all site bindings after consuming one-shot state.
- `composer test`: 513 tests, 3399 assertions pass.
- Platform static build and ESLint pass in Linux Node 26.7.0; 35 tests
  discovered, 31 pass and four
  explicitly gated environment/live tests are skipped in the default run.
- Real PHP Ed25519 to TypeScript JOSE interoperability passes when
  `WEPUU_PHP_INTEROP=1`.
- PostgreSQL 16 migration replay, persistence, forced RLS, and cross-tenant
  denial test passes with `WEPUU_TEST_DATABASE_URL`.
- `composer audit --locked` reports no advisories and the production install
  dry-run passes. `composer validate --strict` reports only Composer's advisory
  against the intentionally exact `lcobucci/jwt` 4.3.0 security pin; the file
  is otherwise valid.
- The disposable PostgreSQL container and volume were removed after testing.

## Closure evidence

- The real resource/domain-change check suspended the old binding, rejected its
  proof route, revoked its platform site/grant, rotated the site ID and Ed25519
  key on explicit re-pair, and required fresh local consent for the new exact
  MCP resource. The old local grant remained inactive.
- The dual-domain fixture containers, networks, volumes, marked hosts entries,
  and fingerprinted CurrentUser CA were removed and independently verified.
- Platform GitHub Actions run `35967643811` passed the complete validation job
  and the Node 26.7 provider-backed JOSE contract against the real AWS KMS test
  key through short-lived GitHub OIDC credentials.
- The connector and platform Phase 2.0.3 branches were pushed after explicit
  authorization. No deployment, Phase 2.0.4 token lifecycle, or Phase 2.0.5
  Bearer authentication was performed.
