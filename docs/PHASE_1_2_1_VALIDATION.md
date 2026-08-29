# Phase 1.2.1 Site Info Validation

## Contract

- Ability: `wp-auto/site-info`
- MCP tool: `wp-auto-site-info`
- Endpoint: `/wp-json/wp-auto/mcp`
- Permission callback: `current_user_can( 'read' )`
- Dedicated-server allowlist: exactly `wp-auto/site-health` and `wp-auto/site-info`

The strict empty input object rejects additional properties. The result contains exactly:

- `site_name`
- `site_description`
- `site_url`
- `home_url`
- `language`
- `timezone`
- `permalink_structure`
- `multisite`

## WordPress data sources

| Field | WordPress Core API |
| --- | --- |
| `site_name` | `get_bloginfo( 'name' )` |
| `site_description` | `get_bloginfo( 'description' )` |
| `site_url` | `get_site_url()` |
| `home_url` | `get_home_url()` |
| `language` | `get_bloginfo( 'language' )` |
| `timezone` | `wp_timezone_string()` |
| `permalink_structure` | `get_option( 'permalink_structure' )` |
| `multisite` | `is_multisite()` |

No user, credential, filesystem, database, server, plugin, theme, or environment data is returned.

## Automated validation

Executed on 2026-08-29:

- Focused PHPUnit: 4 tests, 31 assertions passed.
- Full PHPUnit: 12 tests, 72 assertions passed.
- WordPress Coding Standards: 14 files passed.

The tests cover canonical registration, the `wp_abilities_api_init` hook, strict input/output schemas, all eight required fields, read-only annotations, a non-UTC `Asia/Shanghai` timezone, output privacy, ability-level `read` permission, and the exact two-ability MCP allowlist.

## Live MCP validation

Validated against a disposable WordPress 6.9 / PHP 8.1 / MariaDB environment with the bundled official MCP Adapter 0.6.1 and the current plugin mounted read-only.

| Check | Result |
| --- | --- |
| Authenticated `initialize` | Success; server `WP-Auto Direct MCP` |
| `notifications/initialized` | HTTP 202 |
| `tools/list` | Exactly 2 tools |
| Tool names | `wp-auto-site-health`, `wp-auto-site-info` |
| `wp-auto-site-info` call | Success; `isError = false` |
| Result fields | Exactly the eight documented fields |
| Timezone | `Asia/Shanghai` |
| Permalink structure | `/%postname%/` |
| Anonymous `initialize` | HTTP 401 |
| Authenticated user without `read` | HTTP 403 |
| Session termination | HTTP 200 |

The disposable Apache environment did not have rewrite rules available, so live requests used:

```text
/index.php?rest_route=/wp-auto/mcp
```

This is the WordPress query-route form of the same registered REST route. The normal configured endpoint remains `/wp-json/wp-auto/mcp`.

No MCP content/configuration mutation ability, external WP-Auto request, Cloud MCP call, or telemetry was introduced or invoked. WordPress fixture setup was confined to the disposable validation environment.
