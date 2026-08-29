# Runtime Dependencies

Phase 1.1 uses three Composer runtime packages. Versions below are fixed by `composer.lock`.

| Package | Locked version | License | Purpose |
| --- | --- | --- | --- |
| `wordpress/mcp-adapter` | `0.6.1` | GPL-2.0-or-later | Official WordPress Abilities API to MCP protocol adapter and HTTP transport. |
| `wordpress/php-mcp-schema` | `0.1.3` | GPL-2.0-or-later | Typed MCP protocol data-transfer objects used by MCP Adapter. |
| `automattic/jetpack-autoloader` | `5.0.23` | GPL-2.0-or-later | Collision-safe shared dependency selection when another plugin also bundles MCP Adapter packages. |

Upstream source and license metadata remain present in each installed Composer package. No remote executable code is downloaded at WordPress runtime.

## Development install

```bash
composer install
composer test
composer lint
```

## Production distribution

Build from the locked dependency graph:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Include the generated `vendor/` directory in the plugin ZIP. Do not include development packages.

The standalone dependency bootstrap at `vendor/wordpress/mcp-adapter/mcp-adapter.php` is excluded by `.distignore`. WP-Auto uses MCP Adapter as a Composer library and initializes `WP\\MCP\\Core\\McpAdapter` through `McpAdapterLoader`; shipping the dependency's second WordPress plugin header is unnecessary.

Before every WordPress.org release:

1. Re-resolve dependencies only as a deliberate reviewed update.
2. Run `composer audit --locked`.
3. Re-check every runtime package license and upstream source.
4. Inspect the final ZIP for development dependencies, nested plugin headers, generated junk, and unnecessary files.
5. Re-check whether MCP Adapter is available in the WordPress.org directory and reconsider the dependency strategy under ADR-001.
