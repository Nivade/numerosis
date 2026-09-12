# Contract and seam audit — concrete classes where a contract belongs

**Status: phases 0, 1 and 2 executed 2026-09-12 (`7964b73`, `f8717ef`, and
this commit). Phases 3–10 not started.** Phase 0 added; phases 1, 2, 3, 4, 5
and 9.8 revised
2026-09-12 after review — phases 2 and 4 both originally proposed new
abstractions over abstractions that already existed, and are now much
smaller. Re-audited 2026-09-12 after `refactor/provisioning-pipeline` merged
to `main` (`33f7ca8`) — see "Re-audit after merge" below; findings still
hold, three call sites and one contract-tone example need small edits before
executing. Written
2026-09-12 on branch `refactor/provisioning-pipeline`. Nothing below is built.
Eleven phases (0–10), ordered so each is independently landable and
independently revertable. Phase 1 carries the most design value; 8–10 are
small and could be dropped without harming the rest.

**Three findings are live defects rather than design debt, and they are the
reason to open this file at all:** phase 0's `$tenant->run()` tenancy leak
(cross-tenant, from a queue worker — do this first regardless of what else
happens), phase 6's dead `numerosis.tenancy.seeder` key, and phase 7's
`DB::transaction()` on the wrong connection. If everything else here is
deferred indefinitely, land those three.

Where a phase records a *rejected* design, that text is load-bearing — it is
there because the obvious approach was tried on paper and is wrong. Phase 2 in
particular: do not reintroduce Stripe contracts.

## Context

The audit swept `src/Contracts` (32 interfaces) against every consumer, then
swept the reverse direction: vendor concretes, static god-objects, Eloquent
models crossing seams, and the arch tests meant to hold all of it down.

The contract *layer* is in good shape — 28 of 32 interfaces are injected by
consumers and bound through `numerosis.{billing,tenancy,auth}.implementations`,
and no code does `Tenant::query()` directly. The debt is elsewhere: in seams
that exist in signature but not in fact, in one static class carrying 104 call
sites, and in arch tests that pass for the wrong reason.

Counts below were measured 2026-09-12 against `4e39198`.

## Preflight — read before starting any phase

**The refactor has landed.** `refactor/provisioning-pipeline` merged to
`main` at `33f7ca8` (2026-09-12) and is no longer mid-flight — the file list
below is now history, kept for the re-audit trail, not a live overlap
warning. All ten phases are clear to start against current `main`.

One commit per phase.

### Re-audit after merge (2026-09-12, `main` at `33f7ca8`)

Checked every phase's cited files against current `main`. Findings hold;
three drifted details:

- **Phase 0.** `PromoteFirstUserToAdmin`'s unsafe `$tenant->run()` at what is
  now line 44 is unchanged in kind — it now takes `TenantProvision $provision`
  and resolves the tenant itself (`Numerosis::model(Tenant::class)::findOrFail($provision->slug)`,
  a side effect of the same refactor phase 5 targets). `EnsureTenantUserExists.php:32`
  and `Tenant.php:226` are untouched. Proceed as written; only the line number
  in the table moved (44, not 45–56).
- **Phase 3.** Counts drifted with the merge: `Numerosis::model()` is 102 call
  sites now (was 104), 135 total `Numerosis::` calls (was 142), 30 chained
  `::model(X::class)::` sites (was 29), 118 `@var` annotations in `src/` (was
  ~64 read as "of 117"). `WebhookController` rose from 6 to **11** `@var`
  annotations — an unrelated PHPStan-hygiene commit replaced its five
  array-shape `@param` docblocks with `@var` casts inside each handler. It is
  still one of the three highest-count files; swap "6" for "11" in the
  done-condition table before running the cold-cache check.
- **Phase 5.** The six-site table is now seven: `PromoteFirstUserToAdmin`
  gained the same `Numerosis::model(Tenant::class)::findOrFail($provision->slug)`
  line during the merge (its signature changed from `handle(Tenant $tenant)`
  to `handle(TenantProvision $provision)` so it could declare
  `RequiresContributions`). Convert all seven: `CreateTenantDatabase.php:31`,
  `MigrateTenantDatabase.php:24`, `SeedTenantDatabase.php:34`,
  `FinalizeTenantProvisioning.php:30`, `LinkTenantSubscription.php:53`,
  `AddTenantOwner.php:42`, `PromoteFirstUserToAdmin.php:40`.
- **Contract tone example.** `Contracts/Tenancy/ConsumesContributions.php`
  (cited in the rules-per-phase note near the bottom of the preflight) was
  renamed to `Contracts/Tenancy/RequiresContributions.php` in `691cc59`. Match
  that file for tone instead — same interface, new name.
- **Phase 4, 6, 7, 8, 9, 10.** Confirmed unchanged: `PruneStalledTenantProvisions`'s
  three inline predicates, `InlineCheckoutGateway`'s scoped write,
  `numerosis.tenancy.seeder`'s dead projection, `LoginWithSocialAccount.php:69`'s
  wrong-connection `DB::transaction()`, the four contracts named in phase 8's
  table (`PersistsToProvisionColumns` still names `TenantProvision`;
  `ProvisionsTenant`, added by the merge, names no concrete model and needs no
  entry), and every phase 9/10 target file are all untouched by the merge.

