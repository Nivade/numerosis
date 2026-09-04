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

### How long a comment may be

One budget, no exemptions. Visibility does not buy extra lines: not `public`, not a class docblock, not a documented seam, not a trait a host composes into its own tests.

- A docblock MUST NOT exceed 5 prose lines. An inline comment, or a run of consecutive `//` lines, MUST NOT exceed 3.
- A comment MUST NOT carry more than one fact. Three paragraphs are three facts, and at most one of them is about the code below. `NumerosisServiceProvider::registerAuthRateLimiters()` held three: the tenant-keyed bucket (about the method), the OTP challenge's separate limiter (about a different method), and a bug in a vendor package (about neither). Only the first stayed.
- The cap routes; it does not compress. A fact that needs more than 5 lines goes somewhere a document can hold it — `.ai/rules/` for a codebase trap, `docs/` for anything a host needs to act on — and the source keeps the one sentence a reader of *this* code needs, with no pointer back (see the cross-reference rule above).
- `@see`, `@param`, `@return` and the rest are tags, not prose. They do not count against the cap, and `{@see SomeClass::method()}` is not a cross-reference: it names a symbol the reader can open.

Splitting a 30-line docblock into six 5-line ones on adjacent members is a violation, not a fix. The cap is on prose, and the one-fact rule is what decides whether the prose should exist at all.

```bash
# docblock prose
awk '/^[[:space:]]*\/\*\*/{n=0} /^[[:space:]]*\*/ && !/^[[:space:]]*\*[[:space:]]*@/ && !/^[[:space:]]*\*[[:space:]]*$/ && !/\*\//{n++} /\*\//{if(n>5) print n"\t"FILENAME":"FNR; n=0}' $(git ls-files 'src/**.php') | sort -rn

# runs of // lines
awk '/^[[:space:]]*\/\//{n++; if(n==1) s=FNR; next} {if(n>3) print n"\t"FILENAME":"s; n=0}' $(git ls-files 'src/**.php') | sort -rn
```

POSIX classes, not `\s`: the default `awk` here is mawk, which does not
support `\s` and silently matches nothing rather than erroring. A version of
this check using `\s` reported zero hits against 114 real ones.

Baseline when the cap landed, 2026-09-04: 114 over-budget docblocks holding
1,147 prose lines, over half the docblock prose in `src/`, plus 10 `//` runs
over three lines. The worst are `Testing/CleansUpTenancyDatabases.php` (a
39-line class docblock), `Support/Numerosis.php` (10 blocks) and
`Support/HostConfig.php` (5).

### `docs/` is not on a host's disk

`.gitattributes` marks `/docs`, `/tests` and `/workbench` `export-ignore`, so a Composer dist install has `src/`, `config/`, `routes/`, `resources/`, `database/`, `stubs/` and `.ai/` — and no `docs/`. A source comment saying "see `docs/extending.md`" therefore dangles for every host, the same defect as the `.ai/rules` citations. State the fact or leave it out.

This does not make `docs/` the wrong home for displaced prose. It is where a host reads about this package, on GitHub or Packagist, before and after installing it; it is only unreachable from a comment. Move the prose there and say nothing about it in the source.

```bash
grep -rnE '^\s*(\*|//).*docs/' --include='*.php' src/
```

Docblocks are where the volume is. Prose inside one follows every rule above, exactly as a `//` comment does.

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
