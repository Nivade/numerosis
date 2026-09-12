---
paths:
  - '**'
---
# Sub-agents — Execution Constraint

Execution constraint, not a codebase fact. Restated in `CLAUDE.md`; this file
records why it kept getting overridden.

- Do NOT spawn, invoke, or initialize sub-agents, child agents, or background
  instances, **except the cavecrew agents named below**.
- ALL other tasks, tool usage, file operations, and terminal executions happen
  directly within this single, main active session.
- If a task feels too large or complex for a single thread and no cavecrew
  agent fits, do not delegate it. Stop and ask the user to break the request
  into smaller, sequential steps.

## The cavecrew exception (2026-09-12)

`cavecrew-investigator`, `cavecrew-builder` and `cavecrew-reviewer`, from the
`caveman` plugin, are allowed. Their output is compressed, so delegating to
them spends less main-thread context than doing the same work inline — which
is the opposite of the cost that motivated the blanket ban.

Scope of the exception:

- Only those three, and only within their stated remits — locating code,
  a bounded 1-2 file edit, reviewing a diff. `cavecrew-builder` refuses 3+
  file scope by design; do not work around that by spawning several.
- Everything else still applies: no `general-purpose`, no `Explore`, no
  `Plan`, no forks, no background instances.
- The harness gates independently — it only calls an agent when the user asks
  for one. This rule lifting the repo-level ban does not make cavecrew a
  default; it makes it available when requested.

## Why the blanket ban existed

The repo shipped `.claude/agents/*.md` and `.codex/agents/*.toml` — ten agent
definitions each (`architect`, `database`, `filament`, `pest`, …) — **alongside
this rule**. An agent reading the config could reasonably conclude either way,
and a rule contradicted by the config next to it loses. Both directories were
deleted 2026-09-01 (`.claude/agents/` is recoverable from git history;
`.codex/agents/` was untracked and is not).

That deletion still stands. Do not re-add per-repo agent definitions: the
cavecrew agents come from the plugin, so there is nothing in this tree for a
future session to read as contradicting the paragraphs above.
