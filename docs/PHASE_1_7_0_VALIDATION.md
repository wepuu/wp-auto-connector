# Phase 1.7.0 Client and Release Contract Validation

Status: **documentation-only checkpoint complete; Phase 1.7.1 local acceptance complete**

Validation date: 2026-09-11

Baseline: `main@c48ba31`

## Scope and decisions

Phase 1.7.0 freezes the client acceptance scenario, required negative/security
matrix, client setup references, release-like package allowlist, dependency and
license review, and WordPress.org release gates. It makes no production PHP,
test, dependency, persistence, public schema, tool-order, or external-call
change. Phase 1.6.0 through Phase 1.6.3 remain formally sealed on `main` with
exactly twenty-three ordered tools.

The original 1.7.0 contract named Claude Code plus CodeBuddy/WorkBuddy, with
MCP Inspector as the protocol oracle. That client choice is superseded for the
free-client acceptance lane by Codex CLI plus WorkBuddy and a Codex Desktop
read-only smoke test; Claude Code remains optional for Pro/Max/API-authorized
accounts. ChatGPT is optional and conditional on the
account's current full-MCP capability and a reachable remote server. Local
Docker remains the deterministic test lane; any remote-client evidence uses an
HTTPS staging lane and ephemeral Application Passwords.

## Documents added or synchronized

- `docs/PHASE_1_7_CLIENT_ACCEPTANCE.md` freezes the end-to-end and negative
  matrices, client setup references, evidence, and cleanup requirements.
- `docs/PHASE_1_7_RELEASE_CONTRACT.md` freezes package contents, dependency and
  license review, WordPress.org gates, security review, and version policy.
- `README.md`, `readme.txt`, `docs/ROADMAP.md`, `AGENTS.md`,
  `docs/ADR-007-SEO-PROVIDER-ABSTRACTION.md`,
  `docs/PHASE_1_6_2_VALIDATION.md`, and
  `docs/PHASE_1_6_3_VALIDATION.md` now describe Phase 1.6 as sealed on `main`
  and identify Phase 1.7.0 as the current documentation checkpoint.

## Validation record

The documentation changes were reviewed for the following invariants:

- no production source, Composer manifest/lock file, test, generated package,
  ZIP, credential, or runtime tool registration changed;
- the public SEO description remains provider-neutral while accurately stating
  Rank Math 1.0.278 support, Get/Update scope, draft-only writes, and the
  twenty-three-tool order;
- client instructions keep credentials local, require authenticated
  Streamable HTTP, and preserve the existing capability and Origin boundaries;
- the release allowlist retains only production code and the private MCP
  Adapter 0.6.1 runtime and excludes provider-native Adapter code;
- Phase 1.6 historical evidence remains intact while post-merge status text is
  no longer described as waiting for Git landing.

The docs-only checkpoint does not require a new wp-env, Plugin Check, live
client, or Rank Math run. Those gates begin with Phase 1.7.1. The repository
quality gates were run against the unchanged production tree:

| Gate | Result |
| --- | --- |
| `composer validate --strict` | PASS — `composer.json` is valid |
| `composer test` | PASS — 486 tests / 3,172 assertions |
| `composer lint` | PASS — 139 files |
| `composer audit --locked` | PASS — no security advisories |
| Production Composer install dry-run | PASS — lock file is installable; no operations executed |
| `git diff --check` | PASS |

## Next gate

Phase 1.7.1 local client acceptance is complete. The recorded evidence covers
Codex CLI, WorkBuddy, Codex Desktop read-only smoke, and MCP Inspector on the
disposable local environment. Remote HTTPS validation and formal Phase 1.7
release sealing remain the next gates before any release or version-bump
decision. Phase 2/Cloud, telemetry, additional SEO providers, and new MCP
abilities remain out of scope.
