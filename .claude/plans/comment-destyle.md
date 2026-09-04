# Comment de-styling sweep for `src/`

Bring every comment and docblock in `src/` up to `.ai/rules/general.md`. Read
that rule first; it is the standard and this plan is only the execution order.

Two commits already landed (`5bc10e4`, `c4b84f8`) covering nine sites in six
files. Everything else is untouched.

## Scope

`src/` only. `tests/`, `database/`, `config/`, `routes/`, `workbench/`,
`packages/` and `resources/` are explicitly out of scope and unmeasured.

Baseline as of 2026-09-04, after the two commits above:

| Metric | Count |
| --- | --- |
| Docblock prose lines | 2,138 |
| Inline `//` lines | 354 |
| Em-dashes in comments | 294 |
| `rather than` / `instead of` | 117 |
| `, not ` | 50 |
| Colon reveals | 57 |
| Decorative bold | 33 |
| Offending lines total | 468 |
| Files with at least one | 137 |
| Files with five or more | 26 |

Regenerate these before starting; other sessions commit to this repo.

```bash
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ | wc -l
grep -rnE '^\s*(\*|//).*(—|rather than|instead of|, not |\*\*)' --include='*.php' src/ \
  | awk -F: '{print $1}' | sort | uniq -c | sort -rn
```

## Before writing anything

Read `.ai/rules/general.md`, then read the `no-ai-slop` skill at
`~/.claude/skills/no-ai-slop/SKILL.md` and its `eval.md`. The skill's pattern
catalog is the standard. The grep patterns above find four of its patterns;
the skill names roughly fifteen, and the others (faux-insight setups,
importance puffery, interpretive metadiscourse, fake-strong verbs, synonym
cycling) will not show up in any grep. Read the whole docblock, not the
matched line.

Do not run the `unslop` skill. Its detection layer returns zero findings on
this code and its contract then requires returning the source unchanged.
`validate_preservation.py` from that skill is used below, and is the only
part of it that applies.

## Batches

One commit per batch. Twelve batches, largest offenders first, so an
abandoned sweep still leaves the worst files fixed.

| # | Files | Offending lines |
| --- | --- | --- |
| 1 | `src/NumerosisServiceProvider.php` | 41 |
| 2 | `src/Support/Numerosis.php` | 31 |
| 3 | `src/Testing/CleansUpTenancyDatabases.php`, `src/Testing/FakeStripeHttpClient.php` | 28 |
| 4 | `src/Support/HostConfig.php` | 18 |
| 5 | `src/Commands/InstallNumerosisCommand.php` | 15 |
| 6 | `src/Providers/TenancyServiceProvider.php`, `src/Resolvers/PreservingPathTenantResolver.php` | 20 |
| 7 | `src/Livewire/Tenant/Registration.php`, `src/Livewire/Billing/Checkout.php` | 19 |
| 8 | `src/Support/Contributions.php`, `src/Support/Cache/GlobalCache.php`, `src/Support/Assets.php`, `src/Support/Features.php` | 31 |
| 9 | `src/Http/**` (controllers and middleware) | ~30 |
| 10 | `src/Features/**` | ~21 |
| 11 | `src/Models/**`, `src/Services/**` | ~25 |
| 12 | Everything remaining under `src/` | remainder |

Batch 9 onward covers many small files. Work the ranked list within each
batch and stop when the batch's files are clean.

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

   The identifier diff must come back empty. Restore anything it lists.

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

## Committing

The working tree carries roughly 150 modified files from other sessions.
**Stage by explicit path. Never `git add -A` or `git add src/`.**

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

## Expected outcome

Inline comments compressed roughly 50%, docblocks roughly 20%. The docblock
prose is mostly facts, so a large reduction there means facts were lost.
Treat a batch that halves a docblock as a signal to re-read it, not a win.
