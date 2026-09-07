# Simplification follow-ups

**Status:** Executed 2026-09-07, all eight phases. Written 2026-09-05, from a
whole-codebase review
(`src/`, `resources/`, `routes/`, `config/`, `database/`) across four angles —
reuse, simplification, efficiency, altitude.

Everything the review found that was *safe and test-covered* was applied in the
same session and is Phase 0 below. This file is the remainder: the findings
whose fixes change behaviour, need their own tests, or reach well outside the
area they were found in.

Branch at the time of writing, and of execution: `fix/pr-review-remediation`.

## What was executed differently

Recorded so the diff and this file can be read together.

- **2.1** — `DefaultUnpaidTenantQuota`'s `!== 'active'` turned out to be
  deliberate, not the drift the finding assumed:
  `.ai/rules/billing-checkout.md` already says "unpaid there means anything
  short of a confirmed active subscription, trials included". It keeps its own
  predicate and now says why. The other four sites read
  `Subscription::SETTLED_STATUSES`.
- **2.3** — narrowing on `User&CentralUserModel` was not enough: checkout also
  calls Cashier's `Billable`, which no interface declares.
  `Contracts\Billing\BillableUser` is what the sites narrow on instead.
- **2.4 and 5.6** landed together, as 2.4 anticipated. Both assertions live in
  `Support\ConfiguredSteps` and run on console boots only. 5.6's "not free"
  note was wrong: nothing covered the `LogicException` path, so there was no
  test to move.
- **3.2** — the parent asks step *classes*, so `HasTransientState::transientStateKeys()`
  is static.
- **5.4** — the instance memo the finding asked for is not there: the global
  cache key is invalidated when a domain changes and
  `TenantPrimaryDomainCacheTest` pins that a caller sees it within the request.
  The view reads once into a local instead. The eager load is also absent, and
  the method says why — stancl's `InvalidatesResolverCache` loads `domains` on
  `saved`, before the tenant has one.
- **5.7** — dropped, as the finding itself suggested.
- **6** — the table refactor was not done. The coverage test was, and writing
  it found five keys the doctor did not check; those five checks were added.
- **8.5** — `config-consolidation.md` had in fact been executed, so it moved to
  `archive/` rather than being corrected in place.

## How to use this

Phases are ordered by dependency, not by value. Phase 1 and Phase 2 are
independent of each other and of Phase 3; Phase 4 depends on nothing but is
the largest mechanical change and should land alone. Each item names its own
verification. Nothing here is a single commit.

`composer lint` (Pint + PHPStan level 9) and `composer test` must be green at
the end of every phase, not only at the end of the plan.

---

## Phase 0 — already applied, uncommitted

Staged in the working tree, not committed. `composer analyse` clean,
`composer test` 593 passed / 6 skipped, Pint clean.

Deleted: the tenant chat schema (2 migrations), the Telescope migration,
`StartPlanChangeCheckout`, `GetAuthenticatedTenantUser`, three never-thrown
exception classes, `dunning-alert.blade.php`, three `numerosis::`-namespaced
copies of Laravel's own lang files, `BillingService`'s six unused static
override hooks, `Membership::isAdmin()`, `PaymentPlan::formatCurrency()`,
`BillingCycle::priceIdLabel()`.

Added `Features\Concerns\IsNamedFeature` and put `available()` on the
`NamedFeature` contract; all nine features now answer the same way and
`TurnstileFeature` lost its `$bootstrapped`/`$forcedForTesting` statics.
Routed four raw `numerosis.auth.guards.*` reads through `Context::guard()`,
the `Payment` wizard step through `PaymentPlanRepository`, and
`InitializeTenancyByDomainOrSubdomain` through `Numerosis::isCentralDomain()`.
Registered `AuthGuardBootstrapper` as a singleton, and made
`registerPublishing()` return early off-console.

**Do first:** commit this before starting Phase 1, so a bisect can separate
the mechanical cleanup from the behavioural work below.

