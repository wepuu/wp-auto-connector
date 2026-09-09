# Phase 1.5.2 Tag Create Validation

Status: **IMPLEMENTED; VALIDATION CANDIDATE IN WORKING TREE**

Validation date: 2026-09-09

Runtime target: exact twenty explicitly allowlisted tools, with
`wp-auto/tag-create` appended after `wp-auto/category-create`.

## Scope verdict

This checkpoint implements only authenticated creation in the built-in
`post_tag` taxonomy. The operation requires the taxonomy object's actual
`manage_terms` capability, rejects hierarchical controls, treats existing
terms as conflicts, and uses the shared persistent site/actor/Ability/key
idempotency family. Private attribution is stored in the exact taxonomy
termmeta audit key and is bounded to the newest 20 events per term. Category
Create remains covered by regression tests and keeps its parent contract.

Draft Post taxonomy assignment, term update/delete, custom taxonomies,
implicit term creation, publishing, SEO, Cloud, telemetry, and outbound
requests remain out of scope.

## Automated quality gates

- `composer validate --strict`: passing.
- PHPUnit 9.6: 423 tests / 2,353 assertions, passing.
- `composer lint`: passing for all repository PHP files.
- `git diff --check`: passing.
- Shared AtomicOwnership, idempotency, audit, service, Ability, registrar,
  bootstrap, and uninstall coverage includes the Tag Create namespace and
  exact parent-free event/output shape.

## Production bootstrap and allowlist

The production entrypoint loads the Tag contract and Ability after the shared
taxonomy service. The dedicated MCP registrar exposes exactly 20 tools in the
frozen order; `wp-auto-tag-create` follows `wp-auto-category-create`. No
generic taxonomy API or adapter-wide Ability is allowlisted.

## Disposable real WordPress validation

The approved disposable stack used WordPress 6.9 with PHP 8.1, MariaDB 11.8,
the locked MCP Adapter 0.6.1, and the repository plugin mounted read-only.
The plugin activated successfully and registered exactly 20 `wp-auto/*`
Abilities. An authenticated `wp-auto/tag-create` call created one tag and the
same payload/key replay returned `idempotency_replayed=true`; the matching
slug count remained one. A pre-existing term returned the explicit
`wp_auto_term_conflict` error, unauthenticated execution returned
`ability_invalid_permissions`, and a supplied `parent_id` was rejected by the
Ability input validator. The private audit event contained the exact
parent-free Tag shape, and its termmeta container held one event. The
idempotency option was non-autoloaded (`autoload=off`) and one option existed
for the successful operation.

The local uninstall handler was then invoked in the same disposable site. It
removed the taxonomy idempotency option and the taxonomy termmeta audit key;
both final counts were zero. This also verified the WordPress 6.9 fallback to
the generic Core `delete_metadata( 'term', 0, $meta_key, '', true )` API because
that release does not provide `delete_term_meta_by_key()`.

The temporary containers, network, and database were removed after evidence
collection.

## Official Plugin Check

Plugin Check 2.1.0 was not executed. The repository policy prohibits downloading
or executing a remote plugin, and neither the repository nor the local Docker
image cache contains a Plugin Check copy. This remains an explicit release
blocker; no result is inferred from PHPUnit, lint, or the real-WordPress run.
When an approved local Plugin Check artifact is available, the scan must target
a release-like package containing the production entry files, `src`,
`readme.txt`, `LICENSE`, Composer manifests, and bundled dependencies, while
excluding development files and the standalone MCP Adapter plugin entrypoint.

## Uninstall and security boundary

Tag Create introduces no new persistence family: it shares the existing
taxonomy idempotency option prefix and taxonomy audit termmeta key already
covered by the explicit uninstall path. No new direct SQL authority is added.
The Phase 1.5.4 integration/security seal remains open and must repeat the
authenticated Streamable HTTP, concurrency, state-integrity, uninstall,
exact-allowlist, and security-diff matrix before Phase 1.5 is formally sealed.

## Verdict

```text
Tag Create implementation = COMPLETE IN WORKING TREE
Automated tests/lint = PASS
Real WordPress bootstrap + replay = PASS
Real WordPress uninstall cleanup = PASS
Plugin Check 2.1.0 = BLOCKED BY REMOTE-PLUGIN DOWNLOAD POLICY
Direct MCP tools = 20
Phase 1.5.2 = VALIDATION CANDIDATE
Next checkpoint = Phase 1.5.3 draft-post taxonomy assignment
```
