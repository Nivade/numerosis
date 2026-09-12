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

## Live

| Plan | State |
|---|---|
| `glittery-growing-dewdrop.md` | **Phases 1–7 executed, 8–9 outstanding.** On branch `refactor/provisioning-pipeline` (`5219a09`..`1ff30a5`), unmerged. Phase 8 (docs + rules) not started. Phase 9 (a production opt-in for `Tenant::create()`) was added 2026-09-12 and is specification only. Every "Open flags" item was cleared the same day, three of them wrong about their own facts; the corrections are recorded in place |
| `luminous-wandering-brook.md` | **Not executed.** SQLite compatibility, written 2026-09-12. Six phases. Carries one open decision — what SQLite support would promise — that changes the size of phases 3 and 4 by a large factor and is deliberately unsettled |
| `enum-vocabulary-sweep.md` | **Not executed.** Written 2026-09-12. Eight phases, each independently landable; phases 1 and 2 carry most of the value |
| `contract-seam-audit.md` | **Not executed; phase 0 added and phases 1, 2, 3, 5, 9.8 revised 2026-09-12 after review.** Eleven phases (0–10). Three are live defects: phase 0 is a cross-tenant tenancy leak from a queue worker (`$tenant->run()` has no `try`/`finally` in stancl) and should land first regardless; plus a dead `numerosis.tenancy.seeder` key and a `DB::transaction()` on the wrong connection. Phase 2's original "add Stripe contracts" design was wrong — Cashier is already the abstraction — and the file records why, so it is not reproposed |
| `pr-review-remediation.md` | **Partially executed.** Re-audited 2026-09-07 against `3c3de4e`: most phases fixed, but phase 4 (webhook payload guard) is still open and phase 5.1 (line-number citation sweep) has 7 of 9 left |
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