---

## Phase 1 — validation lives in one place

Three findings, one mechanism. The package's stated design (`docs/extending.md`)
is that validation lives *inside* the Fortify-bound actions, because both
Fortify's controller and the Livewire component call them. Two of the three
paths do not honour it, and they have already drifted in ways that are
user-visible.

### 1.1 `UpdateUserPassword` — the conventional entry point skips validation

`src/Actions/Auth/UpdateUserPassword.php:92` (`handle()`/`run()`) applies the
password with no `UpdatePasswordData` at all. `:100` (`update()`, Fortify's
contract) validates. `.ai/rules/architecture-conventions.md` names `handle()`
as the house convention, so the documented call is the unsafe one — a caller
reaching for `::run()` skips `current_password` and the strength rules.

`UpdateUserProfile` has the same two entry points but `handle()` forwards to
`update()`, so it is already correct. That asymmetry is what makes this easy
to miss.

Fix: make `handle(User, array $input)` the validating path and keep the raw
apply private. Alternative, if `run()` should not be offered at all on a
Fortify-contract action: drop `AsAction` from it.

Test: a Pest test asserting `UpdateUserPassword::run()` throws
`ValidationException` for a wrong `current_password`. There is none today.

### 1.2 Livewire settings components validate twice, with different rules

`src/Livewire/Settings/Profile.php:39-49,181` and
`src/Livewire/Settings/Password.php:30-37,113` hand-roll rules that
`Data\Auth\UpdateProfileData::rules()` / `UpdatePasswordData::rules()` already
own, then call the action, which validates again.

Concrete drift, all present today:

- `UpdatePasswordData` makes `current_password` unconditionally `required`;
  `Password::updatePassword()` prepends it only when `$user->password` is set.
  So an OAuth-only user can set a password on the Livewire screen but not
  through Fortify's `/user/password`. **Decide which is correct before
  writing code** — this is a behaviour question, not a refactor.
- `UpdateProfileData` says `sometimes` where the component says `required`.
- `'string'` is present in one rule set and not the other.
- Email uniqueness has three implementations: `Rule::unique(CentralUser::class)
  ->ignore()` in the component, nothing in the DTO, and
  `UpdateUserProfile::ensureEmailIsAvailable()`
  (`src/Actions/Auth/UpdateUserProfile.php:57-67`) hand-rolling a third with
  `$user->newQuery()` — which resolves to a *different table* on the tenant
  guard.

Fix: components call the validating entry point and map the resulting
`ValidationException` into the component's error bag. The precedent for
spreading the Data class's `rules()` instead already exists at
`Livewire/Tenant/Registration/Steps/CompanyInfo.php:22` and
`Http/Requests/Billing/StartCheckoutRequest.php:35` — either shape is fine as
long as there is one source.

Test: `tests/Feature/Livewire/Settings/SettingsComponentsTest.php` covers the
component path only. Add a test that pins both paths to the same rules — the
OAuth-only-user case is the one that currently diverges, so assert it
explicitly.

### 1.3 Email uniqueness has one home

Falls out of 1.2. Whichever of the three implementations survives must be
tenant-guard-correct; `ensureEmailIsAvailable()`'s `$user->newQuery()` is the
one that is not. `.ai/rules/auth-guards.md` is the relevant invariant — read
it before touching this.

---

## Phase 2 — one rule per shared decision

Four places where the same decision is made at N call sites instead of once.

### 2.1 The "settled subscription" allowlist, four copies, one already diverged

- `src/Concerns/Billing/ConfirmsPayments.php:50` — `['active', 'trialing']`
- `src/Actions/Billing/Checkout/SettleCheckout.php:36` — `['active', 'trialing']`
- `resources/views/pages/tenant/⚡mine.blade.php:226` — `['active', 'trialing']`
- `src/Http/Controllers/Billing/WebhookController.php:269` — `'active', 'trialing' =>`
- `src/Services/Billing/Resolvers/DefaultUnpaidTenantQuota.php:31` — **`!== 'active'`**,
  missing `trialing`, so a trialing tenant counts as unpaid