**Rules to read per phase** (`.ai/rules/`, per the repo's `CLAUDE.md` — open
these before editing, not after):

| Phase | Rule files |
|---|---|
| 1, 2, 9.1, 9.5–9.6 | `billing-checkout.md`, `architecture-conventions.md` |
| 3 | `static-analysis.md`, `architecture-conventions.md` |
| 4, 5 | `tenant-provisioning.md`, `central-rows-on-tenant-routes.md` |
| 6 | `tenant-provisioning.md`, `testing.md` |
| 7 | `events-listeners-observers.md` (it names this defect), `auth-login.md` |
| 8 | `architecture-conventions.md`, `package-boundaries.md` |
| 9.2–9.4 | `auth-guards.md`, `middleware-registration.md`, `package-host-bootstrap.md` |
| 9.7 | `views.md`, `tenant-registration-wizard.md` |
| 10 | `events-listeners-observers.md` |

`general.md` applies to every phase: default to **zero** comments, hard caps
of 5 docblock prose lines and 3 `//` lines, no exemption for public seams, and
never cite `.ai/rules`, `.claude` or `docs/` from source. New contracts get a
short docblock only where the *why* is non-obvious — match
`Contracts/Tenancy/RequiresContributions.php` for tone, not length.

**Test fallout, measured.** Phases 2 and 4 are the ones that will surprise:

| Surface | Test files naming it |
|---|---|
| `PaymentPlan` (phase 1) | 20 — the real one; budget for it |
| `TenantProvision` | 43 files name it, but phase 4 no longer touches them; expect ~2 |
| `Cashier` | 17 files name it; phase 2's expected diff is **zero** |
| `BillableResolver` (phase 9.1) | 0 |

Convert assertions rather than deleting them — deleting tests needs approval,
per the repo's test rules.

Phases 2 and 4 both deliberately touch almost no test, because both were
rescoped away from new abstractions. A large test diff in either is a signal
the conversion drifted back toward the rejected design.

## Phase 0 — `$tenant->run()` leaks tenancy on a throw (live bug, do first)

Found 2026-09-12 by a follow-up sweep for "handrolled where an abstraction
already exists". It is the inverse case: the abstraction exists and is
**unsafe**, and the one place that handrolled around it was right to.

`vendor/stancl/tenancy/src/Database/Concerns/TenantRun.php:18-33`:

```php
public function run(callable $callback)
{
    $originalTenant = tenant();
    tenancy()->initialize($this);
    $result = $callback($this);          // no try/finally
    if ($originalTenant) { tenancy()->initialize($originalTenant); }
    else { tenancy()->end(); }
    return $result;
}
```

No `try`/`finally`. A throw inside the callback skips the revert, so the
process stays initialized against that tenant — tenant DB connection, cache
prefix, auth guard and Spatie permission registrar all still pointed at it.

`src/Actions/Tenancy/SeedTenantDatabase.php:36-52` already knows this and
handrolls `tenancy()->initialize()` / `end()` in a `try`/`finally`, with a
comment that is exactly correct: "Async code manages its own revert:
`$tenant->run()` gives no such guarantee, and leaving tenancy initialized
leaks into the next job on this worker."

Three sites still use the unsafe version:

| Site | Throws? |
|---|---|
| `src/Actions/Tenancy/PromoteFirstUserToAdmin.php:45-56` | **Yes, by design** — `throw_unless($user, NoPromotableUser::class)` at `:48`, inside the closure |
| `src/Actions/Tenancy/EnsureTenantUserExists.php:32-46` | On any DB error from `firstOrCreate` |
| `src/Models/Central/Tenant.php:226` | If Spatie's `role('admin')` scope rejects an unknown role |

`PromoteFirstUserToAdmin` is the serious one. It is a `ProvisioningStep`, so it
runs inside `Jobs/RunProvisioningStep` (`ShouldQueue`), and its throw is a
documented, reachable path — `.ai/rules/events-listeners-observers.md`
describes the ordering that produces `NoPromotableUser`. When it fires: the
job fails, the worker picks up the next job, and that job runs in the failed
tenant's context. Cross-tenant read or write, from a queue worker, with
nothing red in the suite — `tests/TestCase.php` sets `queue.default = 'sync'`,
so no test ever has a second job on the same worker.

### Steps

1. Add `Concerns/Tenancy/RunsInTenant` with a `runInTenant(Tenant $tenant, Closure $callback)`
   that does what `SeedTenantDatabase` does: capture `tenant()`, initialize,
   `try { … } finally { restore-or-end }`. Return the callback's value.
2. Convert the three sites above, and `SeedTenantDatabase` itself, onto it —
   so there is one spelling and the `finally` cannot be forgotten again.
3. Do **not** patch stancl. It is a `require`, not a fork, and
   `.ai/rules/stancl-tenancy-v4.md` records that we do not carry local
   changes to it.
4. Test it properly: a step that throws inside `runInTenant()`, asserting
   `tenancy()->initialized === false` afterwards. Write it against the current
   code first and watch it fail — per `.ai/rules/testing.md`, a repaired
   assertion has to be made to fail once. Then a regression test that a second
   queued job after a failed provisioning step does not see tenant context;
   that one needs the queue not faked to sync.
