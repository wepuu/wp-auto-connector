# Phase 2.0.3B Pairing and Local Grant Contract

## Scope

This phase adds explicit, administrator-initiated platform pairing and local
WordPress consent. It does not add MCP Bearer authentication, access-token
validation, refresh tokens, or any MCP tool.

Application Password authentication remains independent and unchanged. The
platform is a control plane only and never receives MCP request or response
bodies or WordPress content.

## Fixed identifiers

- Protocol version: `1`.
- Canonical MCP resource: the exact HTTPS URL returned for
  `/wp-json/wp-auto/mcp`, with no query, fragment, credentials, non-default
  port, or trailing slash.
- Pairing proof endpoint: `POST /wp-json/wp-auto/v1/pairing/proof`.
- Site proof JOSE header: `alg=EdDSA`,
  `typ=wepuu-site-proof+jwt`, and the active site-local `kid`.
- Pairing attempt lifetime: at most ten minutes.
- Site proof lifetime: at most sixty seconds.

## Pairing proof request

The platform sends bounded `application/json` containing exactly:

- `protocol_version`;
- `tenant_id`;
- `pairing_attempt_id`;
- `site_id`;
- `verifier`;
- `challenge`;
- `platform_issuer`;
- `platform_signing_key_pem`;
- `platform_signing_kid`;
- `resource`.

The connector stores only `SHA-256(verifier)`. A valid request consumes the
pending attempt before a proof is returned. Expiry, mismatch, or replay fails
closed without disclosing which field was valid.

The explicit Connect action redirects the administrator to the fixed platform
start page with bounded Base64url JSON in the URL fragment. The fragment
contains the one-time verifier but is not transmitted in the HTTP request,
Referer header, or server access log. Fixed platform JavaScript immediately
removes it from browser history and submits the payload as same-origin JSON,
so the platform can use its existing `SameSite=Lax` account session without
weakening that cookie policy.

The response contains exactly `proof` and `publicJwk`. The compact proof binds
`kind=pairing`, protocol version, site hostname, platform issuer, tenant,
attempt, platform-assigned site ID, exact resource, challenge, `iat`, and
`exp`. It also binds the SHA-256 digest of the validated RSA platform signing
key. The connector stores that public key with the active connection and binds
the site ID to the current local key before it
returns the proof; a lost or failed platform completion therefore remains
fail-closed and requires an explicit re-pair.
The connector pins both the RSA public key and its `kid`; a consent request
whose protected header does not contain exactly `alg=RS256`,
`typ=wepuu-consent-request+jwt`, and that `kid` fails closed.

## Local consent

The platform supplies a short-lived signed consent request that binds the
platform issuer, tenant, paired site, opaque platform subject and grant,
client, exact resource, exact scope set, one-time challenge, and expiry. The
connector verifies that request against the pinned platform issuer and public
signing key before rendering any consent details.

The request uses the exact resource as a single-string `aud`, has a maximum
120-second lifetime, and contains only the frozen five MCP scopes in canonical
order. It first arrives in the browser fragment, survives local login only in
`sessionStorage`, is removed from browser history, and is then submitted by a
same-origin nonce-protected POST. Only verified normalized claims are stored in
a hashed, non-autoloaded, local one-time record; the compact request is not
stored.

Consent requires an already authenticated local WordPress user. Approval is a
separate nonce-protected POST. The local record contains only the opaque grant,
the local numeric user ID, exact scopes, site identity, status, and bounded
timestamps. No email address, WordPress password, Application Password,
platform session, access token, or content is stored.

The approval proof uses the site-local Ed25519 key and
`typ=wepuu-site-proof+jwt`, with `kind=consent` and all consent bindings. A
proof also binds the single-string resource audience and the exact
`decision=approved|denied`. A request, challenge, or decision proof is
single-use. Denial creates no local grant. The result returns to the fixed
control origin in a URL fragment, so the proof and challenge do not enter
WordPress or platform access-log query strings.

## Local lifecycle

- A local revoke denies the grant immediately even when the platform is down.
- User deletion invalidates every local grant for that user.
- Capability changes take effect on the next WordPress operation; no
  capability snapshot is authoritative.
- A canonical resource, hostname, or site-key change suspends pairing and
  requires explicit re-pairing.
- Every trust-sensitive settings read compares the stored canonical resource
  with the current WordPress REST resource. A mismatch persists `suspended`,
  retains the old resource as the rejected trust boundary, and never rewrites
  the audience silently.
- The public proof route completes only a `pending` attempt. A consent decision
  rechecks tenant, site, resource, issuer, and site-key bindings after consuming
  its one-shot state, so a preview cannot survive suspension or re-pairing.
- Disconnect deletes pending attempts and deactivates local grants before any
  best-effort platform notification.
- Plugin activation or admin page display never starts an external request.
- Plugin reinstall must not silently revive an old pairing or grant.

## Data and tool boundary

The platform may call only the fixed pairing/status control endpoints after
explicit administrator action. It may not call the MCP endpoint or WordPress
content APIs. The connector must not send MCP tool names, arguments, results,
posts, pages, media, taxonomy, SEO values, email addresses, or local user
identifiers to the platform.

The ordered twenty-three-tool catalog and all existing WordPress capability
and object checks remain unchanged.