`.ai/rules/billing-checkout.md` requires this be an allowlist "matching
`SettleCheckout::$settled`". It is not, and the drift gates whether an unpaid
tenant gets provisioned.

Fix: `Models\Central\Subscription::isSettled(): bool` — the model already
extends Cashier's, so it is the natural home. Check Cashier's `valid()` /
`active()` first; if either matches the semantics exactly, use it instead of
adding a method.

**Decide:** is `DefaultUnpaidTenantQuota` a bug or deliberate? A trialing
tenant arguably *is* unpaid for quota purposes even though it is settled for
provisioning. If deliberate, it needs a different predicate and a comment
saying so, not `isSettled()`.

Test: one test that pins all five sites to the same predicate. Nothing does
today — coverage is piecemeal across `SettleCheckoutTest` and
`CreateInlineSubscriptionTest`.

### 2.2 `Livewire\Billing\Checkout` hand-rolls the ownership rule three times

`Actions/Billing/Checkout/AssertReservationIsOwned` documents itself as "one
rule for every checkout entry point, so a new one cannot reach a reservation
belonging to somebody else." `ResolveSetupIntent` and `ResumeCheckout` call it.
The Livewire component re-implements the same
`! $billable instanceof CentralUser || $pending->global_id !== $billable->global_id`
predicate at `src/Livewire/Billing/Checkout.php:214,301,333` — with a
different user-facing message (`session_expired` vs the action's
`foreign_session`).

Fix: one private `ownedReservation(): ?PendingTenantProvision` calling
`AssertReservationIsOwned::run()` and catching `CheckoutSessionExpired` into
`$paymentError`. The three sites collapse to one, and `pendingReservation()`
stops being re-queried 2–3× per request.

**Note:** this changes the message shown on two of the three paths. That is
probably the point, but it is a visible change — confirm before landing.

