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

## Live

| Plan | State |
|---|---|
| `domain-events-expansion.md` | **Not executed.** Approved 2026-09-04. Runs **before** `invitations-social-redesign.md`. Confirmed unstarted: no `src/Actions/Tenancy/EnsureTenantUserExists.php` |
| `invitations-social-redesign.md` | **Not executed.** Approved 2026-09-04. Depends on `domain-events-expansion.md` landing first |
| `post-extraction-review.md` | **Mostly done; three items survive.** Its Live status block is stale — read the correction at the top of the file, not the table |

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

Several archived plans describe subsystems that have since been **deleted**
(the Filament panels, the module marketplace, `torann/geoip`, the six-package
split, the chat module). They were executed as written and then removed by a
later plan — `humming-nibbling-flame.md` did most of the removing. Executed
does not mean still present.

Filenames generated from a random word list (`agile-bubbling-lake.md`,
`federated-wandering-wozniak.md`, …) say nothing about their contents — open
the file's first lines for its subject and Status.
