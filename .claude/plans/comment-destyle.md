# Comment de-styling sweep for `src/`

Bring every comment and docblock in `src/` up to `.ai/rules/general.md`. Read
that rule first. It is the standard; this plan is only the execution order.

## Execution constraints

- **No sub-agents.** `CLAUDE.md` forbids spawning them under any
  circumstances. One session does the work. If it will not fit, stop after a
  phase and hand the next one back to the user.
- **Sonnet executes.** Phase 1 is a calibration loop with the user; the rest
  runs on the judgments it produces.
- One commit per batch or phase step. Pint before Pest, always.

## Scope

`src/` only. `tests/`, `database/`, `config/`, `routes/`, `workbench/`,
`packages/` and `resources/` are out of scope and unmeasured.

## Baseline

Regenerate before starting; other sessions commit to this repo.

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
| Comments citing `.ai/rules` or `.claude` | 38 across 28 files | `D` below |

```bash
# A — the ranked grep. This is the definition of "offending line".
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ | wc -l

# B — ranked by file, worst first. Drives batch ordering and batch completion.
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ \
  | awk -F: '{print $1}' | sort | uniq -c | sort -rn

# C — docblock prose: star-prefixed lines that are not `*/`, not an @tag, not blank.
grep -rE '^\s*\*' --include='*.php' src/ \
  | grep -vE ':\s*\*/' | grep -vE ':\s*\*\s*@' | grep -vE ':\s*\*\s*$' | wc -l

# D — comments citing a rule file or a plan. The rule now forbids these outright.
grep -rnE '^\s*(\*|//).*(\.ai/rules|\.claude)' --include='*.php' src/
```

Grep `A` finds four defects. `no-ai-slop` names roughly fifteen; the rest
(faux-insight setups, importance puffery, interpretive metadiscourse,
fake-strong verbs, synonym cycling) show up in no grep. Read the whole
docblock, not the matched line.

Two earlier metrics are gone. "Colon reveals: 57" was recorded without a regex
and reproduces under none — four candidates give 19, 10, 18 and 159. Colon
reveals are still a defect, just caught by reading. "Docblock prose: 2,138" was
likewise unpublished; command `C` reconstructs it and is now the definition.

## The four remedies

For every comment, work this list in order and stop at the first that applies.

1. **Delete — changelog.** Why the code came to be this shape: dated moves,
   old class names, superseded designs, "split out of X on 2026-09-01". Git
   has it. `general.md` says it belongs in the commit message.
   *Exception:* keep it when the history explains something you would
   otherwise get wrong today, such as why a migration filename is what it is.
   This one needs reading, not grepping — a grep for `used to` matches "is
   used to support", and most `no longer` hits are current-state facts.

2. **Delete — cross-reference.** A comment referencing `.ai/rules/`, a skill,
   or `.claude/plans/`. The rule forbids these with no exception. If the
   source carries a sentence the rule file lacks, **add it to the rule file
   first**, then delete from source. Leave no pointer behind; a pointer is
   itself a reference.

3. **Extract or rename.** Could a careful reader recover the comment's content
   from the code? Then it is narration. Extract a method or fix a name so the
   code carries it, and delete the comment. Expect this to improve the code
   without cutting much comment volume: the fact-bearing sentences survive the
   extraction and move onto the new method.

4. **Keep, and restyle the prose.** What is left is a fact the code cannot
   carry: an external system's behaviour, a race, a rejected alternative, a
   cross-file invariant, a performance rationale. Fix the cadence, keep the
   fact.

Remedy 2 is where the volume is. Remedy 3 is where the code improves. Do not
confuse the two when reporting progress.

## Phase 1 — calibration, 10 changes, judged

Before any autonomous work, propose ten changes one at a time and wait for the
user's verdict on each. The ten **must** cover all four remedies, not ten easy
deletions, and should include at least two extractions and two sites from a
public seam (`src/Support/Numerosis.php`, `src/Support/Contributions.php`).

Proposal format, one site per message:

```
Site:    path:line — Class::method
Remedy:  delete-changelog | delete-crossref | extract | restyle
Before:  <comment, verbatim>
After:   <replacement, or "deleted">
Facts:   <any fact leaving the file, and where it lands>
```

For an extraction, show the resulting method signatures too. Do not batch
proposals. Do not apply until the verdict comes back.

Record every verdict in the log below **in this file**, in the same commit that
lands the change. The log is the point of the phase; a session that loses it
has to redo it.

### Calibration log

