# WP-Auto Connector - Codex Repository Instructions

## Mission

Build a secure, WordPress.org-compliant WordPress connector that gives compatible AI agents direct MCP access to explicitly exposed WordPress abilities. The free plugin must be useful on its own. Paid WP-Auto value belongs to substantive hosted services such as hosted MCP, Skills, automation, analytics, and multi-site orchestration.

## Current development phase

Phase 1 - Direct WordPress MCP MVP.

Phase 1.1 is complete: the direct MCP server foundation proves the authenticated end-to-end path with the read-only `wp-auto/site-health` ability.

Phase 1.2 is active. Phase 1.2.0 and Phase 1.2.1 are complete. The next active task is Phase 1.2.2: implement the frozen posts search/get contracts.

Do not jump ahead to bulk content tools, publishing, cloud pairing, Skills, automation, telemetry, or SaaS code unless the active task explicitly advances the roadmap.

## Phase 1 product acceptance target

A site administrator installs WP-Auto Connector, configures an authenticated direct MCP connection, and a compatible MCP client can:

1. connect to the WordPress site;
2. discover WP-Auto tools;
3. read site/content data;
4. create and update drafts;
5. upload and attach media;
6. manage categories/tags;
7. read and update supported SEO metadata;
8. do all of the above without receiving publish/delete/admin privileges by default.

Phase 1 is not complete until at least Claude Code and one additional standard MCP client can complete the documented end-to-end acceptance scenario.

## Product invariants

1. WordPress.org compliance is a release blocker.
2. The WordPress plugin is GPL-2.0-or-later.
3. The free plugin must provide useful direct MCP functionality and may not be trialware.
4. Do not implement locally present premium code that is merely disabled until payment.
5. External WP-Auto SaaS functionality must be substantive and must be disclosed in `readme.txt` before any release that calls it.
6. Never contact WP-Auto Cloud or another external service before the administrator explicitly enables that integration.
7. Never collect telemetry, usage data, site content, URLs, user data, or identifiers without explicit consent and documentation.
8. Never download or execute remote PHP, JavaScript, plugin, theme, or other executable code.
9. Do not modify WordPress core files or interfere with WordPress core update/security mechanisms.
10. Every state-changing operation must enforce WordPress capabilities server-side.
11. Remote authentication never replaces WordPress capability checks.
12. Nonces protect browser-originating admin actions; they do not authenticate MCP clients.
13. Validate/reject inputs, sanitize where appropriate, and escape output as late as possible.
14. Prefer WordPress APIs over direct database, filesystem, shell, or custom authentication implementations.
15. Never expose arbitrary PHP, shell, SQL, WP-CLI, filesystem, plugin/theme installation, or arbitrary code execution tools.
16. Draft creation and publishing must remain separate abilities and separate MCP tools.
17. Destructive and publish abilities are opt-in and disabled by default.
18. Secrets must never be logged, rendered in admin HTML, returned from tools, or included in exceptions.
19. Use `wp-auto-connector` as the plugin slug/text domain unless an approved naming decision changes it before WordPress.org submission.
20. MCP protocol/adaptation code must stay separate from WordPress domain abilities.

## MCP architecture rules

- WordPress 6.9+ Abilities API is the canonical domain capability layer.
- The official `WordPress/mcp-adapter` project is the preferred MCP protocol adapter.
- As of 2026-08-29, MCP Adapter is not available as a WordPress.org plugin dependency. Do not add `Requires Plugins: mcp-adapter` unless its directory status is re-verified and changed.
- For the current development path, use the official Composer-library/bundling approach documented by MCP Adapter. Keep the integration replaceable so a future WordPress.org plugin dependency can be adopted without changing WP-Auto ability contracts.
- Prefer an already active compatible MCP Adapter instance when present; do not initialize a second conflicting copy.
- Direct MCP and future cloud MCP must invoke the same WP-Auto abilities.
- Do not convert every registered WordPress REST route into an MCP tool.
- Only explicitly registered WP-Auto abilities may be exposed through the WP-Auto MCP server.
- Tool schemas are public contracts. Changes require updating `docs/MCP_TOOL_CATALOG.md` and tests.

## Phase 1 security rules

