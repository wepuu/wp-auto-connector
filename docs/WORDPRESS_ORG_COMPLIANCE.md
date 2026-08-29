# WordPress.org Compliance Baseline

This is an engineering checklist, not legal advice.

## Distribution and licensing

- [ ] GPL-2.0-or-later compatible code/assets only.
- [ ] Main plugin is complete/useful when submitted; do not submit a placeholder.
- [ ] Stable releases use WordPress.org SVN after approval.
- [ ] Plugin header version and `Stable tag` match.
- [ ] Human-readable source/build instructions exist for generated/minified assets.
- [ ] Bundled Composer packages have compatible licenses and documented source/upstream information.

## MCP Adapter dependency

Current initialization fact (2026-08-29): official MCP Adapter documentation says it is not yet available as a WordPress.org plugin dependency.

Before release:
- [ ] Re-check whether `mcp-adapter` is now listed on WordPress.org.
- [ ] If listed and appropriate, evaluate switching to `Requires Plugins: mcp-adapter`.
- [ ] If not listed, ensure the bundled Composer strategy follows official MCP Adapter guidance.
- [ ] Review the distribution ZIP for duplicate/nested plugin-header issues.
- [ ] Review all transitive dependency licenses/source.
- [ ] Ensure the bundled adapter does not conflict with a separately installed compatible MCP Adapter plugin.
- [ ] Document dependency build/update procedure for reviewers/developers.

## Free plugin vs paid service

- [ ] No local functionality is disabled solely until payment/license activation.
- [ ] Paid service provides substantive remote functionality.
- [ ] The plugin is not merely a storefront for paid services.
- [ ] SaaS dependency/behavior is clearly documented before it ships.

## Privacy and external services

Direct MCP to the user's own WordPress site is not a WP-Auto Cloud connection by itself.

Before any WP-Auto/external service request ships:
- [ ] Administrator explicitly opts in/connects the service.
- [ ] `readme.txt` explains the service in plain language.
- [ ] Service URL is documented.
- [ ] Terms of Service URL is documented.
- [ ] Privacy Policy URL is documented.
- [ ] Data categories transmitted are documented.
- [ ] Trigger/circumstances of transmission are documented.
- [ ] Purpose and retention expectations are documented.
- [ ] Disconnect/revoke behavior is implemented.
- [ ] No analytics/telemetry is enabled without consent.

## MCP security

- [ ] Remote MCP transport requires appropriate authentication.
- [ ] HTTPS is required for remote use outside local development.
- [ ] Every private or state-changing ability has a narrow `permission_callback`.
- [ ] Transport authentication does not bypass WordPress capability checks.
- [ ] Tool schemas reject unexpected/invalid input.
- [ ] List/search tools enforce reasonable pagination/limits.
- [ ] Secrets never appear in tools/list, tools/call results, logs, admin HTML, or errors.
- [ ] Publishing/permanent deletion are disabled by default.
- [ ] No arbitrary code/shell/SQL/filesystem/WP-CLI execution.

## General WordPress security

- [ ] Validate input; sanitize when appropriate.
- [ ] Escape output at render time.
- [ ] Capability checks on privileged operations.
- [ ] Nonces on browser-originated state changes.
- [ ] Explicit REST `permission_callback`.
- [ ] No remote executable code loading.
- [ ] No disabling/bypassing WordPress security/update mechanisms.

## Admin UX

- [ ] Notices are limited, actionable, and dismissible when appropriate.
- [ ] No dashboard hijacking.
- [ ] Upsell is contextual and limited.
- [ ] No public-facing credit links without explicit user permission.

## Readme

- [ ] `readme.txt` validates.
- [ ] At most five relevant tags.
- [ ] No keyword stuffing.
- [ ] Installation/client configuration is accurate.
- [ ] External services are disclosed before use.
- [ ] Changelog is maintained.

## Pre-submission gates

- [ ] `composer lint` passes.
- [ ] Unit/integration tests pass.
- [ ] Official Plugin Check passes required checks.
- [ ] Test on a clean WordPress install.
- [ ] Test activation/deactivation/uninstall.
- [ ] Test minimum PHP/WordPress versions.
- [ ] Test separately installed MCP Adapter compatibility if bundling remains necessary.
- [ ] Test multisite behavior or explicitly document unsupported behavior.
- [ ] Scan final ZIP manually: no `.git`, secrets, node_modules, test caches, local configs, or unnecessary development artifacts.
