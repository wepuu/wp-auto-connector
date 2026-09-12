# Phase 1.7 Release Contract

Status: **Phase 1.7.0 documentation freeze; no release candidate declared**

Baseline: `main@c48ba31`

This document freezes the release-like package boundary, dependency review,
and WordPress.org gates for the next implementation checkpoint. It does not
change the plugin version, production source, dependencies, public schemas,
or the twenty-three-tool order.

## Release-like package

The deterministic package root is `wepuu-auto-connector/`. The allowlist is:

- the plugin entry point, `index.php`, `uninstall.php`, and production `src/`;
- `readme.txt`, GPL license notices, and Composer manifests/lock file;
- production Composer dependencies generated with
  `composer install --no-dev --prefer-dist --optimize-autoloader`;
- the private, locked MCP Adapter 0.6.1 runtime required by the connector.

The package must exclude `.git`, `.github`, `AGENTS.md`, development
documentation, tests, tools, fixtures, build/cache directories, `node_modules`,
ZIP archives, credentials, logs, generated diagnostics, provider plugin entry
points, and the provider's public MCP Adapter 0.5.0. No generated file may
silently replace human-readable source.

## Dependency and license review

The locked production dependency set is reviewed before every release-like
build. The current approved set is:

| Package | Version | Role | License review |
| --- | --- | --- | --- |
| `automattic/jetpack-autoloader` | 5.0.23 | Composer class loading | GPL-2.0-or-later compatible |
| `wordpress/mcp-adapter` | 0.6.1 | private MCP protocol runtime | GPL-2.0-or-later compatible |
| `wordpress/php-mcp-schema` | 0.1.3 | MCP schema/value objects | GPL-2.0-or-later compatible |

The build must retain `composer.lock`, pass `composer validate --strict` and
`composer audit --locked`, and be reproducible from the same source and lock
file. Bundled license notices and source provenance remain part of the review
record. A provider's own package is a test artifact, not a connector runtime
dependency.

## WordPress.org release gates

Before a release decision, the package must pass:

- approved plugin identity (`WePuu Auto Connector`, slug/text domain
  `wepuu-auto-connector`), valid header, synchronized version and Stable tag,
  and a human-readable `readme.txt`;
- WordPress 6.9 and 7.1 activation, uninstall, Multisite isolation, and
  supported PHP matrix checks;
- official Plugin Check static and runtime-enabled checks with zero errors and
  zero warnings;
- the exact ordered twenty-three-tool discovery and authenticated permission
  matrix;
- Composer, PHPUnit, WPCS, dependency-audit, production-install dry-run, and
  `git diff --check` gates;
- no telemetry, background requests, undocumented external service, secret,
  arbitrary code/meta/SQL interface, or provider-native MCP surface;
- no more than five readme tags and complete disclosure for caller-triggered
  remote image import before any release that ships it.

## Compatibility and security gates

The release evidence must include the client contract in
`PHASE_1_7_CLIENT_ACCEPTANCE.md`, a reproducible package manifest, and an exact
range security review from the current `main` baseline. Reviewers must check
capability enforcement, authentication and Origin handling, provider
coexistence, dependency loading, uninstall scope, output privacy, and the
absence of outbound requests. Dynamic provider tools and arbitrary WordPress
administration remain unavailable.

## Version policy

Phase 1.7.0 keeps plugin version `0.1.0`; no release tag is created by this
documentation checkpoint. Any later version bump must update the plugin header,
`WP_AUTO_CONNECTOR_VERSION`, `readme.txt` Stable tag, changelog, and release
notes together, followed by the full gate suite and an immutable annotated tag.
