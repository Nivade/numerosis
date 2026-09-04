---
paths:
  - '**/*.php'
---
# General

## Comment style

Default to zero comments. If code needs explaining, the names are wrong. Fix the name, extract the method, delete the comment.

A comment earns its place when it carries a fact the code cannot. Why an alternative was rejected, how an external system behaves, a race, an invariant spanning several files.

Docblocks are where the volume is, not `//` lines. Keep `@param`, `@return` and array shapes exactly as they are. Prose inside a docblock follows the same rules as a `//` comment.

For the prose itself, use the `no-ai-slop` skill (`~/.claude/skills/no-ai-slop`). Its pattern catalog is the standard here, and it covers more than a hand-rolled list will. The four patterns this repo keeps failing on:

| Pattern | Example |
| --- | --- |
| Em dashes | `has not run — a discovered satellite provider` |
| Binary contrast (`rather than`, `, not `) | `Non-string schema names are dropped rather than coerced` |
| Colon reveal | `Zero-migration plan source: reads billing.plans` |
| Decorative bold | `bakes it in **at registration time**` |

Three repo-specific additions to that catalog. Name a consequence with the symbol it throws, so `throws TenantDatabaseAlreadyExistsException` rather than "would race it". Keep identifiers in backticks; stripping them loses the signal that a word is code. Never cite a `.claude/plans/*` file, or say "this plan"/"the plan", inside a code comment — plans are not docs: `.claude/plans/README.md` calls them historical the moment they're executed, so a comment anchored to one goes stale the day it merges. If the fact is worth keeping, put it in the docblock itself or in `.ai/rules/`; if it's only "why we built this", it belongs in the commit message or PR description, not the code.

`.claude/plans/comment-destyle.md` sweeps `src/` for these, and holds the dated counts and the greps that produce them. Counts do not live in this file: they were wrong within three commits last time they did.

## Shortening a comment drops facts silently

Run `validate_preservation.py` from the `unslop` skill (`~/.claude/skills/unslop/scripts/`) on the old comment text against the new, before the edit lands. Strip the `//` and `*` markers first and feed it two plain-text files.

It caught four losses across `Numerosis.php`, `Features.php` and `AcceptInvitation.php`, in edits that had already been reviewed by hand and called finished: `FortifyServiceProvider::configureRoutes()`, `spatie/laravel-one-time-passwords`, backticks stripped off `mergeConfigFrom()`, and a second failure path in the invitation flow. Read its "negation count dropped" warning too. A comment falling from 14 negations to 9 may have inverted a claim.

Diffing the backticked identifiers catches more, because the tool reports rewrapped prose between backticks as a missing "code" constraint and buries the real losses:

```bash
grep -o '`[^`]*`' old.txt | tr -d '`' | sort -u > /tmp/o.txt
grep -o '`[^`]*`' new.txt | tr -d '`' | sort -u > /tmp/n.txt
comm -23 /tmp/o.txt /tmp/n.txt
```

Nothing else from `unslop` applies. Its detection layer returns zero findings on this code, with `suggest.py` reporting `hard: 0, soft: 0` on the five worst files including 267 lines of `Numerosis.php`. The catalog hunts marketing slop, and the contract states that soft cadence never authorizes an edit on its own. Cadence is the entire defect here.