5. Grep for new `->run(` on a tenant afterwards and consider an arch test, the
   way `ArchTest` bans `Auth::user()`.

Highest severity item in this file. Land before phases 6 and 7.

## Phase 1 — `PaymentPlanRepository` is a seam in signature only

`PaymentPlanRepository` returns `Contracts\Billing\Plan` from all five methods.
Every consumer needs `Models\Central\PaymentPlan`:

| Site | Uses |
|---|---|
| `resources/views/components/billing/plan-card.blade.php:17` | `$plan->is_popular` |
| `plan-card.blade.php:23` | `$plan->getPrice($cycle)` |
| `plan-card.blade.php:28` | `$plan->features` (Eloquent relation) |
| `plan-card.blade.php:38,123,129,224` | `$plan->slug` as attribute |
| `order-summary.blade.php:16` | `getPrice()` |
| `order-summary.blade.php:39` | `$plan->name` as attribute |
| `src/Actions/Billing/Subscriptions/SwapSubscriptionPlan.php:23-24` | hints concrete `PaymentPlan` |
| `src/Observers/Billing/PaymentPlanObserver.php:21,26` | hints concrete `PaymentPlan` |

`Plan` declares `slug()`, `name()`, `priceId()`, `price()`, `trialDays()`,
`metadata()` — none of `is_popular`, `features`, `getPrice()`, and the two
attribute reads collide with the method names. Bind a non-Eloquent
`PaymentPlanRepository`, which is the documented reason the contract exists,
and every plan view 500s.

**Two names on the contract are already taken on the model. Read this before
writing any signature.**

- `PaymentPlan.php:85` declares `protected function isPopular(): Attribute`.
  A public `isPopular(): bool` on `Plan` cannot coexist with it, and forcing
  it kills the `$plan->is_popular` accessor Laravel resolves through that
  method. **Do not add `isPopular()` to the contract.**
- `PaymentPlan.php:93` declares `public function features(): BelongsToMany`.
  Adding `features(): Collection` to `Plan` is an incompatible-return fatal.
  **Do not add `features()` to the contract either.**
- `price()` (`:136`) already delegates to `getPrice()` (`:196`) and both
  return `?int` in minor units, so retargeting the views from `getPrice()` to
  `price()` is a pure rename with no currency risk. Verify that with a test
  asserting a known cents value, not by reading the two methods.

So:

1. Views call `$plan->price($cycle)`, `$plan->slug()`, `$plan->name()` —
   contract methods that all already exist. No contract change for these.
2. **Popularity moves to the repository, not the plan.**
   `PaymentPlan::popular()` (`:171`, public, `bool`) is a cached ranking over
   `Subscription` counts — it answers "is this the most-subscribed plan",
   which is a property of the *set*, not of one plan. Putting it on `Plan`
   forces every host implementation to reimplement a ranking. Add
   `PaymentPlanRepository::mostPopularSlug(): ?string` instead, have
   `EloquentPaymentPlanRepository` own the `GlobalCache` lookup now living in
   `popular()`, and let `plan-card.blade.php` compare it against
   `$plan->slug()`. `PaymentPlan::popular()` then becomes unused — delete it
   and `CacheKeys::popularPaymentPlanId()` moves with the query.
3. **Features go on the repository too, as a DTO.** Add
   `PaymentPlanRepository::featuresFor(Plan $plan): Collection` returning a
   new `Data\Billing\PlanFeature` with exactly four fields — `slug`, `name`,
   `description`, `available` (the last from the `payment_plan_features`
   pivot, which `PaymentPlan::features()` already eager-loads via
   `withPivot(['available'])`). That kills the `$feature->pivot->available`
   filter at `plan-card.blade.php:29` and the `$feature->name ?? $feature->slug`
   fallback at `components/feature-line.blade.php:23` — `PlanFeature::name` is
   an accessor (`Models/Central/PlanFeature.php:68`), so the DTO must resolve
   it, not the view.
4. Keep `plan-card.blade.php`'s one-read optimisation. Its comment at `:25-27`
   records that asking through `availableFeatures()`/`features()` ran three
   queries per card; the DTO collection has to be passed in once and filtered
   in PHP, not re-fetched per use.
5. Drop both `@use(\Nvade\Numerosis\Models\Central\PaymentPlan)` lines and
   both `/** @var PaymentPlan $plan */` docblocks.
6. Retype `SwapSubscriptionPlan::handle()` to `Plan`.
7. Leave `PaymentPlanObserver` on the concrete model — an observer is bound to
   a model by definition, and its docblock already says what it guards.
8. Add a test binding a stub `PaymentPlanRepository` that returns a plain
   non-Eloquent `Plan` implementation, and render both views through it. That
   test is the whole point of the phase; without it the seam rots again.

`PaymentPlan::getPrice()`/`getPriceId()` stay on the model — the `available()`
scope and the feature pivot are Eloquent concerns. The contract gains callers,
not a second spelling.

**Test fallout:** 20 test files name `PaymentPlan`; 1 each names `plan-card`
and `order-summary`. Expect to touch the two view tests and any factory-built
plan assertion that reads `is_popular`. Do not delete assertions to make this
green — `.ai/rules` and the repo's test rules both forbid it; convert them.

