# Phase 11 — The comment sweep

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first.

**Run this last, after phase 10.** It touches every file the other phases edit.

**No behaviour changes. No test expectation may move.**

`.ai/rules/general.md` is the whole standard. Read it in full before starting —
this brief summarizes it and the rule file governs where they differ.

## 11.1 is closed — the docblocks are already cut

The plan names eight files as over the five-line cap. Verified with the rule's
own `awk`, one file at a time: **none of the eight has a docblock over five
prose lines.** `MigrateTenant.php`, `StartImpersonation.php`,
`GetCurrentImpersonation.php`, `SeatLimitPlanPolicy.php`, `AnonymizeUser.php`,
`PortableTenantDatabaseDumper.php`, `TenantDataExporter.php`, `Activity.php` are
all inside it.

Re-run the check across the whole tree rather than trusting that list — the rule
file explains why the multi-file form misattributes and why `\s` silently matches
nothing under mawk:

```bash
awk '/^[[:space:]]*\/\*\*/{n=0;f=0} /^[[:space:]]*\*[[:space:]]*```/{f=!f; next} f{next} /^[[:space:]]*\*/ && !/^[[:space:]]*\*[[:space:]]*@/ && !/^[[:space:]]*\*[[:space:]]*$/ && !/\*\//{n++} /\*\//{if(n>5) print n"\t"FILENAME":"FNR; n=0}' $(git ls-files 'src/**.php') | sort -rn
```

Fix whatever that reports. Do not fix from the plan's list.

## 11.2 — The `//` runs

Verified, two sites:

- `src/Testing/CleansUpTenancyDatabases.php:298-302` — 5 consecutive lines
- `src/Testing/CleansUpTenancyDatabases.php:398-401` — 4 consecutive lines

The cap is 3. Run the rule's check **one file at a time**, with an `END` block,
or a run at end-of-file is never printed and runs are attributed to the wrong
file:

```bash
git ls-files 'src/**.php' | while read -r f; do
  awk '/^[[:space:]]*\/\//{n++; if(n==1) s=FNR; next} {if(n>3) print n"\t"FILENAME":"s; n=0} END{if(n>3) print n"\t"FILENAME":"s}' "$f"
done
```

## 11.3 — The cadence

Four patterns from the rule's table: em dashes, binary contrast (`rather than`,
`, not `), colon reveals, decorative bold. Find them:

```bash
grep -rnE '^\s*(\*|//).*(—|rather than|, not )' --include='*.php' src/
```

Shorten or restate. The rule names examples that were live when it was written;
the grep is the authority now, not the list.

## 11.4 — The dangling pointers

**`.ai/rules` and `.claude` citations: none left.** Verified with the rule's wide
form across `src/ config/ routes/ database/ workbench/ packages/ resources/`,
including Blade and JS. Re-run it; do not assume.

```bash
grep -rnE '(\*|//|\{\{--).*(\.ai/rules|\.claude)' src/ config/ routes/ database/ workbench/ packages/ resources/
```

**`docs/` pointers: more than the plan found.** `/docs` is `export-ignore`d, so
every one of these dangles for a Composer install.

- `config/numerosis.php:735` — a comment pointing at `docs/host-requirements.md`
- `src/Console/Commands/InstallNumerosisCommand.php` — **eight runtime strings**,
  not one: `:155`, `:271`, `:572`, `:583`, `:609`, `:626`, `:638`, `:686`,
  `:762`. These are printed to an operator, not comments, so the rule's comment
  grep misses them.

The comment at `config/numerosis.php:735` states the fact instead of citing the
file. The runtime strings are a judgement call the plan did not anticipate:
**decision, already made — keep them.** An operator running `numerosis:install`
on a machine with the package installed can reach the docs on GitHub, and a
failure message that names where the answer lives is more useful than one that
does not. The rule is about *source comments*, whose reader has no such prompt.
Say so in the commit so the next sweep does not re-litigate it.

## 11.5 — The wrong comments

Each needs verifying before editing; the plan's line numbers predate the
simplify branch.

- `src/Http/Middleware/EnsureStaffTwoFactor.php` — says the staff screens "mint
  impersonation links". That was deleted 2026-09-03 and rebuilt elsewhere.
- `src/NumerosisServiceProvider.php` — `private const array AUDITED_EVENTS` was
  inserted between `registerEventListeners()`'s docblock and the method, so the
  docblock now documents the constant. Also: the `activitylog.clean_after_days`
  comment sits above the `prune_data_exports` block, two `if`s from the command
  it describes.
- `src/Models/Central/Domain.php:92` — `dueForCheck()`'s comment duplicates
  `VerifyDomains`' class docblock word for word. **Phase 3 rewrites both.** If
  phase 3 has landed, re-read them before touching either.
- The `{tenant}`-prefix `UrlGenerationException` explanation appears three times
  across `UpdateTwoFactorRequirementController` and
  `UpdateTwoFactorRequirementRequest`. One fact, stated once, in the place that
  owns it.

## Shortening drops facts silently

For every comment you shorten rather than delete, run
`validate_preservation.py` from the `unslop` skill
(`~/.claude/skills/unslop/scripts/`) on the old text against the new. Strip the
`//` and `*` markers first and feed it two plain-text files. Read its "negation
count dropped" warning: a comment falling from 14 negations to 9 may have
inverted a claim.

Then diff the backticked identifiers, which catches what the tool buries:

```bash
grep -o '`[^`]*`' old.txt | tr -d '`' | sort -u > /tmp/o.txt
grep -o '`[^`]*`' new.txt | tr -d '`' | sort -u > /tmp/n.txt
comm -23 /tmp/o.txt /tmp/n.txt
```

## `composer lint` will be loud here

This is the phase where Rector's accumulated rewrites are most likely to land in
your diff. Read `git status` after `composer lint` and carry anything your edits
did not cause as a separate `refactor: apply rector` commit.

## Commit

```
style(comments): one fact per comment, and no pointers that dangle

The caps are five docblock prose lines and three consecutive // lines,
with no exemption for a public seam. Two runs in CleansUpTenancyDatabases
were over. The em-dash and "rather than" cadence went with them.

A comment citing docs/ dangles for every Composer install, since /docs is
export-ignored. The config comment states its fact directly instead.
InstallNumerosisCommand's runtime strings keep their pointers on purpose:
an operator reading a failure message can reach the docs, and the rule is
about source comments, whose reader cannot.

Four comments described code that had moved: the staff 2FA middleware
still talked about minting impersonation links, a constant had been
inserted between a docblock and the method it documented, the activity-log
retention note sat two branches from the command it describes, and one
UrlGenerationException explanation appeared three times.

No behaviour change.
```
