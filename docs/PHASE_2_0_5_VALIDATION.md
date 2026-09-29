# Phase 2.0.5 Connector Validation

Status: **accepted and closed**

## Implemented candidate

- strict Authorization-header Bearer extraction;
- RS256 `at+jwt` verification through `firebase/php-jwt` 7.2.0;
- exact issuer/resource/tenant/site/grant/client/time/scope validation;
- accepted JWKS cache and local key/JTI/grant/site/subject deny checks;
- current `grant_id` to local-user resolution for each request;
- Bearer-only scope decorator covering the exact ordered 23 abilities;
- independent Application Password path;
- path-specific protected-resource metadata and differentiated challenges;
- request-end user/context restoration.

## Automated evidence

| Gate | Result |
|---|---|
| Connector PHPUnit | PASS: 528 tests, 3608 assertions |
| PHP coding standards | PASS: 177 files |
| Exact tool/scope mapping | PASS: 23 ordered abilities equal the server allowlist |
| Invalid audience/client/tenant/scope | PASS: fail closed |
| Independent `crit`/`jku`/`jwk`/`x5u` rejection | PASS: fail closed |
| Local token revocation | PASS |
| Bearer without Application Password support | PASS |
| Invalid Bearer no fallback | PASS |
| Request identity restoration | PASS |
| Missing/invalid/scope/capability challenge separation | PASS |
| Production dependency install dry run | PASS: lock is installable with dev-only removals |
| In-place PRM rewrite migration | PASS: versioned one-time flush plus deactivation/uninstall cleanup |
| Release-like production package | PASS: 789 entries, no development paths, SHA-256 `022aebdc50fc11f86d9ca79f5aef69d3bddeaa381fcfa445d162545a5086856d` |
| Plugin Check 2.1.0 default strict | PASS: exit 0, no errors |
| Plugin Check 2.1.0 runtime-enabled | PASS: exit 0, no errors |

## Final external evidence

- PASS: Codex 0.154.0 completed PKCE S256 authorization and called
  `wp-auto/site-health` directly on WordPress;
- PASS: control-plane logs and PostgreSQL contained no MCP tool marker or
  result marker;
- PASS: `HOSTS_RESTORED=True` and `TRUST_RESTORED=True`;
- PASS: disposable containers, volumes, images, credentials, token/trace files
  and pending authorization URL were removed.

## Live two-key evidence

The platform repository's `scripts/test-live-bearer.ps1` passed on 2026-09-28
against the disposable WordPress HTTPS fixture. Both accepted AWS KMS RSA keys
signed independent five-minute RS256 `at+jwt` tokens. Each token initialized
MCP, observed the exact ordered 23-tool catalog, executed `site-health`, and
received an MCP tool error for `seo-update` under `mcp:read`. PRM and the
missing-token 401 challenge passed, request/result bodies were suppressed, and
the temporary token file and Node image were deleted. The control-plane
content boundary reported `CONTROL_PLANE_CONTENT_FREE=True`.

The current worktree was assembled into a fresh production-only package using
the locked Composer graph. The archive contains 789 entries under the exact
`wepuu-auto-connector/` root and excludes tests, docs, tools, build state and
the provider's public Adapter entrypoint. Official Plugin Check 2.1.0 passed
both its default strict and runtime-enabled checks in the disposable WordPress
6.9 / PHP 8.3 fixture with exit code 0 and no errors.

All Phase 2.0.5 connector, live client, privacy, package and cleanup gates
passed. The user explicitly accepted and closed Phase 2.0.5 on 2026-09-29.
Production deployment and Phase 2.0.6 remain outside the authorization.
