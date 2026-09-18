# Runtime entitlements and quotas

**Status: executed 2026-09-17.** See "What shipped" at the bottom. Wave 4 of
`saas-readiness-roadmap.md`. Owns the tenant-scoped counter service that
`usage-metering.md` and `seat-limit-at-invite.md` read.

## The gap

Plans already describe what they include.
`src/Models/Central/PaymentPlan.php:77` exposes `availableFeatures()` over the
`PaymentPlanFeature` pivot, which carries an `available` boolean
(`PaymentPlanFeature.php:32`), and `PlanFeature` is the sellable-capability
model.

Nothing reads any of it at runtime. The only enforcement anywhere is
`SeatLimitPlanPolicy`, checked at checkout and plan swap, reading
`options.max_users` out of plan metadata
(`SeatLimitPlanPolicy.php:28`).

So the plan a customer bought describes what they get and does not control
what they get. Every differentiated tier is currently sold on trust.

## What to build

Two questions, one service:

| Question | Shape |
|---|---|
| Is this capability included in the tenant's plan | Boolean, from `PaymentPlanFeature.available` |
| Has this tenant used up its allowance of it | Counter against a limit from plan metadata |

```php
Entitlements::allows('custom_domains');
Entitlements::remaining('seats');
Entitlements::consume('api_calls', 1);
```

Naming note for whoever greps: `Feature` in this package already means a
capability toggle in `config('numerosis.features')`. A plan's selling point is
`PlanFeature`, whose table is `features`. Do not add a third meaning — the
service is `Entitlements`, not `Features`.

## The counter is the shared foundation

Quota counting and billing meters are the same counter read two ways.
Built once here:

`Contracts\Billing\UsageCounter` — increment, read, read for a period, reset.
Backed by the central database with a tenant-scoped key and an in-request
memo. **Not** cache-backed as the source of truth: `Cache::lock` and cache
keys are tenant-prefixed inside tenancy, and a counter that loses writes on a
cache flush is a counter that under-bills.

`usage-metering.md` adds the Stripe meter reader over the same rows. Building
metering first would build this twice and they would drift; the billing one is
the one customers audit.

## Phases

### 1. `UsageCounter` contract and database implementation

`tenant_usage` central table: tenant, key, period start, value. Atomic
increment through a database expression, never read-modify-write.

### 2. `Entitlements` service

Resolves the tenant's active plan through the existing subscription
relationship, reads `availableFeatures()` and plan metadata, memoizes per
request. Caching across requests is tempting and dangerous — cached plan
models leak across tenants. Cache the *derived scalars* under a tenant-scoped
key with the package's `CacheTtl`, never the model.

A tenant with no active subscription gets the configured free-tier
entitlements, not an exception. That path runs during trials and during
dunning and must not throw.

### 3. Enforcement seams

- Middleware: `entitlement:custom_domains` on a route.
- Blade: an `@entitled` directive for hiding what is not sold.
- Action-level: an explicit check in the action, which is the only one that
  actually enforces. The first two are UX.

Refusal throws a domain exception implementing `ShowsMessageToUser`, carrying
the plan that would allow it, so the upgrade prompt is not hand-written at
each call site.

### 4. Retro-fit the existing checks

`SeatLimitPlanPolicy` becomes a reader of `Entitlements::remaining('seats')`
and keeps its contract. `DefaultUnpaidTenantQuota` (the cap on concurrent
unpaid tenants per user) is the same shape and moves behind the same counter.

### 5. Downgrade behaviour

**Decision needed, deferred here rather than guessed:** swapping to a plan
whose limits the tenant already exceeds — 8 seats used, 5-seat plan. Three
options: refuse the swap, allow it and block further additions, or allow it
and force the tenant to shed. Recommend **allow and block further additions**,
with a banner naming what is over. Refusing traps a customer trying to spend
less, which is the worst possible moment to be rigid.

### 6. Surface

Usage against limits on the team screen, and on the staff panel's tenant
detail.

