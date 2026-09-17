# Phase 6 — Entitlements, metering and billing reads

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P18, P19, P24, S36, S37.

Rules to read **before any edit**: `.ai/rules/tenant-caching.md` (6.1 is a
cross-tenant leak risk), `.ai/rules/billing-checkout.md`,
`.ai/rules/architecture-conventions.md`, `.ai/rules/package-host-bootstrap.md`
(6.2 is boot ordering).

**Run phase 6 after phase 2, or expect a conflict.** Both touch
`PlanEntitlements`, and 2.2 adds the memo invalidation that 6.1 has to cache
around.

## 6.1 — Cache the derived scalars (P18)

`src/Services/Billing/PlanEntitlements.php` memoizes in request-scoped
properties only: `$resolved` (`:174`), `$meters` (`:143`), `$buckets` (`:162`).
No `Cache` facade, no `CacheTtl`. `runtime-entitlements.md` phase 2 asked for a
cached read "under a tenant-scoped key with the package's `CacheTtl`".

Add the cached read through `CacheTtl`, reading `numerosis.cache.ttl` and
`numerosis.cache.store` the way the existing `CacheTtl` callers do.

**The three rules from `.ai/rules/tenant-caching.md` that decide whether this is
safe.** Read them there in full; they are not optional.

1. Cache the derived **scalars**. Never cache a model or anything holding one —
   there are live examples in that rule file of what went wrong.
2. Invalidate on subscription change. A cached cap outliving a downgrade is the
   same defect 2.2 fixes inside one request, at a longer timescale.
3. `Cache::lock` and the cache store are tenant-prefixed inside tenancy and
   un-prefixed outside it. A key built outside tenancy is a cross-tenant leak.

Reuse the invalidation hook phase 2 added rather than inventing a second one.

## 6.2 — Validate plan metadata at boot (P19)

`verifyPlanMetadata()` lives at
`src/Console/Commands/InstallNumerosisCommand.php:378`. It checks that
`metadata.options` is an array, that `metadata.options.max_users` is numeric
when present, and that `metadata.options.limits` is an array. A typo therefore
stays silent until somebody runs the doctor.

`runtime-entitlements.md` asked for validation "at boot, the way
`ConfiguredSteps` validates the step lists". Follow `ConfiguredSteps` exactly:
find where the provider calls `assertConfiguredStepsAreWellShaped()` and put the
plan-metadata check alongside it, on the same terms.

Keep the doctor as a second caller of the extracted check. Do not leave two
implementations.

**Boot-time constraints that will bite.** A validator that runs at boot cannot
assume config is merged in the order a test suggests, and must not throw during
`register()`. `.ai/rules/package-host-bootstrap.md` documents the
register-vs-booting race that `HostConfig::apply()` had to be moved for.

## 6.3 — Stop inventing a billing period (P24)

`src/Actions/Queries/GetBillingPeriod.php:68-82` — the `anniversary()` branch
synthesises a period from `$subscription->created_at` (`:71`) when Stripe
stamped none. The spec says report against the period "recorded locally".

Return null. Callers say "no period recorded" rather than reporting usage
against a period that does not exist.

**This one changes a test expectation, deliberately.**
`tests/Feature/Billing/UsageReportingTest.php:179` —
`test_an_unstamped_subscription_falls_back_to_its_own_anniversary` asserts the
behaviour being removed. Rewrite that test to assert the null and the caller's
handling of it, and rename it. It is the only expectation in this phase that may
move; if any other one does, stop.

Check `tests/Feature/Billing/UsageReportingTest.php:160`
(`test_the_period_follows_the_subscription_and_not_the_calendar`) still passes
unchanged.

## 6.4 — `ReconcileUsage` is a command doing an action's job (S36)

`src/Console/Commands/ReconcileUsage.php` holds divergence detection, Stripe
reads and alert throttling inline across `handle()` (`:41`), `check()` (`:70`),
`stripeTotal()` (`:91`), `aggregatedValue()` (`:107`), `reportDivergence()`
(`:115`), `tolerance()` (`:141`) and `tenants()` (`:153`).

`.ai/rules/architecture-conventions.md` puts logic in an action and leaves
transport in the command. Its siblings `ReportUsage` and `VerifyDomains` already
do this — copy their shape rather than inventing one.

Extract the logic into an action under `src/Actions/Billing/`. The command keeps
argument parsing, iteration and output.

## 6.5 — The webhook's price walk (S37)

`src/Http/Controllers/Billing/WebhookController.php:366-399` —
`usageAmountOf()` walks `invoice['lines']['data']` and sums metered line amounts
inside a controller, while `src/Data/Billing/StripeSubscriptionData.php` exists
for exactly that shape (it holds `id`, `status`, `priceId`, `quantity`,
`trialEndsAt`, `items`).

Move the walk into the data object. If the invoice shape does not fit
`StripeSubscriptionData`, add a sibling under `src/Data/Billing/` rather than
widening that one — but say so in the commit.

## Tests

- `tests/Feature/Billing/EntitlementsTest.php:98`
  (`test_entitlements_do_not_leak_between_tenants`) is the assertion 6.1 must not
  break. Add a second one that exercises the **cached** path across two tenants,
  since the existing one may only prove the request memo.
- Add: a subscription change invalidates the cached scalars.
- 6.2: a plan with malformed metadata fails at boot, and the doctor still
  reports the same thing.
- 6.3: rewritten as described above.
- 6.4 and 6.5 change no behaviour.
  `tests/Feature/Billing/UsageReconciliationTest.php` (3 tests) and
  `MeteredSubscriptionTest.php` (3 tests) must stay green unmodified.

## Commit

```
fix(billing): cache the entitlement scalars and stop inventing periods

The derived entitlement scalars were memoized per request and recomputed
on every one, where the plan asked for a tenant-scoped cached read through
CacheTtl. Scalars only, never the models they came from, invalidated on
subscription change -- a cached cap outliving a downgrade is the same
defect at a longer timescale.

Plan metadata was validated in the install doctor, so a typo stayed silent
until somebody ran it. It validates at boot now, the way ConfiguredSteps
does, with the doctor as a second caller of the same check.

GetBillingPeriod synthesised an anniversary period from created_at when
Stripe had stamped none, and reported usage against it. It returns null,
and callers say no period is recorded. The test asserting the old fallback
is rewritten, which is the one expectation this phase moves.

ReconcileUsage kept divergence detection, Stripe reads and alert
throttling in the command; the logic is an action now, like ReportUsage
and VerifyDomains already were. The webhook's walk through the metered
invoice lines moved onto the data object that owns that shape.

Closes P18, P19, P24, S36 and S37.
```
