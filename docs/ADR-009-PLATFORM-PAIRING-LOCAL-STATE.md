# ADR-009: Platform pairing and local grant state

Status: accepted for Phase 2.0.3B implementation

## Context

The optional WePuu control plane needs a site-local signing identity, a
single-use pairing verifier, public connection metadata, and an opaque mapping
from a platform grant to a current WordPress user. None of this state may alter
the independent Application Password path or expose WordPress identity or
content to the platform.

## Decision

- Platform integration is disabled by default and requires separate Enable and
  Connect administrator actions, each protected by `manage_options` and a
  WordPress nonce.
- The exact MCP resource, tenant UUID, control origin, issuer, connection
  status, opaque site ID, and site-key ID live in one non-autoloaded option.
- A pairing attempt stores only `SHA-256(verifier)` with a ten-minute expiry.
  The verifier is handed to the platform in a URL fragment, removed from
  browser history, and posted same-origin; it is never placed in an HTTP URL,
  Referer header, platform log, or database row.
- The site creates an Ed25519 identity with PHP libsodium. The private key stays
  in one non-autoloaded local option. Proof construction uses `lcobucci/jwt`
  4.3.0; no JOSE or signature primitive is implemented locally.
- Each active grant uses the fixed option family
  `wp_auto_connector_grant_{sha256(grant_id)}` with autoload disabled. Its value
  contains only the opaque grant, local numeric user ID, exact site/client/
  resource/scope/key bindings, and a bounded timestamp. Platform subject,
  email, roles, capabilities, passwords, tokens, and content are absent.
- Authorization lookup uses the exact derived option name and current
  WordPress user state. It never depends on an index, cached role, or platform
  capability assertion.
- Trust-sensitive settings reads compare the stored resource with the current
  canonical WordPress REST resource. Any mismatch or inability to calculate a
  safe current resource persists `suspended`, retains the original audience,
  and requires explicit disconnect and re-pair; it never migrates trust.
- Pairing proof completion is valid only while the local state is `pending`.
  Consent decisions consume their one-shot state and then recheck the exact
  tenant, site, issuer, resource, and site-key bindings to close the interval
  between preview and decision.
- Explicit disconnect deletes the connection and site key first, making every
  existing grant unusable even if the platform is unavailable. Explicit
  uninstall additionally removes the exact pairing options and exact-valid
  grant family.

## Direct database exception

ADR-004's bounded, read-only uninstall option walker is extended by one exact
prefix and one exact anchored pattern for the grant family. It remains an
explicit-uninstall-only read; deletion still uses `delete_option()`. No runtime
authorization path receives generic SQL or wildcard deletion authority.

## Failure and rollback

Malformed, expired, replayed, mismatched, partially written, resource-changed,
site-key-changed, disconnected, or user-missing state fails closed. Rolling
back the optional platform integration means disconnecting it locally; it does
not weaken proof, issuer, tenant, site, resource, grant, scope, or WordPress
permission checks, and it does not affect Application Password access.

This behavior needs no data migration. Existing connections whose current
resource still matches are unchanged; a drifted connection becomes suspended
the next time a trust-sensitive path reads it.