- Direct HTTP MCP requires authenticated WordPress identity for non-public operations.
- Use WordPress-supported authentication. Application Passwords are the Phase 1 baseline for remote clients unless the active task documents a better supported mechanism.
- HTTPS is required for remote direct-MCP setup outside local development.
- Each ability has its own `permission_callback` using the narrowest WordPress capability that fits the operation.
- MCP server transport permission checks and ability permission checks are separate defenses.
- Phase 1 defaults to read + draft/editor operations only.
- Publishing, permanent deletion, plugin/theme administration, user administration, and arbitrary settings writes are out of scope.
- Mutation tools must later implement idempotency and optimistic concurrency as specified in the Phase 1 plan.

## Phase 1.2 read-only invariants

- Phase 1.2 adds read-only abilities only; it must not mutate WordPress state.
- Search and list abilities must be bounded and paginated. Unlimited queries are prohibited.
- WordPress authentication and object-level authorization determine visibility. Never disclose inaccessible private content or its existence.
- Search results are lightweight discovery records. Full stored content is returned only by a get ability after target-object authorization.
- Do not expose arbitrary post meta, custom fields, query arguments, REST routes, or registered abilities.
- Only the explicitly approved WP-Auto abilities may enter the dedicated MCP server allowlist.

## Architecture rules

- PHP namespace: `WPAuto\\Connector`.
- Global functions, hooks, option names, transients, cron hooks, REST namespaces, and database objects must use a collision-resistant `wp_auto_connector_` or equivalent `wp-auto` prefix.
- Prefer small services with explicit responsibilities.
- One class/interface/trait/enum per PHP file.
- Do not introduce a web application framework inside the WordPress plugin.
- Business workflows and paid Skills belong to WP-Auto Cloud, not this repository.
- Do not create empty architecture directories before they have implementation.

## WordPress.org rules to preserve

- Human-readable source must be available for generated/minified assets and build instructions must be documented.
- Bundled Composer dependencies must be license-compatible, necessary, reviewable, and included in dependency/license review before release.
- Use WordPress bundled/default libraries where appropriate instead of shipping duplicate core libraries.
- External services must be disclosed in `readme.txt`, including purpose, data sent, trigger, and Terms/Privacy links before release.
- Admin upsells must be limited, contextual, and non-disruptive.
- Do not place external credits/links on the public-facing site without explicit permission.
- Do not keyword-stuff the readme or use more than five tags.
- Increment plugin version for every release and keep `Stable tag` synchronized.
- WordPress.org SVN is the release distribution source after approval; GitHub is the development source.

## Coding standards

- Target PHP 8.1+ and WordPress 6.9+ unless an approved architecture decision changes the minimums.
- Follow WordPress PHP, JavaScript, CSS, HTML, accessibility, internationalization, and inline documentation standards.
- All user-facing strings must be translatable.
- Escape output with the context-appropriate WordPress escaping function.
- Capability check before privileged work.
- For browser form mutations: capability check + nonce verification.
- REST routes require explicit `permission_callback`.
- Never use `__return_true` for any route or ability that exposes private data or changes state.

## Task discipline for Codex

Before editing:
1. Read `AGENTS.md` completely.
2. Read the relevant roadmap/spec/ADR documents.
3. Inspect existing implementation and tests.
4. State the narrow outcome and files likely to change.

During editing:
1. Keep the patch scoped to the requested sub-phase.
2. Do not refactor unrelated code.
3. Add/update tests with behavioral changes.
4. Update docs when public behavior, schemas, security assumptions, dependencies, or architecture change.
5. Do not silently choose a different MCP architecture.

Before finishing:
1. Run the narrowest relevant tests first.
2. Run `composer lint` for PHP changes.
3. Run static/unit/integration tests provided by the repository.
4. Run Plugin Check when a WordPress + Plugin Check environment is available.
5. Review `git diff` for secrets, generated junk, unrelated edits, and accidental external calls.
6. Report completed work, validation performed, and remaining blockers.

## Definition of done for a development task

A task is not complete until:
- acceptance criteria are met;
- security and WordPress capability checks are present where applicable;
- lint/tests pass or blockers are explicitly reported;
- no undocumented external service call was added;
- relevant documentation is updated;
- the patch remains inside the requested phase/sub-phase.

