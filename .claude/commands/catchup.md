---
description: Resume work after a break - get up to speed on current state
---

# Catchup — Resume After Break

## Usage

```
/catchup
```

## Workflow

1. **Git state.**
   ```bash
   git branch --show-current && git status --short && git log --oneline -5
   ```

2. **App state.**
   - Boost MCP `last-error` / `read-log-entries` (recent only), or
     `workbench/storage/logs/laravel.log` — this is a package, no root
     `storage/` at runtime; the workbench serves as the dev harness.
   - `composer test:impact` (only tests touching uncommitted changes —
     fast signal; use `composer test` for the full picture).

3. **Summary**
   ```
   ## Current State

   **Branch:** [name]

   ### Recent commits
   ### Uncommitted changes
   ### App health — errors, test results
   ### Next steps
   ```

4. **Continue** — suggest the next action. Do not spawn a subagent to do it
   (`.ai/rules/subagents.md`).
