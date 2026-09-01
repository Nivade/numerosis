# Next session — pick up here

**Overwrite this file at the end of every session; don't append.** It's a
dispatch note, not a plan — the actual plans live in their own files.

## State at handoff, 2026-09-01

Both repos clean and committed. Nothing outstanding to land.

- `numerosis` — branch `package-scope-reduction`, two new commits:
  - `f3e0297 refactor: split the product out of the framework, reclaim overloaded names`
  - `63d216b perf: run the suite in parallel by default`
  - Untracked and deliberately left alone: `.agents/` (a third copy of the
    skills already in `.claude/skills/`).
  - **Not pushed.**
- `numerosis-thin-app` — branch `master`, one new commit:
  `bddeff1 feat: own this product's marketing pages, install numerosis-account`.

Green as of this handoff: `composer test` (669 passed, 7 skipped, ~62s),
`composer test-serial` (~175s), `composer test-browser` (10 passed),
`composer analyse` (0 outside a 203-entry baseline).

## What this session did

**1. Finished `confusion-cleanup.md` step 7 and verified the whole thing.**
That plan's step 7 was "done but NOT test-verified". It is now: the
`ModelResolver` extraction broke nothing. Steps 1–6 unchanged.

**2. PHPStan was not analysing `packages/` at all** — `paths` still listed only
core's directories, so all six satellite packages went uncovered at level 9
from the moment the split happened. Added, which surfaced 27 errors, including
one real defect: `DeleteAccount` referenced its view without the `numerosis::`
namespace — the only such reference in that package — so the panel would have
thrown on render, with no test covering it. Blade templates are excluded, as
core's own views always were. Three cross-package constant-contract assertions
went to the baseline (203 entries now, was 200).

**3. Made `--parallel` the default.** Full mechanism in
`.claude/rules/testing.md` under "How parallel was fixed"; the old "Why
parallel was dropped" section is kept below it with a note saying which of its
conclusions are now void. Short version: the deadlock those sessions died on
had already been removed as a side effect of `keepDatabaseSchema()`, and
nobody re-measured. The real problems were that only the *default* connection
follows the parallel token (this suite defines three), that
`tenancy.database.prefix` was shared across workers, and that
`fake()->unique()->safeEmail()` does not stay unique across tests.

**4. Host: verified against a real boot**, not Testbench — all five central
pages 200 over HTTPS, and `/` serves the host's own `welcome` view through the
new `numerosis.routes.home_view` seam.

## Next-step menu

1. **Push both repos.** Neither has been pushed; `numerosis` is on a feature
   branch, so this probably wants a PR.
2. **`confusion-cleanup.md`'s remaining loose ends**, none of them started:
   - `docs/architecture.md` and `docs/features.md` predate steps 1–7 and still
     describe the old layout (five-package table, now six;
     `AccountPagesFeature`/`MarketingPagesFeature` as core features;
     `Support\Numerosis` as 33 methods). `docs/host-requirements.md`'s §0
     package table is a package short.
   - Splitting `config/numerosis.php` (922 lines) — assessed as the riskiest
     item and deliberately deferred; the reasoning is in that plan.
   - The host still carries a full 922-line copy of `config/numerosis.php`; the
     deep-fill means it only needs the keys it actually changes.
   - The 23-of-32 single-implementation contracts. They are documented swap
     points in `numerosis.{billing,tenancy}.implementations`, so deleting them
     removes a real host feature — wants a decision, not a cleanup.
3. **`build/phpstan/cache` is root-owned** and needs
   `sudo rm -rf build/phpstan` to clear. Until then PHPStan needs the `tmpDir`
   override recipe in `.claude/rules/static-analysis.md`. `build/` is
   gitignored, so this is throwaway cache, not data.