## Git workflow for solo development

`main` is the only long-lived branch and the only release source. Keep its history linear and keep every commit independently understandable and reversible.

### Start every task safely

1. Run `git status --short --branch` and inspect the current branch and working tree.
2. If the working tree contains changes, treat them as user-owned. Do not discard, overwrite, stash, reset, or mix them into the new task without explicit direction.
3. Start clean work from an up-to-date `main`:

```bash
git switch main
git pull --ff-only
```

`--ff-only` is required. If it fails, stop and inspect the divergence instead of creating an automatic merge commit.

### Choose direct main or a short-lived branch

Small, complete, low-risk changes may be developed directly on `main`. Examples include focused documentation fixes, a narrow test correction, or a self-contained implementation that can be completed and validated in one session.

Create a short-lived branch for:
- multi-step or unfinished work;
- dependency upgrades;
- cross-module refactors;
- experimental changes;
- security-sensitive or otherwise high-risk changes.

Use these branch prefixes:
- `feat/<short-description>`;
- `fix/<short-description>`;
- `chore/<short-description>`.

Before merging an unpublished short-lived branch, update it onto current `main`, then fast-forward `main`:

```bash
git fetch origin
git rebase origin/main
git switch main
git pull --ff-only
git merge --ff-only <branch-name>
git push origin main
git branch -d <branch-name>
```

Delete the remote short-lived branch after a successful push when one was published. Never keep a second long-lived development branch.

### Commit discipline

- One commit must represent one independently reversible outcome. Do not batch unrelated changes.
- Use Conventional Commits: `feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `chore:`, `build:`, or `ci:`.
- Write imperative, outcome-focused subjects, for example:
  - `feat: expose site health ability`;
  - `test: cover mcp transport permissions`;
  - `docs: document direct mcp validation`.
- Prefer `git add <explicit-paths>` or `git add -p` over broad staging.
- Before committing, inspect `git diff`, `git diff --cached`, and `git status --short` for unrelated files, secrets, generated junk, and missing tests or docs.
- Do not amend or rebase commits that have already been pushed. Fix published mistakes with a new commit or `git revert`.

### Required checks before commit

Run checks from narrowest to broadest. The minimum repository gate is:

```bash
composer validate --strict
composer test
composer lint
```

When `composer.json` or `composer.lock` changes, also run:

```bash
composer audit --locked
composer install --no-dev --prefer-dist --optimize-autoloader --dry-run --no-interaction
```

Do not commit with failing checks. If a required environment is unavailable, document the exact blocker instead of claiming completion.

### Checkpoints and rollback

- Prefer a small committed checkpoint on a short-lived branch over long-lived `git stash` state. Temporary WIP commits must be cleaned into meaningful commits before merging.
- To unstage a mistaken path without losing its working copy, use `git restore --staged <path>`.
- An unpublished local commit may be adjusted with `git reset --soft HEAD~1`, but inspect the target commit first.
- Revert any pushed change with `git revert <commit>` and push the revert normally.
- Never use `git reset --hard`, `git checkout -- <path>`, force push, or published-history rewriting as a routine rollback mechanism.
- Existing release tags are immutable. Never move or overwrite a published tag; create a corrective commit and a new patch release instead.

### Release and tag procedure

Use semantic versions and annotated tags named `vX.Y.Z`.

1. Update the plugin header version, `WP_AUTO_CONNECTOR_VERSION`, `readme.txt` `Stable tag`, and changelog together.
2. Commit the release metadata as `chore: release vX.Y.Z`.
3. Run all required local checks, including dependency audit and production-install dry run.
4. Push `main` and wait for the GitHub Actions quality workflow to pass.
5. Create and push the immutable annotated tag:

```bash
git tag -a vX.Y.Z -m "WP-Auto Connector vX.Y.Z"
git push origin vX.Y.Z
```

GitHub is the development source. WordPress.org SVN remains the release distribution source after directory approval.

### Codex Git authority

Codex may inspect Git state as part of normal development, but must not create commits, tags, branches, releases, or pushes unless the user explicitly requests that Git operation. Destructive Git commands and force pushes require explicit target-specific authorization and remain disallowed when a safer revert-based path exists.