## Tests

- Included and excluded capabilities resolve correctly for a tenant on each
  seeded plan.
- No active subscription resolves to the free tier and never throws — tested
  during trial, during `past_due`, and after cancellation.
- Counter increments atomically under concurrency (two processes, one key, no
  lost update).
- Entitlement results do not leak across tenants — the cached-scalars test,
  run with two tenants in one request cycle.
- Middleware, directive and action check agree on the same input.
- Downgrade below current usage allows the swap and blocks the next addition.

## Risks

- **Plan metadata is untyped.** `options.max_users` is a nested array read
  with `??`. A typo in a seeded plan silently means "uncapped". Validate plan
  metadata at boot, the way `ConfiguredSteps` validates the step lists.
- **Cached tenant models leak.** The rule is already written down in this repo
  because it has happened. Cache scalars, keyed per tenant, or do not cache.

## What shipped

Phases 1-4 and 6 in full, phase 5 decided as recommended, 2026-09-17.

- `Contracts\Billing\UsageCounter` over a central `tenant_usage` table.
  `increment()` is `increment()` on the row, and the insert races on the unique
  index and retries — an upsert with `values(value)` was written first and
  dropped, since that expression is MySQL's alone.
- `Contracts\Billing\Entitlements` with `PlanEntitlements` behind it, bound as
  a singleton so the per-request memo exists at all. Memo holds scalars keyed
  by tenant id, never a plan model.
- Limits read `options.limits.<capability>` and keep `options.max_users` as
  seats. Absent or non-numeric is uncapped, and `verifyPlanMetadata()` in the
  install doctor is what turns a typo into a message rather than silence.
- Three seams: `entitlement:<capability>` middleware, `@entitled` Blade
  directive, and `assertAllowed()`/`consume()` in an action.
  `EntitlementSeamsTest` asserts all three agree on one input.
- **Seats are counted from rows, not from the counter.** A counter would drift
  the moment a member was removed anywhere but the one action that decrements.
- Phase 5 decided as the plan recommended: a downgrade is allowed and the next
  addition is blocked. `remaining()` never goes negative and the team screen
  says what is over.
- Usage against limits on the staff tenant detail.

Two deviations:

- **`numerosis.billing.free_tier.limits` ships empty**, not `seats => 1`. A
  seat limit on the no-subscription path immediately broke invitations for
  every tenant without one, which is the state a tenant is in before its first
  checkout. The key is documented as the place to put one.
- **`DefaultUnpaidTenantQuota` was left alone.** The plan calls it "the same
  shape", but it counts unpaid *tenants per user* and the counter is keyed on a
  tenant, with a foreign key to prove it. Moving it would need a second scope
  on the table for the sake of symmetry.

Three things the readiness remediation changed, 2026-09-18:

- **The scalars are cached, not only memoized** (P18, phase 6, `4990d9e`).
  Phase 2 asked for a tenant-scoped cached read through `CacheTtl` and what
  shipped recomputed on every request. Scalars only, never the models they came
  from, invalidated on subscription change: a cached cap outliving a downgrade
  is the same defect at a longer timescale.
- **Plan metadata validates at boot** (P19, phase 6, `4990d9e`). The same phase
  asked for validation "the way `ConfiguredSteps` validates the step lists", and
  `verifyPlanMetadata()` landed in the install doctor instead, so a typo stayed
  silent until somebody ran the command. It runs at boot, with the doctor as a
  second caller of the same check.
- **The seat cap has one definition** (P3/S8, phase 2, `d5a5151`).
  `GetTenantSeatUsage` re-derived it from `options.max_users` through three
  optionals and answered differently when the two disagreed. It counts members
  and asks `Entitlements` for the cap. The per-request memo gained an explicit
  invalidation, since a downgrade has to be visible to an accept later in the
  same request; the capability constants moved onto the `Entitlements`
  interface; and seat availability split out as `Contracts\Billing\SeatPolicy`.
