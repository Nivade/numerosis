# Runtime entitlements and quotas

**Status: not executed. Written 2026-09-16.** Wave 4 of
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
