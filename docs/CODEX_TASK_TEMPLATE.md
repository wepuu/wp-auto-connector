# Codex Task Template

Use one task per narrow roadmap sub-phase or independently testable outcome.

## Goal

Describe one observable end state.

## Context

- Active roadmap phase/sub-phase:
- Relevant files/docs:
- Existing behavior:
- Public contracts affected:

## Requirements

1. ...
2. ...
3. ...

## Non-goals

- ...
- ...

## Security / WordPress.org constraints

- Required WordPress capability:
- Authentication assumptions:
- External service impact:
- Dependency/license impact:

## Acceptance criteria

- [ ] ...
- [ ] ...
- [ ] ...

## Validation

Run the narrow checks first, then the repository checks that exist, including:

```bash
composer lint
```

Add task-specific PHPUnit/integration/MCP validation commands.

## Documentation updates

Update the relevant contract/spec/ADR when behavior, dependencies, permissions, or schemas change.

## Deliverable

Return:
- files changed;
- behavior implemented;
- public schemas/contracts added or changed;
- tests/checks run and their results;
- security/WordPress.org considerations;
- blockers and recommended next task.
