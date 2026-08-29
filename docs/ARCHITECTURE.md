# WP-Auto Connector Architecture

## Current target: Direct MCP first

```text
Claude Code / WorkBuddy / standards-compliant MCP client
                         |
                         | Streamable HTTP / MCP
                         v
                WP-Auto MCP Server
                         |
                         v
              Official MCP Adapter
                         |
                         v
            WordPress Abilities API
                         |
                         v
             WP-Auto Ability Layer
                         |
       +-----------------+------------------+
       |                 |                  |
     Site              Content            Media ...
                         |
                         v
                 WordPress Core APIs
```

The WordPress ability layer is the single source of domain operations. MCP is an adapter over abilities, not a second implementation of WordPress CRUD.

## Direct MCP endpoint target

The WP-Auto custom server should target a stable endpoint in the form:

```text
/wp-json/wp-auto/mcp
```

The exact route is implemented through the official MCP Adapter custom-server API and must be covered by integration tests. Do not create an unrelated hand-written JSON-RPC REST server if the official adapter can provide the required behavior.

## Ability naming

Canonical ability names use a stable WP-Auto namespace:

```text
wp-auto/site-health
wp-auto/site-info
wp-auto/posts-search
wp-auto/post-get
wp-auto/post-create-draft
wp-auto/post-update
```

Do not encode third-party SEO plugin names into public tool contracts.

## Ability requirements

Each ability must define:
- label/description;
- input schema;
- output schema where supported/appropriate;
- execute callback;
- narrow permission callback;
- public/MCP exposure metadata required by the selected adapter version;
- annotations describing read-only/destructive/idempotent behavior when supported.

## Authentication and authorization

Direct HTTP MCP has two layers:

1. Transport/client authentication establishes a WordPress identity.
2. Each ability checks the WordPress capability required for the operation.

Application Passwords are the Phase 1 remote-auth baseline. HTTPS is required outside local development.

A successful MCP authentication must never grant more WordPress authority than the authenticated WordPress account already has.

## Planned plugin modules

```text
src/
  Abilities/
    Site/
    Content/
    Media/
    Taxonomy/
    Seo/
  Auth/
  Mcp/
  Admin/
  Diagnostics/
  Infrastructure/
  Integrations/
```

Create directories only when real implementation requires them.

## Official MCP Adapter dependency

As of 2026-08-29:
- WordPress 6.9+ contains Abilities API in core;
- WordPress MCP Adapter latest stable release observed during project initialization is v0.6.1;
- official MCP Adapter documentation states that WordPress.org `Requires Plugins` dependency is not yet supported because MCP Adapter is not yet listed in the directory;
- official documentation allows bundling it as a Composer dependency and recommends a collision-safe autoloading strategy such as Jetpack Autoloader or dependency prefixing.

WP-Auto therefore treats MCP Adapter as a replaceable integration dependency. See `docs/ADR-001-MCP-ADAPTER-DEPENDENCY.md`.

## Future cloud architecture

```text
AI clients
    | direct MCP                       | cloud MCP
    v                                  v
WP-Auto Connector <-------------- WP-Auto Cloud Gateway
       |
       v
WP-Auto Ability Layer
```

Direct MCP and cloud MCP must invoke the same abilities and permission checks.