## Phase 2 — use Cashier's Billable API instead of its escape hatch

**This phase was first written as "no Stripe seam — add two contracts". That
was wrong and the corrected version is smaller. Cashier *is* the abstraction.
`Cashier::stripe()` is its documented escape hatch, so reaching for it where a
`Billable` method exists is the actual defect — and no new contract fixes it.**

The rejected design and why, so it is not re-proposed:

- Contracts returning `Stripe\*` objects abstract *which Stripe client*, which
  Cashier already owns. 20+ files in `src/` already name
  `Stripe\{Customer,PaymentMethod,SetupIntent,Subscription}`, so no seam is
  gained.
- Contracts returning our own DTOs would be a real processor boundary, but
  `Stripe\*` types are already the currency across the billing layer and ~17
  test files read them, so it is a multi-session rewrite for a portability
  nobody has asked for.
- Contract-level *fakes* are actively harmful. `src/Testing/FakeStripeHttpClient.php`
  is a 438-line stateful in-memory Stripe at the HTTP layer, and its own
  docblock records the requirement: "these flows read back what an earlier
  call in the same test wrote." `tests/Concerns/CreatesCheckoutFixtures.php:86,109,123`
  calls `Cashier::stripe()` interleaved with Cashier's own
  `createOrGetStripeCustomer()` at `:110,124`, then production code retrieves
  the same objects. A second fake at the contract layer splits that state.
- `tests/Feature/Livewire/Billing/CheckoutTest.php:244-266` counts
  `GET /v1/customers/{id}` requests to pin a 150–400ms double-round-trip
  regression, and it counts our calls and Cashier's indiscriminately. Routing
  ours through a contract fake makes it pass while proving nothing.

**Nine sites call the raw client for something `Billable` already wraps.**
Verified against installed source (`vendor/laravel/cashier/src/Concerns/`),
not the docs:

| Site | Raw call | Replace with |
|---|---|---|
| `FetchStripeCustomer.php:28` | `customers->retrieve($id, ['expand'=>['tax_ids']])` | `$billable->asStripeCustomer(['tax_ids'])` |
| `SyncBillingAddress.php:33` | `customers->update($id, [...])` | `$billable->updateStripeCustomer([...])` |
| `AttachVatNumber.php:24` | `customers->createTaxId($id, [...])` | `$billable->createTaxId($type, $value)` |
| `AddVatNumber.php:36` | retrieve customer to read tax ids | `$billable->taxIds()` / `findTaxId()` |
| `ResolveSavedPaymentMethod.php:26` | `paymentMethods->retrieve($id)` | `$billable->findPaymentMethod($id)` |
| `ResolveSetupIntent.php:45` | `setupIntents->retrieve($id, $params)` | `$billable->findSetupIntent($id, $params)` |
| `ResumeCheckout.php:43` | same | same |
| `SettleAttachedPaymentMethod.php:37` | same | same |
| `FetchReusablePaymentMethods.php:39` | `customers->allPaymentMethods($id, ['type'=>'card','limit'=>10])` | `$billable->paymentMethods('card', ['limit'=>10])` |

`ManagesPaymentMethods::findSetupIntent()` is exactly
`return static::stripe()->setupIntents->retrieve($id, $params, $options);` —
three sites hand-inline a passthrough. `asStripeCustomer(array $expand = [])`
matches `FetchStripeCustomer`'s expand usage argument-for-argument.

**Four sites are legitimately raw. Do not change them; add one line each
saying why, so the next audit does not re-flag them.**

- `ResolveAttachedPaymentMethod.php:51` — `setupAttempts->all()`. Cashier
  wraps nothing for SetupAttempts.
- `Tenancy/LinkTenantSubscription.php:50` — retrieves a Stripe subscription by
  id with no billable in hand.
- `Console/Commands/PruneOrphanedStripeCustomers.php:49,67` — operates on
  customer ids whose local user is gone, so there is no billable to call a
  method on. That is the command's purpose.
- `FindTenantByStripeCustomer.php:29` — `Cashier::findBillable()` is Cashier's
  own API, not the escape hatch. Not a finding; it was listed in error.

### Steps

