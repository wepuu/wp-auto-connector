# Phase 1.7.2 Release Package Portability Validation

Status: **local package portability acceptance complete; remote Codex and
WorkBuddy acceptance subsequently completed; formal release sealing remains
pending**

Runtime baseline: `main@864da2df1daea0326798cec2ffd50fb7204cd937`

## Defect and correction

The first Windows-built archive used backslashes in ZIP entry names, including
`wepuu-auto-connector\wepuu-auto-connector.php`. A Linux WordPress installation
could unpack the archive without resolving the expected plugin basename, which
produced `Plugin file does not exist` during activation. The rejected archive
SHA-256 is
`0a39e788bcb2e9104acd0853f65ac4c88c59e5292aa176f1b180ffcad9d50d1b`.

The new `tools/build-release-package.php` builder adds files to `ZipArchive`
with explicit `/` paths, a single `wepuu-auto-connector/` root, sorted entries,
normalized timestamps, a production-only allowlist, and hard failures for
backslashes, escaped roots, development files, archives, logs, missing entry
files, or the public MCP Adapter plugin entrypoint. `composer build:release`
provides the canonical local command.

## Accepted artifact

| Property | Result |
| --- | --- |
| File | `build/phase-1-7-2-fixed/wepuu-auto-connector.zip` |
| SHA-256 | `ed43c0c79deb2014fd1837478d98de48d0741d0f3ecff69c3c9de723ba1cb5a1` |
| Size | 978,781 bytes |
| Files / ZIP entries | 650 / 650 |
| Backslash entries | 0 |
| Main plugin entry | `wepuu-auto-connector/wepuu-auto-connector.php` |
| Private MCP Adapter | 0.6.1 |
| Public Adapter entrypoint | absent |

The archive contains production entry files, `src/`, readme/license/Composer
manifests, optimized production dependencies, and the private MCP runtime.
Tests, documentation, tools, build inputs, nested archives, credentials, logs,
and development dependencies are absent.

After all disposable WordPress environments were removed, the entire retained
build directory was deleted and rebuilt from zero. The second build produced
the same SHA-256, proving byte-for-byte reproducibility for this candidate.

## Exact ZIP installation

The accepted ZIP was mapped into a fresh Linux-based wp-env site and installed
through `wp plugin install ... --activate`, rather than mounting its staging
directory as the plugin. The observed environment and result were:

- WordPress 7.1;
- PHP 8.2.33;
- WePuu Auto Connector 0.1.0;
- `Plugin installed successfully`;
- plugin basename `wepuu-auto-connector` with status `active`;
- authenticated MCP protocol `2025-06-18`;
- exactly 23 tools in the frozen order.

An Application Password existed only in the probe process and was explicitly
deleted. The final task-specific Application Password count was zero. PHP logs
contained zero Warning, Notice, Deprecated, Parse, or Fatal lines after the
accepted activation and checks.

## Package and repository gates

| Gate | Result |
| --- | --- |
| Archive POSIX paths and exact root | PASS |
| Package PHP syntax | PASS - 628 files |
| Plugin Check 2.1.0 default strict scan | PASS - no errors or warnings |
| Plugin Check 2.1.0 runtime-enabled `cli.php` scan | PASS - no errors or warnings |
| `composer validate --strict` | PASS |
| PHPUnit | PASS - 486 tests, 3,172 assertions |
| WordPress Coding Standards | PASS - 139 files |
| `composer audit --locked` | PASS - no advisories |
| Production install dry-run | PASS |
| `git diff --check` | PASS |

Both disposable wp-env environments, their containers, volumes, networks,
Application Passwords, extracted Core, and temporary configs were removed.
The retained ignored build directory contains only the production staging
tree, package manifest, and accepted ZIP.

## Completion boundary

This checkpoint changes development/release tooling and documentation only. It
does not change production PHP, dependencies, persistence, public schemas,
permissions, error behavior, or the ordered twenty-three-tool MCP surface.
Installation and activation on the remote HTTPS site, followed by remote Codex
and WorkBuddy acceptance, subsequently completed. Site-scoped post-cleanup log
evidence and formal Phase 1 release sealing remain separate gates; Phase 1 and
a public release are not yet formally sealed.
