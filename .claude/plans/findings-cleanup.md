# Findings cleanup

**Status: not executed.** Written 2026-09-14. Clears the four open lines in
`.claude/findings.md`. Five phases; 1–2 are code, 3 is an investigation with a
time-box, 4 is a one-shot documentation audit.

Each phase deletes its own line from `.claude/findings.md` as it lands.

## Phase 1 — popular-plan cache is never invalidated

`EloquentPaymentPlanRepository::mostPopularSlug()`
(`src/Services/Billing/EloquentPaymentPlanRepository.php:89`) caches under
`CacheKeys::popularPaymentPlanSlug()` with a 5-minute TTL and nothing forgets
that key — grep for it finds the write and no `forget`. The sibling
`available()` key *is* invalidated, by `PaymentPlanObserver` /
`PaymentPlanFeatureObserver`, so the asymmetry is an oversight, not a design.
The value is an aggregate over central `subscriptions` rows, so it goes stale
on subscription churn, not on plan edits.

- Add `src/Observers/Billing/SubscriptionObserver.php`, `use ForgetsCacheKey`,
  forgetting `CacheKeys::popularPaymentPlanSlug()` on `saved` and `deleted` —
  same shape as `PaymentPlanObserver`. `saved` rather than `created`, because
  a row moving to a different `payment_plan_id` changes the aggregate too.
- Attach with `#[ObservedBy(SubscriptionObserver::class)]` on
  `src/Models/Central/Subscription.php`, matching the seven models that
  already register their observer by attribute (no service-provider map here).
- Also forget the popular key from `PaymentPlanObserver::deleted()`: the
  cached value is a slug, and a deleted or renamed plan leaves a slug that
  resolves to nothing.
- Point `mostPopularSlug()`'s docblock at the observer the way `available()`'s
  does, and demote its TTL sentence to "backstop", same wording.

**Tests:** extend `tests/Feature/Models/Central/PaymentPlanPopularCacheTest.php`
with a case that reads the slug, creates a subscription that makes a second
plan win, and asserts the next read reflects it without touching the clock.
Make the new assertion fail once before wiring the observer (`.ai/rules/testing.md`).

## Phase 2 — `Events\Billing\PaymentSettled` is dispatched from nowhere

Confirmed: the only non-test references are the listener map entry
(`src/NumerosisServiceProvider.php:481`) and the listener itself. `RestoreTenant`
— which the event's own docblock names as its source — fires
`Events\Tenancy\TenantRestored` instead, and that has its own listener and
notification. So `SendPaymentConfirmedNotification` and
`Notifications\Billing\PaymentConfirmed` never fire in production.

Take the dispatch, not the delete: `PaymentConfirmed`'s text ("an async
payment finally settling") describes a state the webhook already detects and
that `TenantRestored` does not cover — a *never-suspended* tenant whose
SEPA/iDEAL first payment settles late.

- In `WebhookController::handleInvoicePaymentSucceeded()`
  (`src/Http/Controllers/Billing/WebhookController.php:370`) the
  `TenantProvision` update already filters `whereNull('settled_at')`, so its
  return value is the "this delivery is the one that settled it" signal. Keep
  the affected-row count; when it is `> 0`, resolve the tenant with
  `FindTenantByStripeCustomer` off the invoice customer and dispatch
  `PaymentSettled($tenant, $owner->id, (string) $tenant->getTenantKey())`,
  skipping when `$tenant->owner()` is null — same null guard `RestoreTenant`
  uses.
- Idempotency comes free: a redelivered invoice updates zero rows.
- Rewrite the event docblock; it currently names `RestoreTenant`.

**Tests:** a webhook feature test posting `invoice.payment_succeeded` with a
pending `TenantProvision` and asserting `PaymentSettled` dispatched once, then
asserting a second identical delivery dispatches nothing. Keep the two
existing listener tests as they are — they call `handle()` directly.

## Phase 3 — `PlanCardTest` parallel flake

Failed once under `composer test`, passed on rerun and in isolation; the
failure text was not captured, which is the first thing to fix. Time-box: if
30 full parallel runs do not reproduce it, stop and record what was ruled out
rather than keep hunting.

- Reproduce: `docker start numerosis-mysql-1`, then loop `composer test` and
  keep every failing run's output. Never two runs at once.