| # | Site | Remedy | Verdict | What it settled |
| --- | --- | --- | --- | --- |
| 1 | `Support/ModelResolver.php:15-20` | delete-changelog | Approved | Pure why-it-was-split narrative deletes outright; git has it. |
| 2 | `Support/Cache/GlobalCache.php:19-20` | delete-crossref | Approved | Rule file already carried the fact verbatim; citation drops with no addition needed. |
| 3 | `NumerosisServiceProvider.php:342-344` | delete-changelog (not restyle) | Rejected initial restyle, approved full delete on retry | "Why X is absent" is decision-rationale, not an active invariant the code enforces — treat it as changelog even when phrased as a docblock. Belongs in the commit message, not a restyled-and-kept comment. Method name (`registerPolicies`) is self-explanatory; only add a code comment back if something later needs to *guard against* `CentralUser` gaining a policy, not to explain why it doesn't have one. |
| 4 | `NumerosisServiceProvider.php:477-479` | delete-crossref | Approved, reworded | "Kept in step with" overstated the guarantee — no test enforces the two-registries sync `.ai/rules/middleware-registration.md` warns about. Reworded to state the sync is manual and unenforced, not just "kept in step", so the DX gap stays visible in the code, not just in the rule file. |
| 5 | `Commands/InstallNumerosisCommand.php:426-428` | delete-crossref + rule addition | Approved | Rule file was missing that `tenant_pattern` config is Subdomain-only; add it there first, then trim source. |
| 6 | `Providers/TenancyServiceProvider.php:77-82` | delete-changelog | Approved full delete | Const-to-method history is git's; the "why a method not a constant" fact doesn't need restating either — the method's own shape (`public static function`, returns dynamically) already answers it. |
| 7 | `Support/Numerosis.php:71-77` | restyle → delete (downgraded) | Approved, cut to one sentence | **Key calibration finding**: design-rationale prose ("why WeakMap not a plain bool") is not caller-facing and should be deleted, not restyled-and-kept, even though remedy 4 would technically allow keeping it as "a fact the code cannot carry". Raises the bar for remedy 4 above what the plan text implies: keep only facts that actively bite a caller (external system behavior, races, footguns), not implementation-choice justification. Push design rationale to commit messages / rule files instead. |
| 8 | `Support/Contributions.php:27-32` | restyle | Approved, rewritten (not just restyled) | Distinguishes from #7: this is a caller-facing footgun (two similarly-named methods answering different questions), not design rationale, so it survives under the site-7 bar — but the original prose was confusing independent of cadence, so restyling alone wasn't enough; it needed an actual rewrite for clarity. |
| 9 | `Livewire/Tenant/Registration.php:168-182,205-209` | extract | Approved | Duplicate `stepNames()->search()` narration-comment sites collapse into one private `currentStepIndex()`; all three comments were pure narration and drop with no fact lost. |
| 10 | `Livewire/Tenant/Registration.php:178-181,197-202` | extract | Approved, comments partially kept | Extracted duplicate `ucwords(str_replace('-', ' ', ...))` into `humanize()`. Unlike #7/#8, the two comments here (1-based step numbering, slug mirrors Livewire Wizard's own naming) are facts about an external convention/library, not design rationale — kept as-is per the site-7 bar rather than deleted. |

After round 10, re-read the log end to end and treat the accumulated verdicts
as binding for every phase below. Where a verdict contradicts this plan, the
verdict wins; note the contradiction in the commit body.

## Phase 2 — cross-reference purge

38 comments across 28 files cite `.ai/rules` or `.claude`. Work from grep `D`.
For each: check whether the rule file already carries the fact, add the missing
sentence to the rule file if it does not, then delete from source.

`src/Services/Tenancy/Bootstrappers/PasswordBrokerBootstrapper.php` is the
worked case. Its 42-line docblock duplicates `.ai/rules/auth-login.md` almost
paragraph for paragraph — the same three Fortify controllers, the same
`array_map('app', …)` mechanism, the same `AuthGuardBootstrapper` fallback
observation. The rule is missing one sentence the docblock has: a broker
resolves its user model through `auth.passwords.*`, not `auth.guards.*`. Add
that to the rule, then cut the docblock to what a reader of the class needs.

Edit existing rule files by hand. Use `record-rule` only for a genuinely new
rule file, and diff `.ai/rules/index.md` afterwards — it regenerates the whole
table from `paths:` frontmatter and drops any file lacking one.

Commit per rule-file topic, so a reviewer sees source and rule move together.

## Phase 3 — changelog purge

Read every hit from this grep and judge each one. Most are false positives.

```bash
grep -rnE '^\s*(\*|//).*(20[0-9]{2}-[0-9]{2}-[0-9]{2}|[Ss]plit out of|used to |[Pp]reviously|was extracted|has since|no longer)' --include='*.php' src/
```

