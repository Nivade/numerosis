# Plans — historical, not authoritative

**Nothing in this directory describes how the package works today.** These are
session plans, kept for the reasoning and the incident history in them. A plan
records what someone intended at a point in time; several were superseded,
abandoned, or executed differently from what they say.

For current behaviour read, in this order:

1. `README.md` and `docs/` — architecture, features, extending, host requirements
2. `.ai/rules/` — traps and invariants, indexed in `index.md`
3. the code

If a plan and a `docs/` file disagree, the `docs/` file wins. If a plan and the
code disagree, the code wins.

## Layout

| Directory | Meaning |
|---|---|
| this directory (loose files) | **Live.** Not executed, or partially executed and still worth finishing |
| `archive/` | Executed. Kept for the reasoning and incident history only |
| `abandoned/` | Not executed, and no longer worth executing. The diagnosis in them may still be good; the plan of action is not |

Re-sorted 2026-09-04, validating every file against the tree rather than
against its own Status line. 37 files moved to `archive/`, 1 to `abandoned/`,
4 left live. Re-checked 2026-09-07: `config-consolidation.md` was in fact
executed — `config/` holds one `numerosis.php` and no `config/numerosis/`
directory exists — so it moved to `archive/`, and
`simplification-followups.md` joined it once its eight phases landed.

Re-audited 2026-09-12 the same way (tree, not Status lines): the table below
had gone stale — `domain-events-expansion.md` and `invitations-social-redesign.md`
were both marked "Not executed" but `src/Actions/Tenancy/EnsureTenantUserExists.php`
and `src/Events/Tenancy/MemberJoined.php` both exist, and the redesign's own
HANDOFF file said complete. All three moved to `archive/`, along with
`comment-destyle.md` (its own Status line said done), `drifting-puzzling-flame.md`
(said executed, commit hashes verified in `git log`), the superseded draft it
replaced (`delightful-doodling-turing.md`, added in the same commit, never
executed on its own), and `effervescent-questing-pumpkin.md` (its target
commit `888e31f` is in history under the same subject line). Only two plans
were left live because their own text — not just a Status header — said
something remained open.

`contract-seam-audit.md` moved to `archive/` 2026-09-12: all eleven phases
executed, then audited against the code, which turned up one live defect the
execution had introduced (phase 9's billable narrowing) and per-phase Done
notes missing from six sections. Both fixed in the same pass; the suite ran
710 passed / 6 skipped and `composer analyse` cold-clean.

`pr-review-remediation.md` moved to `archive/` 2026-09-14: every phase
executed, including the comment-budget sweep, which took the repo from 56
over-budget docblocks and 35 over-cap `//` runs to zero of each. Suite 734
passed / 6 skipped, `composer analyse` cold-clean, PHPStan baseline unchanged
at 17.

`post-extraction-review.md` moved to `archive/` 2026-09-14: its last two open
items, 4.2 and 4.4, were re-validated as shipped, and 6.2 — the second-consumer
smoke test, never built — moved to `.claude/findings.md` rather than keeping a
563-line plan live for one line of work.

`enum-vocabulary-sweep.md` moved to `archive/` 2026-09-12: all eight phases
executed on `refactor/enum-vocabulary-sweep`, `composer test` green (721
passed / 6 skipped) and `composer analyse` clean at every phase boundary.

## Live

| Plan | State |
|---|---|
| `cache-audit.md` | **Not executed.** Cache audit remediation, written 2026-09-14. Ten phases. Phase 8 must land after `findings-cleanup.md` phase 1; phase 9 carries one open decision (negative caching on the auth path) |
| `findings-cleanup.md` | **Not executed.** Clears the four open lines in `.claude/findings.md`, written 2026-09-14. Five phases, no open decisions |
| `luminous-wandering-brook.md` | **Not executed.** SQLite compatibility, written 2026-09-12. Six phases. Carries one open decision — what SQLite support would promise — that changes the size of phases 3 and 4 by a large factor and is deliberately unsettled |

## Abandoned

| Plan | Why |
|---|---|
| `parallel-test-isolation.md` | `--parallel` deadlocked across two sessions and was dropped from the suite entirely. The root-cause diagnosis is preserved in `.ai/rules/testing.md`; the plan's direction (keep `--parallel`, delete most of the parallel bootstrap) is dead |

## Notes on the archive

Nothing in `archive/` is actionable. Two things there are worth knowing about:

- **`plan-audit-unexecuted.md`** is a snapshot of 2026-08-10 and was wrong by
  2026-08-11 (it calls `checkout-region-localization.md` unexecuted; it had
  been executed). It is archived as a record of the audit method, not as a
  status list.
- **`package-extraction-log.md`** is an append-only session log, not a plan.
  It is the only record of the bug classes that extraction turned up.
- **`delightful-doodling-turing.md`** is a superseded draft, not an executed
  plan — it was never run on its own. It and `drifting-puzzling-flame.md`
  ("cleaned up") were added in the same commit; the latter is the one whose
  phases actually landed.

Several archived plans describe subsystems that have since been **deleted**
(the Filament panels, the module marketplace, `torann/geoip`, the six-package
split, the chat module). They were executed as written and then removed by a
later plan — `humming-nibbling-flame.md` did most of the removing. Executed
does not mean still present.

Filenames generated from a random word list (`agile-bubbling-lake.md`,
`federated-wandering-wozniak.md`, …) say nothing about their contents — open
the file's first lines for its subject and Status.