- Ranked suspects, all cheap to falsify while looping:
  1. Central-connection rows. `deleteCentralWrites()` runs at teardown, not
     inside `RefreshDatabase`'s transaction, so another test's `PaymentPlan`
     rows can be visible mid-test; the test's own assertions are
     `assertSee($price)` / `assertDontSee('Free')` against one rendered card,
     so a stray plan alone is not enough — check whether anything renders a
     list.
  2. The "Most Popular" badge. `plan-card.blade.php:17` resolves
     `mostPopularSlug()` at render, which reads the global cache and the
     central `subscriptions` table. Phase 1 changes the invalidation here, so
     run phase 3's loop *after* phase 1 lands and note whether it changed.
  3. Factory collisions — the suite has already been bitten by
     `fake()->unique()` not being unique across workers (`.ai/rules/testing.md`).
- Outcome is one of: a fix plus a regression test, or a `record-rule` note in
  `.ai/rules/testing.md` naming what was excluded, plus a rewritten findings
  line. Do not delete the findings line for an unreproduced flake.

## Phase 4 — second-consumer smoke test

Last open item of the archived `post-extraction-review.md` (its 6.2). The
documented install path — `laravel new`, path repo, `numerosis:install`, by
`docs/host-requirements.md` alone — has never been executed by anything;
`numerosis-thin-app` was hand-configured over many sessions and cannot prove
it.

Do it as a throwaway local host, not a CI job: `laravel new` a scratch app
outside `~/repos`, never `git init` it, walk `docs/host-requirements.md`, and
delete the directory afterwards. What the finding is actually worth is the
*first* run — every host-seam bug so far was found by adding a consumer, and
the audit finds them whether or not a runner ever repeats it. A workflow costs
a services block, a checkout token question and ongoing maintenance before it
finds its first bug. Build one later if the manual run proves the path is
worth guarding.

- Scratch host at `/tmp/numerosis-smoke/host` (tmpfs, gone on reboot, cannot
  be mistaken for a checkout). `composer create-project laravel/laravel host`
  if the `laravel` installer is not on PATH.
- Two path repositories, both to the absolute checkout path, symlink on —
  `/home/nvade/repos/private/numerosis` and `.../numerosis/packages/*`. One is
  not enough: `packages/ui` is a separate split and
  `numerosis-thin-app/composer.json:11-26` declares both. Then
  `composer require nvade/numerosis:@dev`.
- MySQL: reuse the running `numerosis-mysql-1` container, on its own database
  name. The host needs `CREATE DATABASE` for tenant provisioning, so connect
  as root. Do not point it at `testing` or any `*_test_*` worker database.
- Then **only** what `docs/host-requirements.md` says (321 lines, sections 0–2)
  — no memory of how `numerosis-thin-app` was wired, no reading `src/`. Run
  `php artisan numerosis:install`, then `numerosis:install --verify-only`,
  which must exit 0. The flag is `--verify-only`; the archived plan's
  `--check` never existed.
- Then a real boot, because `--verify-only` did not catch either of the two
  `HostConfig` bugs found by hand in 2026-08: `php artisan serve`, request
  `/login` and check it is the right route rather than any 200, then provision
  a tenant and request it on its own subdomain (`/etc/hosts` or a `Host:`
  header).
- **The output is a docs diff, not a green check.** Every step needed that the
  doc does not state is a docs bug — record it as it is hit, with the symptom
  it produced, and fix `docs/host-requirements.md` in the same pass. Reaching
  a booting app by improvising is the failure mode, not the goal.
- Finish by deleting `/tmp/numerosis-smoke` and dropping the scratch database.

Findings line is cleared by the run plus the docs fixes it produces. If the
run turns up more than a couple of gaps, that is the argument for the CI
workflow, and it gets its own plan.

## Phase 5 — bookkeeping

- Delete each `.claude/findings.md` line as its phase lands (phase 3's line is
  rewritten instead if unreproduced).
- Add this plan to `.claude/plans/README.md`'s Live table when it is written,
  and `git mv` it to `archive/` in the same pass that closes the last phase.
- `vendor/bin/pint --dirty` before finishing any PHP phase; `composer analyse`
  cold at the end of phases 1 and 2.