Roughly six are clear changelog, led by the class docblocks of
`ModelResolver.php`, `Contributions.php`, `Assets.php` and `Numerosis.php`,
which open by narrating the 2026-09-01 split. `InstallNumerosisCommand.php`'s
migration-rename history stays: it explains filenames a host still has to
match. A dated measurement stays too — `index.md` asks for claims to be dated
next to the measurement that qualifies them.

One `docs:` commit.

## Phase 4 — length, then cadence

**Length runs first, and it changed what Phase 4 is.** `.ai/rules/general.md`
gained a hard cap on 2026-09-04 (`66920f1`): 5 prose lines per docblock, 3 per
inline comment or `//` run, no exemption for `public` members, class docblocks
or documented seams. Tags do not count, and one fenced example of up to 5 lines
is allowed per docblock.

Baseline the day the cap landed, from the two checks in `general.md`:

| Metric | Count (2026-09-04) |
| --- | --- |
| Over-budget docblocks | 112 |
| Prose lines in them | 1,089 |
| Files holding at least one | 76 |
| `//` runs over three lines | 10 |

That is more than half the docblock prose in the package. The counts before
the fenced-example concession were 114 and 1,147.

Cadence work on a paragraph that the cap will delete is wasted. Batch 1 proved
it: `registerAuthRateLimiters()`'s docblock was restyled line by line, then cut
from 23 lines to 5 an hour later, and the restyling of the two deleted
paragraphs bought nothing. So:

1. **4a, length.** Take the over-budget blocks worst-first. Each fact that
   outgrows the cap moves to `.ai/rules/` (codebase trap) or `docs/` (anything
   a host acts on) before it leaves the source. This phase writes
   documentation, not just deletions, and it is the larger half of the work.
2. **4b, cadence.** The batch table below, over whatever prose survives 4a.
   Expect it to be well under the 357 hits outstanding at the time of writing.

Re-run both checks in `general.md` between the two, not once at the start.

### 4a batches — over-budget blocks, worst first

| # | File | Prose lines | Blocks |
| --- | --- | --- | --- |
| 1 | `src/Support/Numerosis.php` | 132 | 10 |
| 2 | `src/Testing/CleansUpTenancyDatabases.php` | 87 | 6 |
| 3 | `src/Commands/InstallNumerosisCommand.php` | 51 | 5 |
| 4 | `src/Support/HostConfig.php` | 45 | 5 |
| 5 | `src/Livewire/Tenant/Registration.php` | 42 | 4 |
| 6 | `src/NumerosisServiceProvider.php` | 40 | 3 |
| 7 | `src/Support/Contributions.php` | 39 | 4 |
| 8 | `src/Support/ModelResolver.php` | 34 | 3 |
| 9 | `src/Providers/TenancyServiceProvider.php` | 28 | 2 |
| 10 | `src/Actions/Auth/**` | 59 | 4 |
| 11 | `src/Http/**` | 56 | 4 |
| 12 | Remainder: 66 files holding one or two blocks each | ~490 | ~64 |

The three that will hurt, because each holds host-facing content that has to
land in `docs/` rather than be deleted: `CleansUpTenancyDatabases`'s 39-line
class docblock (a trait hosts compose into their own suites),
`Numerosis::routes()`'s 24 lines (the `withAuth: false` documentation), and
`RedirectIfOneTimePasswordAuthenticatable`'s 24-line class docblock.

One commit per destination, so a reviewer sees the source cut and the new home
for its facts in the same diff.

### 4b batches — cadence

Sixteen batches, worst first, so an abandoned sweep still leaves the worst
files fixed. Counts are from the 2026-09-04 baseline and are already stale
where 4a has been; re-run grep `B` and treat the table as ordering, not as a
contract.

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
| 16 | Remainder: `src/Support/**`, `src/Livewire/**` and `src/Testing/**` not yet done, plus `src/Console/**`, `src/Exceptions/**`, `src/Rules/**`, `src/Jobs/**` | 36 |

Batches 1 (`44edc63`) and 2 (`679b225`) landed before the cap existed, so both
files are cadence-clean and still over budget on length; 4a revisits them.

Batches 13 to 16 are mostly files with one or two offending lines — 74 files in
`src/` have exactly one, most from the invitations, social-login and
domain-events work in `1502308` and `d65c2c3`. Usually a lone em-dash in an
otherwise fine docblock. Fix the line; do not restructure the docblock.

Each batch produces up to two commits: a `docs:` commit for remedies 1, 2 and
4, and a separate `refactor:` commit for any remedy 3 extraction. Never mix
them.

## Per-file procedure

1. Snapshot the comment text before editing:

   ```bash
   strip() { grep -E '^\s*(\*|//)' | sed -E 's@^\s*(//|\*/?)\s?@@' | sed '/^\s*$/d'; }
   git show HEAD:PATH | strip > /tmp/old.txt
   ```

