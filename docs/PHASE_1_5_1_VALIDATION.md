# Phase 1.5.1 Category Create Validation

Status: **IMPLEMENTED; VALIDATION CANDIDATE IN WORKING TREE**

Validation date: 2026-09-09

Runtime target: exact nineteen explicitly allowlisted tools, with
`wp-auto/category-create` as the first taxonomy mutation tool.

## Scope verdict

This checkpoint implements only authenticated creation in the built-in
`category` taxonomy. The operation requires the taxonomy object's actual
`manage_terms` capability, accepts an optional validated parent, rejects
existing terms as conflicts, and uses persistent site/actor/Ability/key
idempotency. Private attribution is stored in the exact taxonomy termmeta
audit key and is bounded to the newest 20 events per term.

Tag Create, exact relationship assignment, term update/delete, custom
taxonomies, implicit term creation, publishing, SEO, Cloud, telemetry, and
outbound requests remain out of scope.

## Automated quality gates

- `composer validate --strict`: passing.
- PHPUnit 9.6: 412 tests / 2,284 assertions, passing.
- `composer lint`: passing for all repository PHP files.
- `git diff --check`: passing.
- AtomicOwnership tests cover the exact taxonomy idempotency option family,
  including malformed-record rejection and cross-ability isolation.
- Taxonomy store, audit, service, Ability, registrar, bootstrap, and uninstall
  tests cover capability boundaries, parent validation, conflicts, replay,
  uncertain state, bounded audit retention, and exact cleanup preservation.

## Production bootstrap and allowlist

The production entrypoint loads the taxonomy stores, contract, service, and
Ability before registration. The dedicated MCP registrar exposes exactly 19
tools in the frozen order; the new final entry is
`wp-auto-category-create`. No generic taxonomy API or adapter-wide Ability is
allowlisted.

## Disposable real WordPress validation

An isolated Docker stack used WordPress 6.9, PHP 8.1.34, MariaDB 11.8.9, and
the locked MCP Adapter 0.6.1. The release-like plugin copy activated
successfully as `WePuu Auto Connector` version `0.1.0`.

The `@wordpress/env` CLI was not installed locally (Node/npm are unavailable),
so the equivalent official WordPress Apache and MariaDB Docker images were
used. The temporary containers, network, and release-like copy were removed
after validation.

With an administrator user authenticated, the real Ability registry accepted
`wp-auto/category-create`. A first request created one root Category and a
second request with the same actor, payload, and idempotency key returned the
same term with `idempotency_replayed: true`. The resulting term had one exact
private `create` audit event containing only the approved fields; no request
body, raw key, or credential was persisted.

## Official Plugin Check

Official Plugin Check 2.1.0 ran against a release-like package containing the
production entry files, `src`, `readme.txt`, `LICENSE`, Composer manifests,
and the bundled dependencies. Development files and the standalone MCP
Adapter plugin entrypoint were excluded.

Both the static scan and the runtime-enabled scan using
`--require=/var/www/html/wp-content/plugins/plugin-check/cli.php` completed
with exit status 0 and reported:

```text
Success: Checks complete. No errors found.
```

The result contained no warning rows.

## Uninstall and security boundary

The explicit uninstall path now removes the exact taxonomy idempotency option
family and taxonomy termmeta audit key using Core deletion APIs plus the
bounded, prepared exact-key verification authorized by ADR-004. Neighboring
options and unrelated termmeta remain intact. No active taxonomy runtime SQL
was added.

The Phase 1.5.4 integration/security seal remains open. It must repeat the
full authenticated Streamable HTTP, concurrency, state-integrity, uninstall,
exact-allowlist, and security-diff matrix before Phase 1.5 is formally sealed.

## Verdict

```text
Category Create implementation = COMPLETE IN WORKING TREE
Automated tests/lint/validation = PASS
Real WordPress bootstrap + replay = PASS
Plugin Check 2.1.0 static/runtime = PASS, no errors or warnings
Direct MCP tools = 19
Phase 1.5.1 = VALIDATION CANDIDATE
Next checkpoint = Phase 1.5.2 Tag Create
```
