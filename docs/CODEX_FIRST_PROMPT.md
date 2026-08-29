# First Codex Implementation Prompt

Paste the following prompt into Codex after opening the repository root.

---

You are implementing Phase 1.1 of WP-Auto Connector: Direct MCP Server Foundation.

Before changing files, read these completely:

1. AGENTS.md
2. docs/ROADMAP.md
3. docs/ARCHITECTURE.md
4. docs/PHASE_1_DIRECT_MCP.md
5. docs/MCP_TOOL_CATALOG.md
6. docs/ADR-001-MCP-ADAPTER-DEPENDENCY.md
7. docs/PRODUCT_BOUNDARIES.md
8. docs/WORDPRESS_ORG_COMPLIANCE.md
9. docs/CODEX_TASK_TEMPLATE.md

Then inspect the existing plugin bootstrap, Composer configuration, admin screen, and repository quality tooling.

Goal:
Implement only Phase 1.1. Establish a standards-based direct MCP path using WordPress 6.9+ Abilities API and the official WordPress MCP Adapter, and prove it with one safe read-only `wp-auto/site-health` ability.

Requirements:

1. Integrate a compatible stable `wordpress/mcp-adapter` release using the dependency strategy in ADR-001. At the start of the task, verify the currently available stable release and relevant upstream integration API before editing. Do not assume APIs from memory.
2. Keep the adapter integration isolated behind small WP-Auto classes. Prefer an already loaded compatible MCP Adapter and avoid double initialization/conflicting copies.
3. Register the `wp-auto/site-health` WordPress ability on the correct Abilities API hook.
4. The ability must be read-only and require `current_user_can( 'read' )` or an equivalently narrow WordPress permission check.
5. Its output must contain only safe diagnostics needed for the Phase 1 proof: WordPress version, PHP version, WP-Auto Connector version, Abilities API availability, MCP Adapter availability/version, REST API availability, and HTTPS state. Do not expose usernames, admin email, filesystem paths, DB details, secrets, plugin inventory, or server environment dumps.
6. Create/register a WP-Auto MCP server using the official adapter. Target the direct endpoint `/wp-json/wp-auto/mcp` if the current adapter API supports that namespace/route model. If upstream behavior differs, document the exact endpoint and explain why; do not invent a second MCP implementation.
7. Expose only the Phase 1.1 site-health ability through the WP-Auto server. Do not expose every WordPress REST route or every registered ability.
8. Remote/private access must be authenticated according to the official adapter/WordPress transport model. Do not implement custom password storage, JWT, OAuth, or cloud pairing in this task. Application Password compatibility is the intended Phase 1 baseline.
9. Update the existing WP-Auto admin diagnostics/settings page minimally so an administrator can see: MCP availability, direct endpoint, Abilities API availability, adapter version/availability, HTTPS state, and a clear warning if requirements are missing. Do not build the full client-setup UI yet.
10. Add automated tests for ability registration, safe output shape, permission denial, and MCP server registration/integration where practical with the repository test environment.
11. Add or update development test tooling only as necessary for this task. Keep dependencies minimal and WordPress-compatible.
12. Add manual validation instructions for MCP initialize/tools discovery/tool invocation using the official adapter's supported test approach (MCP Inspector, curl, or WP-CLI as appropriate).
13. Update docs/MCP_TOOL_CATALOG.md if the adapter-generated MCP tool name differs from the canonical ability name.
14. Do not implement content search, create/update drafts, media, taxonomy, SEO, publishing, deletion, WP-Auto Cloud, telemetry, Skills, or automation.
15. Do not make any outbound request to WP-Auto Cloud or any external telemetry endpoint.
16. Preserve WordPress.org compliance and GPL-compatible dependency requirements.

Acceptance criteria:

- Plugin activates on WordPress 6.9+ / PHP 8.1+.
- Abilities API availability is detected correctly.
- The `wp-auto/site-health` ability is registered with an explicit permission callback.
- WP-Auto's MCP server is registered through the official adapter.
- A compatible authenticated MCP client can initialize/discover and invoke the site-health capability.
- Unauthenticated or unauthorized access to private data/tool execution is rejected by the actual transport/permission model.
- The WP-Auto MCP server exposes only the intended Phase 1.1 ability.
- No content mutation exists.
- No external WP-Auto service call exists.
- Relevant tests pass.
- `composer lint` passes.
- Documentation reflects the actual adapter version, endpoint, tool name, commands, and any unresolved packaging issue.

Work method:

- First report your implementation plan and any upstream API/package facts you verified.
- Then implement the smallest complete patch.
- Do not rewrite unrelated architecture.
- Do not silently expand scope.
- Run the narrowest tests first, then the repository checks.
- Review the final git diff.

At completion, return:

1. files changed;
2. behavior implemented;
3. actual MCP endpoint;
4. actual MCP tool name exposed for `wp-auto/site-health`;
5. authentication method verified;
6. tests/checks run and results;
7. WordPress.org/dependency considerations;
8. blockers;
9. recommended Phase 1.2 next task.

---
