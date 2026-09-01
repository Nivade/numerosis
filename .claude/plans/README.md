# Plans — historical, not authoritative

**Nothing in this directory describes how the package works today.** These are
session plans, kept for the reasoning and the incident history in them. A plan
records what someone intended at a point in time; several were superseded,
abandoned, or executed differently from what they say.

For current behaviour read, in this order:

1. `README.md` and `docs/` — architecture, features, extending, host requirements
2. `.claude/rules/` — traps and invariants, indexed in `INDEX.md`
3. the code

If a plan and a `docs/` file disagree, the `docs/` file wins. If a plan and the
code disagree, the code wins.

## Still open

Per `plan-audit-unexecuted.md` (audited 2026-08-10, spot-checked 2026-09-01):

| Plan | State |
|---|---|
| `confusion-cleanup.md` | **Partial, uncommitted, and the newest work here (2026-09-01).** Steps 1–6 verified green; step 7 (the `ModelResolver` extraction) was never test-run, and the config split was not started. Read it before touching `Support\Numerosis`, `config/numerosis.php`, or the package layout |
| `post-extraction-review.md` | **Partial.** Phases 1 and 3 done. Phases 2, 4, 5, 6 have no DONE marker; Phase 5 (the host becomes a real test consumer) is flagged in the file as where the remaining risk lives |
| `vendor-duplication-cleanup.md` | **Partial.** Items #4 (drop legacy `subscriptions`/`payments` migration history), #6 (`Money` cast float round-trip → integer minor units) and #7 (impersonation: enable or drop its migration) read as still open |
| `design-system-unification.md` | **Partial.** Phases 0–8 done for browser-independent work; Phase 7's keyboard-nav and mobile-width items need a real browser pass |
| `test-suite-speedup.md` | **Partial.** Template-clone done and measured (~0.19s/tenant vs ~1.9s). The "shared tenant DB per test process" step never started |

## Dead — do not execute

| Plan | Why |
|---|---|
| `parallel-test-isolation.md` | Abandoned. `--parallel` deadlocked across two sessions; the approach is dead, not merely undone. See `.claude/rules/testing.md` |
| `admin-panel-provider-polish.md` | Targets `App\Providers\Filament\AdminPanelProvider` in the host — that file no longer exists in either repo. Panel providers live in `packages/filament/src/Providers/` and are registered by that package |

## Audit staleness worth knowing

`plan-audit-unexecuted.md` calls `checkout-region-localization.md`
**"❌ Not executed — `torann/geoip` never installed, no `ResolveCheckoutRegion`
action."** Both now exist (`torann/geoip` is a `require`;
`src/Actions/Billing/Checkout/ResolveCheckoutRegion.php` is in place), so that
plan was executed after the audit was written. Treat the audit as a snapshot of
2026-08-10, not a live list — the table above is the corrected version.

`package-extraction-log.md` is an append-only session log, not an actionable
plan.

## Everything else

Fully executed as of the audit. Filenames generated from a random word list
(`agile-bubbling-lake.md`, `federated-wandering-wozniak.md`, …) say nothing
about their contents — open the file's first lines for its subject and Status.
