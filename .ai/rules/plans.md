---
paths:
  - '.claude/plans/*.md'
---

# Plans

## Every loose plan needs a status marker, checked by PlanStatusLineTest
`.claude/plans/README.md`'s Live table is hand-copied from each plan's own status text and goes stale silently — `domain-events-expansion.md`, `invitations-social-redesign.md`, `comment-destyle.md`, `drifting-puzzling-flame.md` and `effervescent-questing-pumpkin.md` were all fully executed and still listed live until the 2026-09-12 re-audit; `delightful-doodling-turing.md` had no status text at all and turned out to be a superseded draft.

`tests/Feature/PlanStatusLineTest.php` now fails if any loose file directly under `.claude/plans/` (excluding `README.md`) has no case-insensitive "status" match in its first 15 lines. This does not stop a status line from going stale on its own — only the README/tree re-audit catches that — it just guarantees every loose plan has one to audit against, and forces a status decision when a plan is added or executed.

When a plan is confirmed executed, `git mv` it into `archive/` in the same pass as updating the README — don't leave it loose with an updated table row, that's the mechanism that let this drift compound.
