# Comment de-styling sweep for `src/`

Bring every comment and docblock in `src/` up to `.ai/rules/general.md`. Read
that rule first; it is the standard and this plan is only the execution order.

Three commits have landed against this goal: `5bc10e4` and `c4b84f8` (nine
sites in six files), plus this plan itself in `dda8ee2`. Everything else is
untouched.

**Audited 2026-09-04**, after `d65c2c3`. Three feature commits landed on `src/`
between the original baseline and this audit (`1502308`, `b391c39`, `d65c2c3`),
touching 97 files and adding 48, which is why the counts below are higher than
the plan originally recorded and why the batch list was re-cut.

## Execution constraints

- **No sub-agents.** `CLAUDE.md` forbids spawning them under any
  circumstances. One session does the whole sweep. If it will not fit, stop
  after a batch and hand the next batch back to the user.
- One commit per batch. A batch is finished only when its files return zero
  from the ranked grep below.
- Run Pint before Pest, always. Pint rewrites files, and Pest run first would
  be testing the pre-Pint tree.

## Scope

`src/` only. `tests/`, `database/`, `config/`, `routes/`, `workbench/`,
`packages/` and `resources/` are explicitly out of scope and unmeasured.

## Baseline

Every number here is reproducible with the command beside it. Regenerate the
whole table before starting; other sessions commit to this repo.

| Metric | Count (2026-09-04) | Command |
| --- | --- | --- |
| Offending lines total | 490 | `A` below |
| Files with at least one | 155 | `A` with `-l` |
| Files with five or more | 25 | `B` below |
| Docblock prose lines | 2,301 | `C` below |
| Inline `//` lines | 389 | `grep -rE '^\s*//' --include='*.php' src/ \| wc -l` |
| Em-dashes in comments | 311 | `A` with `(—)` |
| `rather than` / `instead of` | 118 | `A` with `(rather than\|instead of)` |
| `, not ` | 57 | `A` with `(, not )` |
| Decorative bold | 33 | `A` with `(\*\*)` |

```bash
# A — the ranked grep. This is the definition of "offending line".
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ | wc -l

# B — ranked by file, worst first. Drives batch ordering and batch completion.
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ \
  | awk -F: '{print $1}' | sort | uniq -c | sort -rn

# C — docblock prose: star-prefixed lines that are not `*/`, not an @tag, not blank.
grep -rE '^\s*\*' --include='*.php' src/ \
  | grep -vE ':\s*\*/' | grep -vE ':\s*\*\s*@' | grep -vE ':\s*\*\s*$' | wc -l
```

Two metrics from the original baseline are gone. "Colon reveals: 57" was
recorded without a regex and reproduces under none — four candidate patterns
give 19, 10, 18 and 159 at `dda8ee2`. Do not chase the number; colon reveals
are still a defect, just caught by reading rather than by grep. "Docblock
prose: 2,138" was likewise unpublished; command `C` above reconstructs it
(2,139 at `dda8ee2`, off by one) and is now the definition.

## Before writing anything

Read `.ai/rules/general.md`, then read the `no-ai-slop` skill at
`~/.claude/skills/no-ai-slop/SKILL.md` and its `eval.md`. The skill's pattern
catalog is the standard. Grep `A` finds four of its patterns; the skill names
roughly fifteen, and the others (faux-insight setups, importance puffery,
interpretive metadiscourse, fake-strong verbs, synonym cycling) will not show
up in any grep. Read the whole docblock, not the matched line.

Do not run the `unslop` skill. Its detection layer returns zero findings on
this code and its contract then requires returning the source unchanged.
`validate_preservation.py` from that skill is used below, and is the only
part of it that applies.

## Batches

Sixteen batches, largest offenders first, so an abandoned sweep still leaves
the worst files fixed. Counts sum to 490.

| # | Files | Offending lines |
| --- | --- | --- |
| 1 | `src/NumerosisServiceProvider.php` | 41 |
| 2 | `src/Support/Numerosis.php` | 31 |
| 3 | `src/Testing/CleansUpTenancyDatabases.php`, `src/Testing/FakeStripeHttpClient.php` | 28 |
| 4 | `src/Support/HostConfig.php` | 18 |
| 5 | `src/Commands/InstallNumerosisCommand.php` | 15 |
| 6 | `src/Http/Controllers/Billing/WebhookController.php` | 14 |
| 7 | `src/Providers/TenancyServiceProvider.php`, `src/Resolvers/PreservingPathTenantResolver.php` | 20 |
| 8 | `src/Livewire/Tenant/Registration.php`, `src/Livewire/Billing/Checkout.php` | 19 |
| 9 | `src/Support/Contributions.php`, `src/Support/Cache/GlobalCache.php`, `src/Support/Assets.php`, `src/Support/Features.php` | 34 |
| 10 | `src/Http/**` minus batch 6 | 35 |
| 11 | `src/Features/**` | 23 |
| 12 | `src/Models/**`, `src/Services/**` | 47 |
| 13 | `src/Actions/**` | 53 |
| 14 | `src/Contracts/**`, `src/Concerns/**`, `src/Enums/**`, `src/Data/**` | 45 |
| 15 | `src/Listeners/**`, `src/Events/**`, `src/Observers/**`, `src/Notifications/**`, `src/Policies/**` | 31 |
| 16 | Remainder: `src/Support/**` not yet done, `src/Livewire/**` not yet done, `src/Console/**`, `src/Exceptions/**`, `src/Rules/**`, `src/Testing/**` not yet done, `src/Jobs/**` | 36 |

