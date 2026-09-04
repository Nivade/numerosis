---
paths:
  - '**/*.php'
---
# General

## Comment style

Write self-documenting code. Names carry the meaning. If code needs explaining, the name is wrong: fix the name, extract the method, delete the comment.

Obligations, in RFC 2119 terms:

- Explanatory comments and docblock prose MUST NOT be added unless asked for.
- A comment MUST NOT narrate what the code does.
- A comment MUST NOT reference a `.ai/rules/` file, a skill, or a `.claude/plans/` file. A reader of the source cannot see those, and they go stale independently of it. If the fact is worth keeping, state it here or keep it only in the rule file. **`tests/` is exempt**, and only `tests/`: a test that exists to hold a rule down is allowed to name the rule it enforces, because the citation is the test's subject rather than background for something else. `src/`, `config/`, `routes/`, `database/` and `resources/` carried none as of 2026-09-04 — check with the grep below before assuming a survivor is deliberate.

  ```bash
  grep -rnE '^\s*(\*|//).*(\.ai/rules|\.claude)' --include='*.php' src/ config/ routes/ database/ workbench/ packages/
  ```
- `@param`, `@return`, `@var`, `@template`, `@throws` and array shape annotations MUST stay exactly as they are. PHPStan runs at level 9 and reads them.
- A comment MAY carry a fact the code cannot: how an external system behaves, a race, a rejected alternative, an invariant spanning several files, a performance rationale.
- Sentences SHOULD be short.

Docblocks are where the volume is, not `//` lines. Prose inside a docblock follows the same rules as a `//` comment.

For the prose itself, use the `no-ai-slop` skill (`~/.claude/skills/no-ai-slop`). Its pattern catalog is the standard here, and it covers more than a hand-rolled list will. The four patterns this repo keeps failing on:

| Pattern | Example |
| --- | --- |
| Em dashes | `has not run — a discovered satellite provider` |
| Binary contrast (`rather than`, `, not `) | `Non-string schema names are dropped rather than coerced` |
| Colon reveal | `Zero-migration plan source: reads billing.plans` |
| Decorative bold | `bakes it in **at registration time**` |

Two repo-specific additions to that catalog. Name a consequence with the symbol it throws, so `throws TenantDatabaseAlreadyExistsException` rather than "would race it". Keep identifiers in backticks; stripping them loses the signal that a word is code.

Why the code came to be this shape is not a comment. It belongs in the commit message or the PR description. Dated moves, old class names and superseded designs are already in git.

Counts do not live in this file. They were wrong within three commits last time they did, and a stale count invites trusting a rule that has moved underneath it.

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