`.ai/rules/billing-checkout.md` already records this exact class of bug
happening twice ("Livewire wizard bypasses every rule in
`StartCheckoutRequest`", "two tenants, one payment").

Test: assert every public entry point on the component rejects a foreign
reservation. `tests/Feature/Livewire/Billing/CheckoutTest.php` covers current
behaviour but nothing pins the entry points together.

### 2.3 `instanceof CentralUser` at 17 sites vs the `CentralUserModel` interface at 5

`docs/extending.md` justifies keeping `CentralUserModel` as "the shape core's
own services accept so a host subclass satisfies them without extending a
package class." `UserModelResolver`, `RequiresAuthenticatedUser`,
`GetTenantsByGlobalId` and `EnsureTenantUserExists` type against it. Every
billing path instead narrows on the concrete class —
`Livewire/Billing/Checkout.php:108,110,187,206,301,333`,
`CompleteRedirectCheckout:46,54,64`, `CreateInlineSubscription:59`,
`InlineCheckoutGateway:43`, `AssertReservationIsOwned:30`.

A host model implementing the interface without extending — the case the
interface exists for — fails silently through the entire checkout with
"session expired".

Fix: narrow on `User&CentralUserModel`, or on `Subscribable` where the Stripe
methods are what is actually needed, and let `HostConfig`'s `is_a()` check be
the real contract.

Test: none exercises a non-subclass central user model, which is why this
reads as fine today. That test is the deliverable — write it first and watch
it fail.

Do 2.2 before 2.3; they touch the same lines in `Checkout.php`.

### 2.4 The provisioning step list has two calling conventions and no guard

`numerosis.tenancy.provisioning.steps[0]` is invoked
`::run($registration): Tenant` synchronously; every later entry is
`::run($tenant, $data): void` on the queue. `ProvisionTenant.php:237-244` takes
`[0]`; `RunProvisioningSteps.php:177-179` `array_shift`s.

`.ai/rules/tenant-provisioning.md` argues the split at length and the rationale
holds — step 0 is the one step with no `Tenant` to receive, and must run before
the chain lock. **This is not a finding against the design.** The finding is
that the contract is enforced nowhere: a host prepending its own step gets a
`TypeError` inside a queued job, five retries deep, far from the config it
edited.

Fix: a `CreatesTenant` marker interface, checked at boot.
`RegistrationWizardFeature::assertAStepProvidesTenantIdentity()` is the exact
precedent — same shape, same failure mode, already built. If Phase 5.2 moves
that assertion to console-only, move this one with it.

Test: configure a custom step list with a wrong-shaped step 0 and assert the
`LogicException` at boot.

---

## Phase 3 — depth

Two items where the code is at the wrong altitude. Both are real refactors.

### 3.1 `WebhookController` is 425 lines of domain orchestration in a controller

`.ai/rules/architecture-conventions.md`: "Business logic goes in `AsAction`
classes under `src/Actions/**`." This controller decides suspension policy
(`suspendUnlessStillEntitled:359`), computes plan-change direction against the
plan repository (`planChangeDirection:317`), reaches Stripe directly
(`Cashier::stripe()->setupIntents->retrieve:184`), matches payment methods to
open reservations (`:118-209`), and mutates `pending_tenant_provisions` rows
(`:399`). None of it is reachable from anywhere but an HTTP POST — not from a
console command, not from a reconciliation job, not from a test without going
through the full Cashier webhook stack.

Fix: thin handlers that resolve the payload into an event or DTO and hand off
to Actions. `SuspendUnlessEntitled`, `ResolvePlanChange`,
`SettleAttachedPaymentMethod` — the last already half-exists as
`finalizeIfPaymentMethodMatches`.

Sequencing: extract one handler at a time, keeping the existing webhook-level
tests green throughout, then add unit tests against the new Actions. The
existing coverage
(`tests/Feature/Http/Controllers/Billing/WebhookControllerSetupIntentTest.php`
and siblings) is at the request level, which is exactly why the depth problem
does not show — do **not** delete those tests as the Actions grow their own.

`.ai/rules/tenant-provisioning.md` documents races between the sync checkout
redirect and the async Stripe webhook. Read it before moving any of this;
several of the idempotency requirements it names are implemented inside the
methods being extracted.

### 3.2 The registration wizard's parent knows a child step's internals

`src/Livewire/Tenant/Registration.php:117` — `stateToPersist()` hardcodes
`Plan::class` and unsets five of its property names
(`checkoutClientSecret`, `checkoutPublishableKey`, `isSubmitting`,
`checkoutError`, `wizardCompleted`) before writing state to the session.

Which state is transient is the *step's* knowledge. The seam already exists
one file away: `ProvidesTenantIdentity::tenantIdentityStateKeys()` in
`TechnicalSetup:185`.

Fix: a `HasTransientState::transientStateKeys()` interface the parent asks each
step for.

Cost of not doing it: adding a public property to `Plan`, or a host replacing
the Plan step — which `numerosis.tenancy.registration.steps` explicitly invites
— silently persists a Stripe client secret into the session.

Test: `RegistrationRefreshTest` / `RegistrationCheckoutHandoffTest` touch
`checkoutClientSecret` but only for the shipped step list. Add a custom-step
case.

---

## Phase 4 — schema and seed hygiene

Land alone. Large, mechanical, and every item touches migrations that every
test run replays.

### 4.1 Squash the central migrations

62 files in `database/migrations/central/`, roughly half create-then-undo churn
inherited from the archived saas-m app:
`add_missing_fields_to_payment_plans_table`, `unfuck_payment_plans_and_features`,
`new_plan_tables`, `rename_id`, `rename`, `int`,
`add_is_popular` → `remove_is_popular`,
`add_billing_cycle` → `remove_billing_cycle`,
`create_features_table` → `update_payment_plan_features` → `remove_redundant_tables`,
`remove_morphs`, `change_subscribable_id_type_to_string`,
`add_subscribable_id_and_type` → `add_subscribable_to_subscriptions_table`.

No host of a freshly published package has any of the intermediate states.
Squash the pre-`2026_07` set into final-schema migrations.

Verification is direct and strong: `CleansUpTenancyDatabases` and every
`RefreshDatabase` test replays these, so `composer test` proves the squash.
Compare `schema:dump` output before and after as the primary check.

Note `.ai/rules/exception-handling.md`'s `failed_jobs` trap and
`.ai/rules/central-rows-on-tenant-routes.md` before touching anything under
`central/`.

### 4.2 Trim the seeded permission contexts that have no policy

Nine policies exist. Eight declare a context. The seeders write more than that.

Central (`database/seeders/RoleAndPermissionSeeder.php:52-62`) — no policy
reads `domains`, `memberships`, `subscription_items`, `payment_plan_features`.
That is 36 dead rows per install.

Tenant (`database/seeders/Tenant/PermissionAndRoleSeeder.php:50`) — `clients`
has no model, policy or route anywhere in the repo. Nine rows per tenant.

**This is not a blind delete.** `.ai/rules/package-boundaries.md` warns that a
*missing* context 500s a page. Audit whether each of the five wants a policy
instead of a deletion, and land the audit in the same commit. `memberships` in
particular is tied to 4.3.

### 4.3 Decide what `MembershipsFeature` is

`grep` finds it in exactly four places: its own class, `config/numerosis.php:106`,
`docs/features.md:32` (which claims it gates "the Team screens"), and one test's
feature list. There are no Team screens — `routes/tenant.php` registers only
invitations. It is a config entry whose only effect is a no-op `bootstrap()`.

Two honest options: delete it, or ship the screens it names. Breaking changes
are free here (no existing installs), so deletion is cheap — but it touches
`docs/features.md` and published config, and it interacts with the
`memberships` permission context in 4.2. Decide both together.

It also stands as the template the next feature gets copied from, which is the
real cost of leaving it.

---

## Phase 5 — efficiency, each needs a measurement first

None of these are urgent. Each wants a query-count or timing assertion written
*before* the fix, or the fix is unfalsifiable. The `DB::listen` harness at
`tests/Feature/Services/Billing/Plans/EloquentPaymentPlanRepositoryTest.php:41`
is the pattern to copy.

### 5.1 Two Stripe round-trips for the same customer

`src/Livewire/Billing/Checkout.php:111,131` — `FetchSavedBillingDetails`
(`src/Actions/Billing/FetchSavedBillingDetails.php:27`) and
`FetchReusablePaymentMethods` (`.../FetchReusablePaymentMethods.php:35`) each
call `customers->retrieve($stripeId)` independently, serially, in `mount()`.

Fix: retrieve once with `['expand' => [...]]` and pass the `Customer` in, or
give `FetchReusablePaymentMethods` an optional `?Customer $customer`.

Roughly 150–400 ms of wall clock on every checkout page load.

### 5.2 Three queries per plan card, one of them unconditionally wasted

`resources/views/components/billing/plan-card.blade.php:107,113,126` —
`availableFeatures()->take(6)->get()`, `availableFeatures()->count()`, and
`features()->get()`. The third runs even when the modal is never opened.
`EloquentPaymentPlanRepository::available()` (`.../EloquentPaymentPlanRepository.php:61`)
does not eager-load `features`, so nothing is shared.

Fix: `$plan->features` once per card, then `filter`/`take`/`count` in PHP. The
pivot `available` flag is already loaded.

12 queries for a 4-plan catalogue, re-run on every Livewire update of the
wizard's Plan step.

### 5.3 `GlobalCache` builds a fresh `CacheManager` per call

`src/Support/Cache/GlobalCache.php:30` — `app('globalCache')` is a stancl
`bind`, not a singleton, so every `store()` call constructs a new manager *and*
a new store/driver. The class docblock notes this; nothing mitigates it.

Callers are hot: `Authenticate` middleware → `canAccessTenant()` →
`GetTenantsByGlobalId` on every authenticated tenant request, plus
`FindUserByGlobalId`, `Tenant::primaryDomain()`, `PaymentPlanRepository::available()`.
A 5-tenant list page builds ~16 cache managers.

Fix: memoize the resolved `Repository`, or `$app->instance()` it once. Check
Octane safety and read `.ai/rules/tenant-caching.md` first — the un-prefixed
`global_cache()` behaviour is deliberate and load-bearing.

### 5.4 `Tenant::primaryDomain()` called three times per tenant per render

`src/Models/Central/Tenant.php:196` is globally cached but not memoized per
instance, and `resources/views/pages/tenant/⚡mine.blade.php` calls it three
times in the ready-tenants loop (subtitle, `@if`, `href`). On a cold cache it
is also an N+1: `refreshTenants()` eager-loads `subscriptions` but not
`domains`.

Fix: a `?Domain` instance memo, and add `'domains'` to the `with()`.

### 5.5 `GetTenantsByGlobalId` deserializes every tenant model to answer a `contains`

`src/Actions/Queries/GetTenantsByGlobalId.php:35`, reached from
`Authenticate::authenticate()` via `User::canAccessTenant()`
(`src/Models/User.php:59`) on every authenticated tenant request.

Fix: a per-request static memo keyed on `$globalId` (the value is already an
hour-TTL cache, so a request-lifetime memo adds no staleness), or cache the id
list rather than hydrated models. The latter also reduces exposure to
`.ai/rules/tenant-caching.md`'s `cache.serializable_classes` trap, which whole
cached models sit squarely inside.

### 5.6 `assertAStepProvidesTenantIdentity()` runs on every request

`src/Features/Tenancy/RegistrationWizardFeature.php:111` — `is_subclass_of()`
over four configured step classes, force-autoloading four Livewire
`StepComponent` subclasses and their parents, on requests that never touch
registration. `bootstrapFeatures()` (`src/NumerosisServiceProvider.php:269`)
additionally `class_exists()`s and `app()->make()`s all nine features per
request.

Fix: run the assertion under `runningInConsole()` only, or in
`numerosis:install`. The answer cannot change between requests.

**Not free:** `tests/Feature/Features/` covers the `LogicException` path and
would have to move with it. See 2.4 — if that adds a second boot-time
assertion, both should live wherever this one lands.

### 5.7 The HTTP kernel is instantiated in queue workers and Artisan

`src/Providers/TenancyServiceProvider.php:325` and
`src/NumerosisServiceProvider.php:491,602` each
`$this->app->make(Illuminate\Contracts\Http\Kernel::class)` during `boot()`.
Line 325 also makes eight separate `prependToMiddlewarePriority()` calls in a
loop.

Fix: guard with `! runningInConsole()`, or defer via
`callAfterResolving(Kernel::class, …)`.

**Read `.ai/rules/middleware-registration.md` first.** Middleware registrations
disappearing silently is a documented failure class in this package, and
`seedMiddlewareBaselineIfMissing()` genuinely needs the kernel. Verify against
`route:cache` and against `tests/Feature/FreshHostTest.php`. This is the
riskiest item in Phase 5 for the smallest win — consider dropping it.

### 5.8 The config array is rebuilt per request under `config:cache`

`src/NumerosisServiceProvider.php:136` — `require .../config/numerosis.php`
runs on every boot, including when the host has `config:cache`d, where the
cached `numerosis` key is already complete and `fillMissingKeys()` is a
guaranteed no-op. The require executes a 373-line array with 10 `env()` calls
plus `Domains::apexFromAppUrl()`, which reads unloaded `$_ENV` in that state.

Fix: `if (! $this->app->configurationIsCached()) { …fill… }`.

**Behaviour change:** a host that upgrades the package without re-running
`config:cache` stops picking up new default keys. That is standard Laravel
config-cache semantics, but it is a change. `.ai/rules/package-host-bootstrap.md`
documents two load-time invariants here — the require staying facade-free, and
`HostConfig::apply()` staying in the `booting()` callback. This does not
disturb either; confirm that still holds after the edit.

Nothing covers the cached path today
(`tests/Feature/NumerosisServiceProviderDefaultsTest.php` and `HostConfigTest`
cover the uncached one). Write that test first.

### 5.9 `InvitationPolicy` runs an `exists()` per row

`src/Policies/InvitationPolicy.php:54` — the fallback ownership check queries
per invitation, after two `hasPermissionTo()` calls that each hit spatie's
permission cache. N queries for an N-row list, on the non-admin path.

Fix: eager-load `invitedBy:id,global_id` on the listing query in
`resources/views/pages/tenant/⚡invitations.blade.php` and compare in PHP.

**`.ai/rules/central-rows-on-tenant-routes.md` applies.** The tenant-scope
check on line 46 is load-bearing and must survive any refactor here.

---

## Phase 6 — two hand-synced surfaces

`src/Commands/InstallNumerosisCommand.php:206-671` re-checks roughly the key
set that `src/Support/HostConfig.php` writes, through ~20 bespoke `verify*()`
methods that each re-encode the expected shape and the stock values in prose.
`HostConfig` writes ~20 keys through a declarative `applyWhileStock()` table
plus one-off methods.

A key added to `HostConfig` is silently unverified. A default changed in one
place produces a false failure in the other.

Fix: make each correction a row — `key`, stock values, value, remedy message —
and have both `apply()` and the doctor iterate the same table. The bespoke
`verify*` methods then shrink to the genuinely cross-key checks
(`verifyCentralMigrationCollisions`, `verifyPublishedAssetsMatchSource`).

`HostConfig::applied()` already exists and is the hook that makes a
"the doctor covers every key `HostConfig` writes" test trivial. Write that test
first — it is worth having even if the table refactor never happens.

`.ai/rules/package-boundaries.md` covers `HostConfig`'s preference/correction
split and its one deliberate asymmetry (the four tenancy model keys vs
`auth.providers.users.model`). Preserve that asymmetry; it is not an
oversight.

---

## Phase 7 — `packages/ui`

`packages/ui/resources/views/flux/icon/{book-open-text,chevrons-up-down,folder-git-2,layout-grid}.blade.php`
— four files, ~33 identical lines each (variant `match`, stroke-width `match`,
the full `<svg>` attribute block). Only the inner paths differ.

Fix: one `components/ui/lucide-icon.blade.php` taking the paths as its slot;
each icon file collapses to ~5 lines. ~120 duplicated lines today, growing
linearly with every icon added.

`tests/Feature/PackageBoundariesTest.php` constrains this package — no core
references, no `tenancy()`, no named `route()`. A purely presentational
wrapper satisfies all three. Nothing covers these files today.

`chevrons-up-down.blade.php` was separately flagged as an orphan (nothing
renders it). Confirm before deleting rather than folding it in.

---

## Phase 8 — documentation and rules that are now wrong

Not code. Each is a claim in a file an agent or teammate is told to trust,
which the tree contradicts. Cheap to fix, and each one currently costs
somebody a wrong assumption.

1. **Turnstile is a `require`, not a `suggest`.** Promoted in `1798bab`;
   `.ai/rules/optional-dependencies.md` records it as of 2026-09-05. Still
   described as optional and `class_exists()`-guarded in `docs/features.md:24`,
   `docs/architecture.md:41-47`, `docs/extending.md:243`,
   and in `.ai/rules/package-boundaries.md`. `docs/features.md:24` is now
   doubly stale: it names `isEnabled()`, which Phase 0 deleted.

2. **The "two alias registries that drift" trap is fixed.**
   `NumerosisServiceProvider::registerMiddleware()` iterates
   `Numerosis::middlewareAliases()` / `middlewareGroups()`; there is no second
   copy. `.ai/rules/middleware-registration.md` still frames it as a live
   hazard and its line references (`Numerosis.php:401-435`, provider
   `:467-486`) are stale. Rewrite it as "this is why it is structured this
   way" rather than deleting the file — the hazard is real if someone
   un-structures it.

3. **`.ai/rules/auth-login.md:86-94` records the `AuthGuardBootstrapper`
   singleton finding as open.** Phase 0 fixed it. Update or remove that bullet.

4. **`numerosis.social.providers` does not exist.** `composer.json:73`'s
   `socialiteproviders/zoho` suggest text and `config/numerosis.php:79`'s
   `SocialLoginFeature` comment both tell hosts to add an entry to it. It was
   replaced by `src/Enums/Auth/SocialProvider.php`, whose own docblock and
   `docs/features.md:25` say so. A host following the shipped config comment
   writes a key nothing reads and concludes their provider is broken. Point
   both at the enum.

5. **`.claude/plans/README.md`'s Live table is stale.** It says
   `config-consolidation.md` is unexecuted and "`config/numerosis/` still holds
   the partials". There is no `config/numerosis/` directory — `config/` holds
   one `numerosis.php`. Move that plan to `archive/` and correct the table.
   Add this file to the Live list in the same edit.

6. **`.ai/rules/index.md`'s preamble is load-bearing and `record-rule` discards
   it.** Already documented in the preamble itself. Nothing to fix now, but any
   phase above that calls `record-rule` must diff `index.md` afterwards — see
   the 2026-09-04 incident note.

---

## Deliberately not doing

Recorded so they are not re-litigated.

- **`ResolveCheckoutRegion` always returns `null`.** GeoIP was dropped in
  Phase 6 of `humming-nibbling-flame.md`. The action's docblock states the null
  contract, `ResolveCheckoutRegionTest` pins it, and
  `tests/Feature/Livewire/Billing/CheckoutTest.php:58` mocks a return so the
  ten unreachable `billing.payment_methods.regions` config entries stay green
  for a host that wires a lookup back in. Correct shape for a re-enable seam.
- **`InitializeTenancy` / `TenantRouteGuard` resolving `IdentificationMode::current()`
  at `handle()` time.** Looks like per-request re-resolution; is the documented
  fix for the `Numerosis::middleware()` facade-root crash.
  `.ai/rules/package-host-bootstrap.md`.
- **`HostConfig::apply()` running from `booting()` rather than `register()`.**
  Same rule file. Deliberate.
- **`NumerosisServiceProvider::fillMissingKeys()` hand-rolling the deep merge.**
  `array_replace_recursive` merges lists element-wise, which would corrupt
  `numerosis.features`.
- **Textual duplication between `database/migrations/central/` and
  `.../tenant/`.** Inherent to the two-schema design, not duplication to remove.
- **`src/Testing/FakeCheckoutGateway::assertCheckoutStarted()` being uncalled.**
  It is a testing-helper API for hosts.
- **`stancl/tenancy` v4.** Permanently off the table, not deferred.
  `.ai/rules/stancl-tenancy-v4.md`.

## Checked and clean

Stated so the next review does not re-audit them: model access all goes through
`Numerosis::model()` (no bare static `Model::where()` outside the seam);
`RouteNames::*` has no literal-string bypasses after Phase 0;
`global_cache()` / `CacheKeys` has no un-routed callers; the
`numerosis-pages::` views and `resources/views/auth/verify-email.blade.php`
look orphaned to a naive grep but are reached via `routes/web.php:118`,
`routes/tenant.php:70` and Fortify's `viewPrefix('numerosis::auth.')`.