1. Convert the nine sites in the table. One commit; they are independent.
2. Two behaviour changes to handle deliberately, not discover:
   - `asStripeCustomer()` calls `assertCustomerExists()` and throws
     `Laravel\Cashier\Exceptions\InvalidCustomer`, where `FetchStripeCustomer`
     currently catches `ApiErrorException` and returns `null`. The guard moves
     from a catch to a precondition, which that action's docblock already
     assumes ("a billable with no Stripe customer at all is the caller's
     `hasStripeId()` check, not this one's"). Keep returning `null` — catch
     `InvalidCustomer` alongside `ApiErrorException`.
   - `Billable::paymentMethods()` returns `Laravel\Cashier\PaymentMethod`
     wrappers, not `Stripe\PaymentMethod`, and uses `paymentMethods->all()`
     rather than `customers->allPaymentMethods()`. So
     `FetchReusablePaymentMethods`'s `collect($paymentMethods->data)->map(fn (PaymentMethod $pm) => …)`
     becomes a map over the returned Collection, and the `PaymentMethod`
     import changes. Cashier's wrapper proxies `->card` through `__get`, so
     the field reads survive. This is the one site with real churn — do it
     last, on its own.
3. Keep `FakesStripe` and `FakeStripeHttpClient` exactly as they are. Cashier's
   Billable methods go through the same `ApiRequestor` client, so every
   existing test keeps working unchanged. **Expected test diff for this phase:
   zero.** If a test needs changing, stop — something about the conversion is
   wrong.
4. Re-verify `CheckoutTest.php:244-266` still *fails* when a second customer
   retrieve is deliberately reintroduced. It should, since the counting is
   below Cashier, but it is the one assertion this phase could quietly
   weaken.
5. Add `Cashier::stripe()` to the list of things `.ai/rules/billing-checkout.md`
   warns about, with the four legitimate exceptions named.

Smaller than originally scoped: nine mechanical edits, one with churn, no new
contracts, no new config keys, no test changes.

## Phase 3 — `Numerosis::model()`: 104 call sites on a static

`src/Numerosis.php` is 328 lines of statics; `model()` is 104 of the class's
142 call sites, and 29 of those are `Numerosis::model(X::class)::staticQuery()`.
Nothing can be injected or faked, the memo needs `resetModelCache()` in tests,
and the `class-string` return is why ~64 of `src/`'s 117 `@var` annotations
exist (`InstallNumerosisCommand` 10, `HostConfig` 7, `WebhookController` 6,
`Livewire/Billing/Checkout` 5). `phpstan-baseline.neon` holds 5 entries, all
test-only — level 9 is green *because* of those annotations, not despite them.

This phase does not delete `Numerosis::model()`. It is the right seam for
"which class did the host configure", it is read at container-registration
time in places where injection is impossible, and 104 mechanical edits carry
more risk than they remove. It narrows the blast radius instead:

1. Absorb what phase 4 absorbs — three query predicates onto the model as
   scopes. That is smaller than the repository this step originally assumed.
2. Move the 6 duplicated `Numerosis::model(Tenant::class)::findOrFail($provision->slug)`
   lookups out of the provisioning steps (phase 5).
3. For the remainder, leave `Numerosis::model()` and instead delete the `@var`
   annotations that a `@return class-string<TModel>` generic already covers —
   `ModelResolver::resolve()` is correctly generic, `Numerosis::model()`
   forwards it, so some of the annotation tax is habit, not necessity.

**Done-condition for step 3, so this does not become open-ended churn:** pick
the three highest-count files only — `Console/Commands/InstallNumerosisCommand.php`
(10), `Http/Controllers/Billing/WebhookController.php` (11, up from 6 — an
unrelated PHPStan-hygiene commit added five since this count was taken; see
"Re-audit after merge"), `Boot/HostConfig.php` (7). Delete every `@var` in
those three, run `composer analyse` on a **cold**
cache (`vendor/bin/phpstan clear-result-cache` first, per
`.ai/rules/static-analysis.md`), and restore only the ones that actually go
red. Record the surviving count in this plan file and stop. Do not sweep the
other 23 files, and do not add an arch test capping annotation counts — a
ratchet on a metric nobody reads costs more than it saves.

The number that comes back is the finding. If most annotations survive, they
are load-bearing and `Numerosis::model()` is not worth refactoring at any
scale — write that down and close the phase.

## Phase 4 — three query scopes on `TenantProvision`, not a repository

**Originally written as "add `TenantProvisionRepository`". That was the same
mistake as phase 2 — the abstraction already exists, and it is the model.**
`TenantProvision` already carries the API a repository would wrap:

`claim()` (static, `:100`), `hasRun()` (`:145`), `recordStep()` (`:153`),
`currentStep()` (`:173`), `currentStepLabel()` (`:194`), `contribution()`
(`:126`), `allContributions()` (`:219`), `applyContributions()` (`:249`),
`hasFailed()` (`:73`), `isSettled()` (`:78`).

A `TenantProvisionRepository` would be a second name for each of those, bound
in config, for a model nothing swaps independently of `Tenant`. Do not build
it. The `PaymentPlanRepository`/`SubscriptionRepository` precedent does not
transfer: those exist so a host can serve plans from config or a different
store, which is a stated feature. Nobody wants a non-Eloquent provision row —
the pipeline's resumability is rows.

**What is actually duplicated is three query predicates**, each written inline
in `Console/Commands/PruneStalledTenantProvisions.php`:

| Method | Lines | Predicate |
|---|---|---|
| `forgetCompletedProvisions()` | `:42-44` | `status = Completed AND completed_at < $cutoff` |
| `releaseAbandonedReservations()` | `:67-69` | `status = Reserved AND created_at < $cutoff` |
| `flagStalledProvisions()` | `:93-95` | `status = Provisioning AND created_at < $cutoff` |

1. Add three `#[Scope]` methods to `TenantProvision` — `completedBefore`,
   `reservedBefore`, `provisioningBefore`, each taking a `Carbon $cutoff`.
   Attribute scopes, matching `PaymentPlan`'s own `#[Scope] protected function
   available()` (`Models/Central/PaymentPlan.php:159`); this repo is on
   Laravel 13 and that is the house spelling.
2. Rewrite the three command methods against them. The `$dryRun` counting and
   the messages stay in the command — that is presentation.
3. Leave every other site in the original list alone.
   `Numerosis::model(TenantProvision::class)::firstWhere('slug', …)` is a
   primary-key-ish lookup, not a predicate worth naming, and routing it
   through a scope buys nothing.
4. The scoped-write invariant at `InlineCheckoutGateway.php:57-60` ("an
   unscoped write would overwrite a stranger's SetupIntent") is worth moving
   onto the model as a named method, since the comment is the only thing
   holding it. One method, `claimSetupIntent(string $slug, string $setupIntentId, string $globalId)`,
   with the ownership predicate inside it.

**Test fallout: near zero, not 43.** The 43-file count was for a repository
touching every call site; three scopes and one method touch
`PruneStalledTenantProvisionsTest` and `InlineCheckoutGatewayTest` only.

## Phase 5 — `ProvisioningStep` should receive what it needs

`ProvisioningStep::handle(TenantProvision $provision)` hands each step the
provision row, and then seven of them immediately re-resolve the tenant:
`CreateTenantDatabase.php:31`, `MigrateTenantDatabase.php:24`,
`SeedTenantDatabase.php:34`, `FinalizeTenantProvisioning.php:30`,
`LinkTenantSubscription.php:53`, `AddTenantOwner.php:42`,
`PromoteFirstUserToAdmin.php:40` — the same
`Numerosis::model(Tenant::class)::findOrFail($provision->slug)` line, seven
times. (`PromoteFirstUserToAdmin` picked up this line during the provisioning
refactor that merged 2026-09-12 — it originally took `Tenant $tenant`
directly; the six-site count predates that.)

**Do option 1. Option 2 is recorded only so it is not re-proposed.**

1. Add `TenantProvision::tenant(): BelongsTo` keyed on `slug` → `tenants.id`
   (the tenant's primary key *is* the slug — see `CreateTenant.php:34`,
   `'id' => $provision->slug`), and replace the seven `findOrFail` lines with
   `$provision->tenant` / `$provision->tenant()->firstOrFail()`.
2. Both models are central-connection, so this is not a cross-connection
   relation. But the relation is null for any step that runs *before*
   `CreateTenant` in `numerosis.tenancy.provisioning.steps` — only convert the
   seven sites that already call `findOrFail` today, and leave the contract
   hinting `TenantProvision`. Do not add a `tenant()` call to any other step.

Option 2 was: widen `ProvisioningStep::handle()` to take a
`Data\Tenancy\ProvisioningContext` carrying both the provision and the tenant,
which would also remove the concrete-model dependency the contract has today
(phase 8). Rejected here — it changes the public extension point every host
implements, for a duplication problem option 1 solves in six one-line edits.
Revisit only if phase 8 item 3 is taken up independently.

## Phase 6 — `numerosis.tenancy.seeder` does nothing (live defect)

`src/Actions/Tenancy/SeedTenantDatabase.php:40` hardcodes
`resolve(TenantDatabaseSeeder::class)`. `src/Boot/HostConfig.php:205` projects
`numerosis.tenancy.seeder` onto `tenancy.seeder_parameters['--class']`, which
only stancl's `tenants:seed` reads — and this step deliberately bypasses that
command (its own docblock, lines 20–22, explains why). So setting the
documented config key has no effect on provisioning; only a container `bind()`
works, which is what `docs/extending.md:34` describes.

Read `Config::string('numerosis.tenancy.seeder', TenantDatabaseSeeder::class)`
in the step. Add a test that sets the key to a spy seeder and asserts the
pipeline runs it. Keep `HostConfig`'s projection — a host may still invoke
`tenants:seed` by hand.

## Phase 7 — `DB::transaction()` on the wrong connection (live defect)

`src/Actions/Auth/Social/LoginWithSocialAccount.php:69`. `DB::transaction()`
reads `database.default`; the writes inside go to `CentralConnection` models on
`tenancy.database.central_connection`. Two `Connection` objects, two PDO
handles, two transaction stacks — so the transaction covers nothing and a
throw rolls back nothing. `.ai/rules/events-listeners-observers.md` records
this exact defect as still-live as of 2026-09-07, naming this file;
`AcceptInvitation` was fixed and this was not. It is the only remaining
`DB::transaction()` in non-test `src/`.

Switch to `$user->getConnection()->transaction(...)`. The test has to prove
the rollback: write a failing assertion first, per `.ai/rules/testing.md`.

## Phase 8 — contracts that name concrete models, and the arch tests that miss them

`tests/Feature/ArchTest.php:26,30` scopes "contracts do not depend on app
models" to `Contracts\Billing` plus exactly two named tenancy contracts, and
its docblock exempts `Subscribable`/`HasTenants` by name with a reason. Four
contracts added since are silently outside it:

| Contract | Line | Names |
|---|---|---|
| `Contracts/Tenancy/ProvisioningStep` | 7 | `Models\Central\TenantProvision` |
| `Contracts/Tenancy/PersistsToProvisionColumns` | 7 | `Models\Central\TenantProvision` |
| `Contracts/Tenancy/TenantDatabaseManager` | 7 | `Models\Central\Tenant` |
| `Contracts/Notifications/NotifiesTenantOwner` | 8 | `Models\Central\Tenant` |

`TenantDatabaseManager` is the easy one: stancl already ships
`Stancl\Tenancy\Contracts\TenantWithDatabase`, which is what
`databaseExists()` actually needs, and `src/Testing/CleansUpTenancyDatabases.php:14`
already uses it.

1. Retype `TenantDatabaseManager::databaseExists()` to `TenantWithDatabase`.
2. Retype `NotifiesTenantOwner::notify()` to a new one-method
   `Contracts/Tenancy/HasTenantOwner` (`owner(): ?CentralUserModel`), which
   `NotifiesTenantOwnerDirectly.php:15` is the only consumer of.
3. `ProvisioningStep` / `PersistsToProvisionColumns`: only worth changing if
   phase 5 option (2) lands, in which case they take the
   `ProvisioningContext` DTO. Otherwise document them as deliberate, the way
   `Subscribable` already is.
4. Widen the arch rule to all of `Nvade\Numerosis\Contracts` with an explicit
   named-exception list, so the next contract is covered by default. Named
   exceptions, not a path scope — the current scope is why this drifted.
5. Also in `ArchTest`: `everything in Services implements something` (line 195)
   asserts `getInterfaceNames() !== []`, which inherited vendor interfaces
   satisfy. `Services/Tenancy/PreservingPathTenantResolver` implements nothing
   of ours and passes by inheriting stancl's `TenantResolver` — a third
   un-named exception the test was written to prevent. Filter
   `getInterfaceNames()` to `Nvade\Numerosis\Contracts\*` and add the resolver
   and the three `Bootstrappers/` classes to the named list, with the reason
   (they implement stancl's contracts, which is correct for a stancl adapter).
6. `Services/Tenancy/Bootstrappers/` is a one-sided sub-grouping —
   `Contracts/Tenancy/` is flat and has no mirror — which
   `architecture-conventions.md` forbids in as many words. Either flatten the
   three files into `Services/Tenancy/` or record the exception in that rule
   file. Flattening is cheaper and matches the `Services/Billing/` precedent
   from 2026-09-11.

## Phase 9 — narrow the over-wide returns and the stray concretes

Small, independent, mechanical. Each is one or two lines.

1. `Contracts/Billing/BillableResolver.php:18` returns `?Model`, and all six
   callers immediately narrow it: `StartSubscriptionCheckout.php:45,49` (two
   `instanceof` in a row), `InlineCheckoutGateway.php:46` (`throw_unless`),
   `AssertReservationIsOwned.php:30`, `CompleteRedirectCheckout.php:44`,
   `CreateInlineSubscription.php:57`, `BillingService.php:70`. PHP 8.4 DNF
   gives `null|(Model&BillableUser&Subscribable)` — put the narrowing in the
   one implementation. Deletes six runtime checks and their tests' setup.
2. `src/Http/Middleware/EnsureSessionMatchesTenant.php:27` — `AuthManager` to
   `Illuminate\Contracts\Auth\Factory`; it only calls `guard()`.
   `Authenticate.php:24` and `AuthGuardBootstrapper.php:24` call
   `getDefaultDriver()`, which is not on `Factory`, and stay.
3. `src/Http/Middleware/EnsureTenantSubscriptionActive.php:24` narrows on
   concrete `Tenant` for `isSuspended()`. Extract a one-method
   `Contracts/Tenancy/Suspendable`.
4. `src/Providers/TenancyServiceProvider`'s four statics
   (`identificationMiddleware()`, `livewireUpdateIdentificationMiddleware()`,
   `tenancyRouteMiddleware()`, `shouldCacheResolvedTenants()`) are a static
   lookup service on a service provider, imported by
   `Http/Middleware/InitializeTenancy.php:22` and `TenantRouteGuard.php:22`.
   Move them to `Boot/` — that is the category the `src/Support/` deletion
   established for config-derived answers about the host.
5. `src/Services/Billing/BillingService.php:21,100-102` imports and
   instantiates `Testing\FakeCheckoutGateway`; `src/Facades/Billing.php:16`
   references it in a docblock. `src/Testing/` ships in the published split,
   so `Billing::fake()` puts a test double on the production autoload path.
   Move `fake()` to a `Testing\` helper. Add the `src/` → `src/Testing/`
   direction to `PackageBoundariesTest`, which does not check it today.
6. `resources/views/components/billing/order-summary.blade.php:15` and
   `resources/views/livewire/tenant/registration/wizard/steps/plan.blade.php:15`
   both `resolve(BillingService::class)`. The `Billing` facade exists for
   this, and `BillingService` is one of the two "implements nothing" residents
   a host cannot swap. Use `Billing::formatAmount()`.
7. Query logic in Blade single-file components:
   `pages/tenant/⚡mine.blade.php:77,94` and `⚡invitations.blade.php:26`.
   Move behind phase 4's repository and `Actions/Queries/` — that folder
   exists for exactly this and holds three classes.
8. ~~`ResolvedSetupIntent` / `ResumedCheckout` hold an Eloquent model inside a
   `Data` object.~~ **Withdrawn — the premise was false.** Neither extends
   `Data`; both are plain `final readonly class`, and
   `ResolvedSetupIntent.php:14` says why in as many words: "Not a Spatie Data
   object, since it never crosses the wire." They are the only 2 of 21 classes
   under `src/Data/` that are not `Data` subclasses, and they are exactly the
   two whose docblocks explain the exception. Deliberate, consistent, no
   action.

## Phase 10 — events carrying a model with no id

`src/Events/Billing/PaymentFailed.php:17` and `TenantSuspended.php:17` carry
only `public readonly Tenant $tenant`.
`.ai/rules/events-listeners-observers.md` requires a scalar id alongside any
model, because `SerializesModels` re-queries on unserialize — which fails for
a deleted row and resolves against the wrong connection for a tenant-scoped
one. `Events/Billing/PaymentSettled` and `Events/Tenancy/TenantProvisioned`
carry `$ownerId` but no `$tenantId`, where `TenantRestored` and
`SubscriptionCancelled` carry all three.

Add `public readonly string $tenantId` to all four. Breaking change to the
event signatures, which is free here — nothing installs this package yet.

## The "handrolled vs existing abstraction" sweep — what came back clean

Run 2026-09-12 after phase 2's original design turned out to be wrong. The
audit that produced phases 1–10 asked "is there a concrete class where a
contract belongs", which cannot see "an abstraction already exists one layer
up". This sweep asked the inverse. Recorded so it is not repeated: it found
**one** thing (phase 0), and these came back clean.

| Checked | Result |
|---|---|
| `spatie/laravel-permission` | Correct. `hasPermissionTo()` throughout `Policies/`; no manual `whereHas('roles')` anywhere |
| Signed URLs / email verification | Correct. `URL::temporarySignedRoute()` + `hash_equals(sha1(…))` mirrors Laravel's own `VerifyEmail` |
| Cache locking | Correct. `Cache::lock(…)->block(5, …)` at the three contended sites; no handrolled `lockForUpdate` loops |
| Cache reads | Correct. `remember()` used; zero manual `has`/`get`/`put` triples in `src/` |
| Retry / backoff | Correct. Job `$tries`/`$backoff` plus the `ControlsItsOwnRetries` contract; no `usleep` loops |
| `lorisleiva/laravel-actions` | Correct. `asController` at the 3 sites that need it, `::dispatch()` for queueing. `Jobs/RunProvisioningStep` being one generic job over a config-driven step list is deliberate — `asJob` per action would lose the chain and the per-step resume |
| `Str::` / `Arr::` / Collections | Correct. `array_filter`/`array_map` over `list<>` is the right call at level 9; converting to Collections would be churn |
| `spatie/laravel-data` | Correct. 19 of 21 extend `Data`; the 2 that do not are documented (see 9.8) |
| Cashier | The one real cluster — 9 sites, now phase 2 |

The productive question was not "is this handrolled" but **"does an
abstraction exist here, and is it safe?"** Phase 0 is a case where the answer
was yes and no respectively, which no amount of looking for missing contracts
would have surfaced.

## Out of scope, recorded so it is not re-derived

- **`Numerosis::model()` itself.** Phase 3 narrows its reach and measures its
  annotation cost; it does not replace it. 104 mechanical edits for a seam
  that works is the wrong trade, and `ModelResolver` is already correctly
  generic.
- **`src/Actions/Billing/Checkout/StartLocalCheckout.php:27`** injects the
  concrete `LocalCheckoutGateway` on purpose — the dev-only route must not
  pick up whatever gateway the host configured. Leave it, and leave the
  comment.
- **`src/Concerns/Auth/ForgetsGuardSession.php:7`**'s `SessionGuard`
  narrowing is deliberate and its docblock says why (`SessionGuard::logout()`
  resolves the user first and writes a remember token onto a stranger's row
  outside tenant context).
- **`src/Routing/RouteLoader.php:154`** reflects Fortify's class file to find
  `vendor/.../routes/routes.php`, and
  **`src/Console/Commands/InstallNumerosisCommand.php:189`** writes Composer's
  private `missingClasses`. Both are vendor-internal and both break on a
  layout change with no test to catch it — but both are commented, both are
  off the request path, and neither has a supported alternative. Worth a rule
  file entry, not a refactor.
- **Facade use generally.** 36 `Config::`, 8 `Auth::`, 6 `Log::` and so on are
  idiomatic Laravel and the house style. `ArchTest` already bans the one
  spelling that mattered (`Auth::user()`/`auth()->user()`).
- **`Features/`.** `NamedFeature::available()` is declared on the contract, so
  the static calls from Blade and route files are contract calls. Clean; no
  action.
- **Fortify and Socialite.** Both consumed through their contracts
  (`CreatesNewUsers`, `ResetsUserPasswords`, `LoginResponse`,
  `Socialite\Contracts\User`). Clean.

## Verification, per phase

`vendor/bin/pest --filter=<name>` while iterating, then `composer test`
(`pest --parallel`) and `composer analyse` on a **cold** result cache before
calling a phase done — a warm cache hides errors, per
`.ai/rules/static-analysis.md`. `vendor/bin/pint --dirty` before finishing any
PHP change. The MySQL container has to be up first
(`docker start numerosis-mysql-1`).

Phases 1, 2, 4 and 6 each need a *new* test that would have caught the finding
— a stub binding for 1, a fake for 2, a spy seeder for 6. Phase 7's test has
to be written failing first. Without those, every phase here is reversible by
the next person who does not know the seam is load-bearing.
