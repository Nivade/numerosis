# Audit: plans not executed / partially executed

Audience: whoever picks next work. Scanned all 32 files in `.claude/plans/`
2026-08-10, read each file's self-declared Status line (or lack of one) plus
top/tail for corroboration. 26 of 32 are fully executed — not listed here.
List below is everything else, most-actionable first.

**Update 2026-08-10 (a):** `package-extraction.md` moved to done — its only
open item was Phase 10's archive-saas-m step, and the user decided against
archiving. Plan marked ✅ Done, archive step struck out in the file itself.

**Update 2026-08-10 (b):** `opt-in-feature-classes.md` moved to done — its
Status line was stale, written back when only `TurnstileFeature` existed.
Codebase now has 13 classes implementing `NamedFeature` across
`src/Features/*`, config-array toggle wired exactly as the plan specced.
Corrected in the file itself.

## Not executed

- **`admin-panel-provider-polish.md`** — thin-app's `AdminPanelProvider.php`.
  No Status marker in file at all. Confirmed live: grepped thin-app's actual
  `AdminPanelProvider.php` for `spa()`, `databaseNotifications`, `brandName`,
  `navigationGroups` — zero matches. None of Phase 1-4 landed.

- **`checkout-region-localization.md`** — self-declared **❌ Not executed**.
  `torann/geoip` never installed, no `ResolveCheckoutRegion` action, no
  `config/billing/payment_methods.php`. Never left the ground.

- **`parallel-test-isolation.md`** — self-declared **❌ Not executed /
  superseded**. `--parallel` tried across two sessions, abandoned
  (deadlocks — see `.claude/rules/testing.md` "Why parallel was dropped").
  Direction changed; this plan's approach is dead, not just undone.

## Partially executed

- **`post-extraction-review.md`** — Phase 1 and Phase 3 marked **DONE** with
  commit hashes; Phases 2 (delete dead weight), 4 (install-day verification:
  `numerosis:install --check`), 5 (thin-app becomes a real test consumer —
  currently has no Pest, no CI), and 6 (release readiness/archive) have no
  DONE marker and show no corroborating commits. Phase 5 is flagged in the
  file itself as "where the extraction's remaining risk actually lives."

- **`vendor-duplication-cleanup.md`** — self-declared Executed, but with its
  own caveat: items #1, #2, #3, #5 verified done; **#4 (drop legacy
  `subscriptions`/`payments` migration history, part of the squash),
  #6 (collapse `Money` cast's float round-trip to integer minor units),
  and #7 (decide impersonation: enable the feature or drop its migration)
  were never re-verified and read as still-open** from the file's own text.
  #8 ("keep, but align") is advisory, not a to-do.

- **`design-system-unification.md`** — self-declared **Phases 0-8 done,
  browser-independent parts only**. Phase 7's keyboard-nav and mobile-width
  items explicitly need a real browser pass and are marked deferred, not
  done.

- **`test-suite-speedup.md`** — self-declared **⚠️ Partially executed**.
  Phase 1 (template clone) done and measured (~0.19s/tenant vs ~1.9s).
  Parallel-test track abandoned entirely (see `parallel-test-isolation.md`
  above — same abandonment, two files record it). "Possible next step"
  section (shared tenant DB per test process) never started.

## Not a plan to execute (excluded from above)

`package-extraction-log.md` is an append-only archival log of
`package-extraction.md`'s session history, not itself an actionable plan —
skipped.