Batch 6 is new to this audit. `WebhookController.php` was inside the original
plan's "`src/Http/**`, ~30" bucket; it is now the sixth-worst file in `src/`
on its own and is worth its own commit.

Batches 10 onward cover many small files. Work the ranked list from grep `B`
within each batch and stop when every file in the batch's paths returns zero.
Batches 13 to 16 are dominated by files with one or two offending lines each
(74 files in `src/` have exactly one), most of them from the invitations,
social-login and domain-events work in `1502308` and `d65c2c3`. Those are
usually a single em-dash in an otherwise fine docblock. Do not restructure a
docblock that has one defect.

## Per-file procedure

1. Snapshot the comment text before editing:

   ```bash
   strip() { grep -E '^\s*(\*|//)' | sed -E 's@^\s*(//|\*/?)\s?@@' | sed '/^\s*$/d'; }
   git show HEAD:PATH | strip > /tmp/old.txt
   ```

2. Read the whole file. Edit comments and docblock prose only.

3. Snapshot again and run both preservation checks:

   ```bash
   strip < PATH > /tmp/new.txt
   grep -o '`[^`]*`' /tmp/old.txt | tr -d '`' | sort -u > /tmp/o.txt
   grep -o '`[^`]*`' /tmp/new.txt | tr -d '`' | sort -u > /tmp/n.txt
   comm -23 /tmp/o.txt /tmp/n.txt
   python3 ~/.claude/skills/unslop/scripts/validate_preservation.py /tmp/old.txt /tmp/new.txt
   ```

   The identifier diff must come back empty. If it lists anything, put that
   identifier back into the new comment text before moving on; do not accept
   it as reworded. The only exception is an identifier that was deleted along
   with a whole sentence you deliberately dropped, which the commit body must
   then name.

   `validate_preservation.py` will report missing "code" constraints that are
   really rewrapped prose between backticks; those are a known false positive.
   Read its `warnings` array. A large drop in negation count means checking
   that no claim was inverted.

4. Move on. Run the suite once per batch, not per file.

## Hard constraints

- **Never change a line of code.** Verify before every commit:

  ```bash
  git diff --cached -U0 -- src/ | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' \
    | grep -vE '^[+-]\s*(//|\*|/\*)'
  ```

  Any output means a code line moved. Stop and undo it.

- **Never touch `@param`, `@return`, `@var`, `@template`, `@throws` or array
  shape annotations.** PHPStan runs at level 9 and reads them.

- **Never delete a fact.** A comment naming a race, an exception class, an
  external system's behaviour, a rejected alternative or a caller contract
  keeps that content. Shorten the prose around it.

- **Do not reflow untouched paragraphs.** Rewrapping a paragraph you did not
  edit inflates the diff and defeats review.

## Verification per batch

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest
composer analyse
```

Pint before Pest, so Pest does not run against files Pint is about to
rewrite. `composer analyse` matters here because docblock edits can move
annotations; compare cold-to-cold if the result cache looks suspicious
(`.ai/rules/static-analysis.md`).

Then confirm the batch is actually finished:

```bash
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' <BATCH PATHS>
```

Empty output, or nothing but lines you consciously kept, means commit.

## Committing

Check `git status --porcelain` first. The tree was clean at this audit, but
other sessions commit to this repo and it has previously carried ~150
unrelated modified files. **Stage by explicit path. Never `git add -A` or
`git add src/`.**

Branch: `package-scope-reduction` unless told otherwise.

```
docs: de-style comments in <area>

<what changed, and any fact deliberately dropped>

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

## Judgment calls to escalate rather than guess

Ask the user instead of deciding alone when:

- A comment is the only record of a caller contract, and cutting it is the
  difference between the rule's "default to zero" and losing a real fact. One
  such sentence was dropped in `InlineCheckoutGateway.php` in commit
  `5bc10e4`; that was flagged, not silent.
- A public seam's docblock (`src/Support/Numerosis.php`,
  `src/Support/Contributions.php`) would lose material a host developer reads
  on hover. These are API documentation and shrink far less than internal
  comments, roughly 20% against 50%.
- The whole docblock looks deletable because the method name already says it.
  Deleting a public API docblock is a larger call than restyling one.

Escalating means stopping and asking in the session. Do not delegate the
question.

## Expected outcome

Inline comments compressed roughly 50%, docblocks roughly 20%. The docblock
prose is mostly facts, so a large reduction there means facts were lost.
Treat a batch that halves a docblock as a signal to re-read it, not a win.

## Note for whoever audits this next

This file is the only place counts live. `.ai/rules/general.md` used to carry
its own table (2,145 docblock prose lines, 366 inline, 300 em-dashes, 58 colon
reveals, 36 decorative bold, 95 `rather than`, 53 `, not `), undated and with
no regex; it was wrong within three commits and was removed on 2026-09-04. The
rule now states the standard and points here. Keep it that way — if the sweep
finishes and this plan is archived, the counts go with it rather than moving
back into the rule.