2. Read the whole file. Apply the remedy list.

3. Snapshot again and run both preservation checks:

   ```bash
   strip < PATH > /tmp/new.txt
   grep -o '`[^`]*`' /tmp/old.txt | tr -d '`' | sort -u > /tmp/o.txt
   grep -o '`[^`]*`' /tmp/new.txt | tr -d '`' | sort -u > /tmp/n.txt
   comm -23 /tmp/o.txt /tmp/n.txt
   python3 ~/.claude/skills/unslop/scripts/validate_preservation.py /tmp/old.txt /tmp/new.txt
   ```

   The identifier diff must come back empty, **except** for identifiers that
   left with a deliberately deleted paragraph. Every one of those must be
   either genuinely dead or already written into a rule file, and the commit
   body must name it. Anything else the diff lists goes back into the comment.

   `validate_preservation.py` reports rewrapped prose between backticks as a
   missing "code" constraint; known false positive. Read its `warnings` array.
   A large drop in negation count means checking that no claim was inverted.

4. Move on. Run the suite once per batch, not per file.

## Hard constraints

- **`docs:` commits change no code.** Verify before committing:

  ```bash
  git diff --cached -U0 -- src/ | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' \
    | grep -vE '^[+-]\s*(//|\*|/\*)'
  ```

  Any output means a code line moved. Stop and undo it, or split it into the
  `refactor:` commit.

- **`refactor:` commits change no public surface.** Extractions are
  behaviour-preserving, and the new methods are `private` unless the class is
  designed for extension. Verify:

  ```bash
  git diff --cached -U0 -- src/ | grep -E '^[+-]\s*(public|protected) function'
  ```

  Output means the surface moved. A private method being added produces none.
  If an extraction needs a public signature change, escalate instead.

- **Never touch `@param`, `@return`, `@var`, `@template`, `@throws` or array
  shape annotations.** PHPStan runs at level 9 and reads them.

- **Never lose a fact.** A fact may be deleted from source only when it is in a
  rule file or genuinely dead. Shorten the prose around it otherwise.

- **Do not reflow untouched paragraphs.** Rewrapping a paragraph you did not
  edit inflates the diff and defeats review.

## Verification per batch

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest
composer analyse
```

Pint before Pest, so Pest does not run against files Pint is about to rewrite.
`composer analyse` matters because docblock edits can move annotations; compare
cold-to-cold if the result cache looks suspicious
(`.ai/rules/static-analysis.md`). A `refactor:` commit needs both green before
it lands, not at the end of the phase.

Then confirm the batch is finished:

```bash
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' <BATCH PATHS>
grep -rnE '^\s*(\*|//).*(\.ai/rules|\.claude)' --include='*.php' <BATCH PATHS>
```

Empty, or nothing but lines consciously kept, means commit.

## Committing

Check `git status --porcelain` first. Other sessions commit to this repo and it
has previously carried ~150 unrelated modified files. **Stage by explicit path.
Never `git add -A` or `git add src/`.**

Branch: `package-scope-reduction` unless told otherwise.

```
docs: de-style comments in <area>

<what changed, and every fact deleted from source with where it now lives>

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

## Escalate rather than guess

Stop and ask in session. Do not delegate the question.

- A comment is the only record of a caller contract, and cutting it is the
  difference between "default to zero" and losing a real fact. One such
  sentence went in `InlineCheckoutGateway.php` in `5bc10e4`; it was flagged,
  not silent.
- A public seam's docblock (`src/Support/Numerosis.php`,
  `src/Support/Contributions.php`) would lose material a host developer reads
  on hover. These are API documentation and shrink far less than internal
  comments.
- An extraction would change a public or protected signature.
- The whole docblock looks deletable because the method name already says it.
  Deleting a public API docblock is a larger call than restyling one.

## Expected outcome

Most of the reduction comes from remedies 1 and 2, which delete whole
paragraphs. Remedy 4 compresses inline comments roughly 50% and docblocks
roughly 20%. Remedy 3 barely moves the counts at all and is worth doing for the
code, not the numbers.

A batch that halves a docblock under remedy 4 alone is a signal to re-read it,
not a win. The docblock prose that survives phases 2 and 3 is mostly facts
about Fortify, Cashier and stancl that no renaming removes.

## Note for whoever audits this next

This file is the only place counts live. `.ai/rules/general.md` used to carry
its own table (2,145 docblock prose lines, 366 inline, 300 em-dashes, 58 colon
reveals, 36 decorative bold, 95 `rather than`, 53 `, not `), undated and with
no regex; it was wrong within three commits and was removed on 2026-09-04. The
rule states the standard and no longer points here. Keep it that way — when
this plan is archived the counts go with it.
