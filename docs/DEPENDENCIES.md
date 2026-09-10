# Runtime Dependencies

Phase 1.1 uses three Composer runtime packages. Versions below are fixed by `composer.lock`.

| Package | Locked version | License | Purpose |
| --- | --- | --- | --- |
| `wordpress/mcp-adapter` | `0.6.1` | GPL-2.0-or-later | Official WordPress Abilities API to MCP protocol adapter and HTTP transport. |
| `wordpress/php-mcp-schema` | `0.1.3` | GPL-2.0-or-later | Typed MCP protocol data-transfer objects used by MCP Adapter. |
| `automattic/jetpack-autoloader` | `5.0.23` | GPL-2.0-or-later | Required by the locked upstream MCP Adapter package and retained with its reviewed source/license metadata. |

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

Composer install/update runs `tools/build-mcp-runtime.php`. The deterministic
build verifies the exact locked Adapter 0.6.1 and PHP MCP Schema 0.1.3 versions,
then copies their PHP sources into `vendor/wp-auto-mcp-runtime/` while applying
the ADR-008 private namespace, hook/filter prefix, CLI command, and session-key
transformations. The generated manifest records the source versions and the
upstream license files are copied beside the generated runtime. Tests compare
every generated PHP file against the locked source and transformation.

The standalone dependency bootstrap at
`vendor/wordpress/mcp-adapter/mcp-adapter.php` is excluded by `.distignore`.
WP-Auto loads only `vendor/wp-auto-mcp-runtime/autoload.php` through
`McpAdapterLoader`; shipping the dependency's second WordPress plugin header is
unnecessary. The source packages remain in production `vendor/` for
human-readable WordPress.org review and reproducible generation. No code is
downloaded or generated at WordPress request time.

Before every WordPress.org release:

1. Re-resolve dependencies only as a deliberate reviewed update.
2. Run `composer audit --locked`.
3. Re-check every runtime package license and upstream source.
4. Inspect the final ZIP for development dependencies, nested plugin headers, generated junk, and unnecessary files.
5. Re-check whether MCP Adapter is available in the WordPress.org directory and reconsider the dependency strategy under ADR-001.
