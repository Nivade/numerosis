---
paths:
  - '**/*.php'
---

# General

## Code comments state the constraint, not the reasoning around it
Comments here drifted into essay prose: 300 em-dashes, 95 "rather than", 53 ", not " antitheses across ~3,000 comment lines in src/. That cadence is an AI tell and it buries the fact.

Rules for `//` and PHPDoc prose:
- Default to zero comments. If code needs explaining, the names are wrong. Fix the name, extract the method, delete the comment.
- A comment earns its place only by carrying a fact the code cannot: why an alternative was rejected, an external system's behaviour, a race, an invariant spanning files. Those stay.
- Lead with the fact. One line if one line does it; a 4-line block needs 4 distinct facts, not one fact restated.
- Ban the antithesis reflex: "X, not Y", "rather than", "instead of", "is not A but B". State what is true. Only contrast when the wrong alternative is one a reader would actually reach for, and then name the failure it causes.
- No em-dash asides, no semicolon-joined clauses, no parenthetical hedging. Split into sentences or delete.
- No restating code ("// Loop through users"), no section banner comments.
- Consequences get named concretely: "throws TenantDatabaseAlreadyExistsException", not "would race it".
- PHPDoc: keep `@param`/`@return`/array shapes. Prose in a docblock follows the same rules.
- Any comment prose that survives the bullets above gets a pass through the `unslop` skill before the edit lands. Read `references/core-contract.md`, apply it to the comment text, keep every technical fact. The `crisp` preset is the register to aim for.

`unslop` is installed per-machine at `~/.claude/skills/unslop`, not in this repo. An agent without it applies the bullets above by hand; they encode the tells that matter here. Its `banned_phrase_scan.py` scores this repo's comments clean — the catalog targets marketing slop, and code comments fail on cadence instead. Use the skill's judgment pass, not its scanner.
