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

Docblocks are the bulk of the problem, not `//` comments: 2,145 prose lines against 366, carrying 270 of the em-dashes and 96 of the "rather than"/"instead of".

## Shortening a comment drops facts silently

Run `validate_preservation.py` from the `unslop` skill (`~/.claude/skills/unslop/scripts/`) on the old comment text against the new, before the edit lands. Strip the `//` and `*` markers first and feed it two plain-text files.

It caught four losses across `Numerosis.php`, `Features.php` and `AcceptInvitation.php` that a careful hand-edit had already reviewed and called finished: `FortifyServiceProvider::configureRoutes()`, `spatie/laravel-one-time-passwords`, backticks stripped off `mergeConfigFrom()`, and a second failure path in the invitation flow. Its "negation count dropped" warning is worth reading; a comment shrinking from 14 negations to 9 may have inverted a claim.

Diffing the backticked identifiers is the sharper check, since the tool reports rewrapped prose between backticks as a missing "code" constraint and buries the real losses:

```bash
grep -o '`[^`]*`' old.txt | tr -d '`' | sort -u > /tmp/o.txt
grep -o '`[^`]*`' new.txt | tr -d '`' | sort -u > /tmp/n.txt
comm -23 /tmp/o.txt /tmp/n.txt
```

## Do not run `unslop` itself on this code

Its detection layer returns zero findings here. `suggest.py` on the five worst files, including 267 lines of `Numerosis.php`, reported `hard: 0, soft: 0` on every one, and the core contract's rule is then "with no findings, return the source exactly". Followed properly the skill authorizes none of this cleanup; followed loosely it is theater over an edit already made by hand.

The catalog hunts marketing slop (empty abstraction, inflated claims, stock praise) and this repo's comments have none of it. They are dense, accurate and too long. The contract states outright that "soft cadence and document-shape scores never authorize edits alone", and cadence is the whole defect here. Its one structure flag, `conclusion_coda`, fires identically before and after a rewrite, from treating a PHP file as an essay with a closing paragraph. `banned_phrase_scan.py` scores the comments clean.

The bullets above are the standard. `validate_preservation.py` is the safety net. Nothing else from that skill applies.
