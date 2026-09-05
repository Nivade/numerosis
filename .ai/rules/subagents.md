---
paths:
  - '**'
---
# Sub-agents — Execution Constraint

Execution constraint, not a codebase fact. Restated in `CLAUDE.md`; this file
records why it kept getting overridden.

- NEVER spawn, invoke, or initialize sub-agents, child agents, or background
  instances, under any circumstances.
- ALL tasks, tool usage, file operations, and terminal executions must happen
  directly within this single, main active session.
- If a task feels too large or complex for a single thread, do not delegate it.
  Stop and ask the user to break the request into smaller, sequential steps.

## Why this used to be ignored

The repo shipped `.claude/agents/*.md` and `.codex/agents/*.toml` — ten agent
definitions each (`architect`, `database`, `filament`, `pest`, …) — **alongside
this rule**. An agent reading the config could reasonably conclude either way,
and a rule contradicted by the config next to it loses. Both directories were
deleted 2026-09-01 (`.claude/agents/` is recoverable from git history;
`.codex/agents/` was untracked and is not).

If sub-agents are wanted here later, delete this file and `CLAUDE.md`'s
"Critical Execution Constraints" section in the same commit that re-adds the
definitions. Do not leave both.
