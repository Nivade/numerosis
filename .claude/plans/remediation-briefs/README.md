# Remediation briefs — read this before any brief

**Status: live.** One brief per phase of
`.claude/plans/saas-readiness-review-remediation.md`, rewritten so a phase can
be executed without re-deriving the plan's context. The plan stays the record of
*why*; a brief is the record of *what to do*. Where the two disagree, the brief
wins — it was verified against HEAD, the plan was written before
`refactor/saas-readiness-simplify` landed.

Phase 1 is done and committed (`e411bed`); there is no brief for it.

## Starting a phase

**One phase per session.** Do not chain two: the second starts with a context
full of the first and the anchors stop getting read.

Pick the phase from the progress table below — the first unticked row whose
prerequisite is met. If the user named a phase, use that one instead.

Then follow this, exactly:

1. Read this whole file, then the brief for your phase, then
   `.ai/rules/overview.md` and every rule file the brief names. Before touching
   anything.
2. The brief is the scope and the spec. Its anchors are verified file:line. **If
   an anchor does not match what you find, stop and report** — do not hunt for a
   substitute. **If you meet a decision the brief does not answer, stop and
   ask.**
3. Anything you notice outside the brief goes in `.claude/findings.md` through
   the `note-finding` skill. Do not fix it.
4. `docker start numerosis-mysql-1` before the first test run. Never start a run
   while another is alive.
5. Prove every behavioural fix: write the test, revert the fix, watch it fail,
   restore the fix.
6. Green suite, then `composer analyse`, then `composer lint`, then read
   `git status` — anything Rector rewrote that your phase did not touch goes in
   its own `refactor: apply rector` commit.
7. Commit with the message at the bottom of the brief. One commit for the phase.
8. Tick your row in the progress table below, in that same commit.
9. Report what changed, what you tested, and anything you stopped on.

Work on `fix/saas-readiness-remediation`. Do not create another branch.

## Progress

Tick a row only when its phase is committed.

| Done | Phase | Prerequisite |
|---|---|---|
| [x] | 1 — Security correctness (`e411bed`) | — |
| [x] | 2 — Contracts and the seat counter | — |
| [x] | 3 — Custom domain verification | — |
| [ ] | 4 — Privacy, audit and backup | — |
| [ ] | 5 — Notifications | — |
| [ ] | 6 — Entitlements and billing | phase 2 |
| [ ] | 7 — Feature gating and the API | — |
| [ ] | 8 — Lifecycle and the staff panel | — |
| [ ] | 9 — Hardening configuration | — |
| [ ] | 10 — Duplication and dead weight | 2–9 all done |
| [ ] | 11 — The comment sweep | phase 10 |
| [ ] | 12 — Closing out | everything |

## The contract every brief runs under

1. **Anchors are verified, not approximate.** Each brief names file:line as of
   the commit in its header. If what you find there is not what the brief
   describes, **stop and report**. Do not search the tree for a plausible
   substitute — a moved anchor means the brief is stale and somebody has to
   decide, not guess.
2. **No decisions.** Every judgement call in the plan is already answered in the
   brief. If you meet one that is not, stop and ask. Picking the reasonable
   option silently is the failure mode these briefs exist to prevent.
3. **Scope is the brief.** Fix nothing you notice outside it. Use the
   `note-finding` skill to put it in `.claude/findings.md` instead.
4. **One commit per phase**, with the message the brief gives.

## Before you touch a file

- Read `.ai/rules/overview.md`, then the rule files the brief names. They carry
  traps that are not visible in the code.
- `.ai/rules/general.md` governs comments everywhere: default to zero, 5
  docblock prose lines and 3 `//` lines as hard caps with no exemption for
  public seams, one fact per comment. No em dashes, no `rather than` / `, not`
  contrasts, no colon reveals. Never cite `.ai/rules`, `.claude` or `docs/` from
  source — `tests/` is the only exemption.

## Toolchain

No Sail, no `.env`, no `app/`. This is a package tested through Testbench.

| Task | Command |
|---|---|
| Full suite | `composer test` (Pest, parallel) |
| One file or filter | `vendor/bin/pest <path>` or `--filter=name` |
| Static analysis | `composer analyse` (PHPStan level 9) |
| Format, per edit | `vendor/bin/pint --dirty --format agent` |
| Before the commit | `composer lint` (Rector, then Pint, then PHPStan) |

- **The test database is a container.** `docker start numerosis-mysql-1` first,
  or every test fails on connection refused.
- **Never start a test run while another is alive.** One shared database.
- `composer lint` rewrites the whole tree, Rector included. Read `git status`
  afterwards; anything it rewrote that your phase did not touch goes in a
  separate `refactor: apply rector` commit.

## Proving a fix

A test that passes against the unfixed code proves nothing. For every
behavioural change: write the test, revert the fix, watch it fail, restore the
fix. Phases 10 and 11 are the exception and the opposite — they change no
behaviour, so **no test expectation may move**. If one does, the refactor
changed behaviour and is wrong.

## The briefs

| Brief | Closes | Notes |
|---|---|---|
| `phase-02-contracts-and-seats.md` | S34, P3, S8, S9, S14 | The memoization trap already reverted one attempt at this |
| `phase-03-domain-verification.md` | P26, P20 | P25 is closed; do not restore the columns |
| `phase-04-privacy-audit-backup.md` | P10, P11, P9, P12 | S23/S24 already closed |
| `phase-05-notifications.md` | S40 (second half) | Almost entirely already closed — verify, then one change |
| `phase-06-entitlements-and-billing.md` | P18, P19, P24, S36, S37 | Run after phase 2. Cache work is a leak risk |
| `phase-07-feature-gating-and-api.md` | P21, P22, S39, S38, S41, S35 | Highest risk: a half-registered feature fatals at boot |
| `phase-08-lifecycle-and-staff-panel.md` | P1, P2, P7, P4 | |
| `phase-09-hardening-config.md` | P13, P14, S28, S29, S30 | D4 inverts what the plan's 9.1 assumed |
| `phase-10-duplication-and-dead-weight.md` | S7, S10, S11, S22, S12, S13, S25, S26 | Last but one. No test may move |
| `phase-11-comment-sweep.md` | S1–S4, S15–S20, S27, S32, S33 | Last. No test may move |
| `phase-12-closing-out.md` | — | Docs and recorded rules |

Phases 2, 6 and 7 carry the judgement that went wrong before and are worth the
more capable model. The rest are bounded enough that the brief is the whole job.

## Order

Phases 2 through 9 are independent of each other and may run in any order.
Phase 10 and phase 11 touch files every other phase edits, so they run last, 10
before 11. Phase 12 closes out and runs after everything.
