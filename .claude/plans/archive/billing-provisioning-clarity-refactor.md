# Clarify tenant provisioning & billing: named actions, real interfaces, DTOs, Requests

**Status: ✅ Executed.** Renames (`CreateTenant`→`CreateTenantWithOwner`,
`MakeFirstUserAdmin`→`FinalizeTenantProvisioning`, etc.), `TenantRegistrationData`/
`TenantProvisionData`, `TenantProvisionStatus` enum all landed — commits `de06293`,
`438f12f`; confirmed in `.claude/rules/testing.md` and `tenant-provisioning.md`.

## Context

Tenant provisioning and billing work correctly (the race conditions and idempotency
rules in `.claude/rules/tenant-provisioning.md` hold), but the code does not *say*
what it does. Reading it requires prior knowledge:

- **`CheckoutData` is not checkout data.** It is the tenant-registration payload,
  and it is what `CreateTenant`, `ProvisionTenant`, `ReconcileTenantSubscription`
  and the Stripe webhook all pass around. `app/Actions/Tenancy/CreateTenant.php:8`
  literally imports it `as SubscriptionInfo` to make the signature readable — proof
  the name is wrong.
- **`CreateStripeSubscription` creates nothing in Stripe.** It inserts a local
  `subscriptions` row (`app/Actions/Billing/Subscription/CreateStripeSubscription.php:26`).
- **The interfaces are decorative.** `CreatesSubscription::handle(Data $data)` and
  `CreatesTenant::handle(SubscriptionInfo $data)` are bound in
  `AppServiceProvider::register()` but nothing resolves them, and
  `CreateStripeSubscription` — the class bound to `CreatesSubscription` — does not
  implement it. `App\Contracts\Billing\CheckoutAction` declares only
  `failureRoute()`/`successRoute()`, which have zero callers; the
  `App\Actions\Billing\Checkout` abstract class exists solely to re-declare them.
- **Wide positional signatures.** `ProvisionTenant::handle()` takes 4 positional
  args with a `$userId ?? 0` sentinel; `ReconcileTenantSubscription::handle()` takes
  6, three nullable, and branches on their combination.
- **HTTP input is smuggled.** `ProcessSuccessfulCheckout` reads the Stripe session
  out of `$request->attributes->get('checkoutSession')`, a magic string written by
  `ValidateCheckout` middleware. Nothing types that hand-off.
- **`BillingService` is a grab bag** where 10 of 16 methods have no callers, and the
  ones about plans read from *both* `config('cashier.billables.tenant.plans')` and
  the `PaymentPlan` model inconsistently.

Outcome: the same behaviour, expressed as verb-named actions with one typed DTO in,
one typed thing out; interfaces only where a second implementation actually exists;
validation and authorization at the HTTP edge in Request classes.

**Non-goal:** changing provisioning semantics. Every lock, retry count, idempotency
guarantee and ordering constraint documented in `.claude/rules/tenant-provisioning.md`
must survive byte-for-byte in behaviour. Wire-format field names inside the
registration DTO stay identical so Stripe checkout sessions already in flight (whose
`metadata` carries `company_name` / `domain` / `global_id` / `payment_plan` /
`billing_cycle`) still parse after deploy.

---

## 1. DTOs (`app/Data/`)

| New | Replaces / purpose |
|---|---|
| `App\Data\Tenancy\TenantRegistrationData` | Renamed `CheckoutData`. **Field names unchanged.** Add `toStripeMetadata(): array` so the metadata contract lives in one place instead of `$data->toArray()` at two call sites. Drop `rules()` — validation moves to Requests (§3). |
| `App\Data\Tenancy\TenantProvisionData` | Single param for `ProvisionTenant`: `TenantRegistrationData $registration`, `?string $stripeCustomerId`, `?string $stripeSubscriptionId`, `?string $centralUserId`. Kills the `$userId ?? 0` sentinel — nullable means nullable. |
| `App\Data\Billing\CheckoutSessionData` | Typed replacement for `$request->attributes->get('checkoutSession')`: `sessionId`, `stripeCustomerId`, `?stripeSubscriptionId`, `TenantRegistrationData $registration`. Built by `CheckoutSuccessRequest`. |
| `App\Data\Billing\StripeSubscriptionData` | Mapped from `Stripe\Subscription` via `::fromStripe()`. Removes the `->items->data[0]->price->id` chains in `ReconcileTenantSubscription` and lets tests stop hand-building `\Stripe\Subscription` out of `(object)` casts (`tests/Feature/ReconcileTenantSubscriptionTest.php:62-75`). |

Tighten `SubscriptionData`: today every field is `?string ... = null`, so a malformed
row is a runtime surprise. Make `stripe_id`, `stripe_status`, `subscribable_id`,
`subscribable_type` required; keep `items` as `DataCollection<SubscriptionItemData>`.

Add `App\Enums\TenantProvisionStatus` (`Reserved`/`Provisioning`/`Failed`) and cast it
on `PendingTenantProvision`, replacing the `STATUS_*` string consts. Sibling precedent:
`App\Enums\BillingCycle`.

## 2. Contracts — the seams a package consumer will actually reach for

This code is headed for extraction as a package. That changes the test for "is this
interface worth having" from *does a second implementation exist in this repo today*
to *would an installing application have to fork the package to change this*. Several
things §5 previously proposed deleting fail that test — they are dead **here** because
this app is the only consumer, not because they are the wrong idea.

### 2.0 Two rules that apply to every contract below

- **A contract must never typehint an app model.** `findBySlug(string): ?PaymentPlan`
  nails every implementer to that Eloquent class, so a consumer whose plans live in
  Stripe or a config array cannot satisfy it. Introduce two thin interfaces —
  `App\Contracts\Billing\Plan` (`slug()`, `name()`, `priceId(BillingCycle): ?string`,
  `price(BillingCycle): ?float`, `trialDays(): ?int`, `metadata(): array`) and
  `App\Contracts\Billing\Subscribable` (already exists; keep) — and typehint *those*.
  `PaymentPlan` implements `Plan`; nothing else changes for this app. Same rule for
  `SubscriptionRepository`: return `Laravel\Cashier\Subscription`, the Cashier base
  class, not `App\Models\Central\Subscription`.
- **Every contract gets a closure hook as well as a binding** (§5.2). Writing a class
  plus a container binding is the right path for a consumer replacing real behaviour;
  it is far too much ceremony for "trial is 30 days for referrals". Both are cheap
  once the seam exists.

### 2.1 Delete (unchanged from before)

`App\Contracts\Tenancy\CreatesTenant`, `App\Contracts\Tenancy\CreatesSubscription`,
`App\Contracts\Billing\CheckoutAction`, and the `App\Actions\Billing\Checkout` abstract
class, plus their two dead bindings in `AppServiceProvider::register()`
(`app/Providers/AppServiceProvider.php:49-50`).

These die because they are the *wrong* seams, not because seams are unwanted:
`CreatesTenant::handle(Data): Tenant` is one step of provisioning with no useful
alternative implementation, and `failureRoute()`/`successRoute()` are configuration
(`billing.redirect.*`, §5.4) wearing an interface costume.

**Keep:** `Subscribable`, `HasTenants` — genuinely implemented by `Tenant` and `CentralUser`,
and both are exactly what a consumer's own models must satisfy.

### 2.2 Add — swap-worthy seams

Each is constructor-injected, never called statically, and bound from config (§5.1).

| Contract | Surface | Default impl | Why a consumer swaps it |
|---|---|---|---|
| `Billing\CheckoutGateway` | `start(TenantRegistrationData, ?string $successUrl, ?string $cancelUrl): Responsable` | `StripeCheckoutGateway`, `LocalCheckoutGateway` | Paddle/Lemon Squeezy/manual invoicing; two impls exist here today. |
| `Billing\PaymentPlanRepository` | `findBySlug(string): ?Plan`, `findByPriceId(string): ?Plan`, `available(): Collection<Plan>` | `EloquentPaymentPlanRepository` (default), `ConfigPaymentPlanRepository` (§5.5) | Plans in config, in Stripe, or per-tenant custom pricing. DB is the single source of truth **for the default impl only** — the fork this ends is config-vs-DB *inside one method*, not the ability to choose. |
| `Billing\SubscriptionRepository` | `findByStripeId(string): ?Subscription`, `record(SubscriptionData): Subscription` | `EloquentSubscriptionRepository` | Consumer subscription model / extra columns. |
| `Billing\BillableResolver` | `resolve(): (Model&Billable)\|null` | `TenantOrUserBillableResolver` | **Highest-value seam in the package.** `BillingService::resolveBillable()` hardcodes "tenancy initialised ? `tenant()` : authed central user". A consumer billing per-user, per-org, or per-workspace has to fork the package to change one ternary. |
| `Billing\PlanPolicy` | `assertEligible(Subscribable $for, Plan $plan): void`, `canSwap(Subscribable, Plan $from, Plan $to): bool` | `SeatLimitPlanPolicy` (the seat check from `checkPlanEligibility`) | Downgrade rules, feature-usage gates, grandfathering, per-customer overrides. **Reversal:** the old §5 deleted `checkPlanEligibility` as callerless. It is callerless because nothing wires it, not because nobody wants it — promote it and call it from `StartSubscriptionCheckout` and `SwapSubscriptionPlan`. |
| `Billing\TrialResolver` | `daysFor(Plan $plan, ?Subscribable $for): ?int` | `PlanOrDefaultTrialResolver` (`$plan->trial_days ?? config`) | "No second trial", referral extensions, sales-negotiated trials. Currently inline at `StripeCheckout:57-60`. |
| `Tenancy\TenantDomainPolicy` | `assertAvailable(string $domain): void` | `DefaultTenantDomainPolicy` (regex + reserved-word list + `domains`/`pending_tenant_provisions` lookup) | Reserved-subdomain blocklists (`www`, `admin`, `api`, brand terms) are per-application by definition. Today the rule is a bare regex in `CheckoutData::rules()` and a uniqueness check split across `TechnicalSetup` and `StripeCheckout::reserveDomain` — three places, no single answer to "is this domain allowed". |
| `Tenancy\ProvisionsTenant` | `provision(TenantProvisionData): Tenant` | `ProvisionTenant` | Consumers who provision onto separate DB servers/regions. The webhook and checkout-success handler depend on this, not the concrete action. |
| `Billing\MoneyFormatter` | `format(float\|int $amount, ?string $currency = null): string` | `CashierMoneyFormatter` | Currency/locale/zero-decimal handling. Cheap, and it gives `PaymentPlan::formatCurrency()` a real home instead of the broken `getFacadeRoot()->currency()` path (§5.3). |

### 2.3 The provisioning pipeline is the other big one

A consumer's first customisation is almost always "when a tenant is provisioned, also
do X" — seed demo data, create a default workspace, provision a subdomain cert, call
their CRM. Today that requires editing `CreateTenant`'s body.

Expose an ordered, config-declared step list, mirroring the existing
`TenancyServiceProvider::$tenantCreatedJobs` precedent:

```
'provisioning' => [
    'steps' => [
        \App\Actions\Tenancy\CreateTenantWithOwner::class,
        // consumers append their own here
    ],
],
```

Each step is `__invoke(Tenant $tenant, TenantProvisionData $data): void`.
`ProvisionTenant` runs them in order inside the existing
`Cache::lock("tenant-provision:{$domain}")`.

**Two constraints the implementation must enforce, not merely document:**

1. `FinalizeTenantProvisioning` is *appended* by `ProvisionTenant` after the configured
   steps — it must not be placeable in the array. It is the sole emitter of the
   finished signal (`provisioned_at`, pending-row deletion, `TenantProvisioned`
   broadcast); a consumer who reorders it above their own step ships a UI that says
   "ready" before the tenant is.
2. Steps must be idempotent, because the whole list re-runs on retry
   (`$jobTries = 5`). Say so in the config comment and in the package README — this is
   the same invariant `.claude/rules/tenant-provisioning.md` already imposes on
   `CreateTenantDomain`/`AddTenantOwner`.

Failure of any step falls through to the existing `jobFailed` handling; no new
error path.

### 2.4 Explicitly *not* interfaced

Listing these so the next pass does not "helpfully" add them:

- `CreateTenantWithOwner`, `ReserveTenantDomain`, `MarkProvisionInProgress`,
  `MarkProvisionFailed`, `MarkTenantProvisioned`, `PromoteFirstUserToAdmin` — internals.
  §2.3's pipeline is how a consumer extends provisioning; interfacing each step
  multiplies the surface without adding capability.
- DTOs. `spatie/laravel-data` objects are the wire format; a consumer needing extra
  fields adds them to `metadata`, and the `toStripeMetadata()` contract (§1) is what
  keeps that stable.
- Request classes. Consumers override by binding their own subclass through
  `app()->bind()`, per normal Laravel.
- Webhook handling. `WebhookController` is already extended-by-subclass with the route
  class read from config (§5.4) — an interface adds nothing over that.
- Events. `TenantProvisioned` / `TenantProvisioningFailed` already exist and are the
  idiomatic hook for "notify me". Do not wrap them in a `ProvisioningNotifier` contract.

## 3. Requests at the HTTP edge (`app/Http/Requests/Billing/`)

- **`StartCheckoutRequest`** — owns what `CheckoutData::rules()` held today, except the
  bare `domain` regex, which becomes a rule delegating to `TenantDomainPolicy` (§2.2) so
  the Request and `ReserveTenantDomain` cannot disagree. Plus an
  `authorize()` that asserts `global_id === $request->user()->global_id`.
  **This closes a real hole:** the start-checkout route currently accepts any
  `global_id` in the query string, so one user can reserve a domain in another user's
  name (`StripeCheckout::reserveDomain`). The success handler already checks this
  (`ValidateCheckout:54-57`); the start handler does not.
  Exposes `toRegistrationData(): TenantRegistrationData`.
- **`CheckoutSuccessRequest`** — absorbs `App\Http\Middleware\ValidateCheckout`
  wholesale: `session_id` present → retrievable (catching `ApiErrorException`) →
  `payment_status === 'paid'` → metadata `global_id` matches the authenticated user
  (fails closed, 403). Exposes `toCheckoutSession(): CheckoutSessionData`.
  `ValidateCheckout` and its `getControllerMiddleware()` registration are then deleted.
  **Security-sensitive: port `tests/Feature/Http/Middleware/ValidateCheckoutTest.php`
  case-for-case onto the Request rather than deleting it.**

Actions never receive a `Request`. `asController()` accepts the Request, calls
`->toX()`, hands the DTO to `handle()`.

## 4. Renamed / new actions

### `app/Actions/Billing/Checkout/`
| New | Was |
|---|---|
| `StartSubscriptionCheckout` | `Subscription\StripeCheckout` — now thin: `StartCheckoutRequest` → `PlanPolicy::assertEligible()` → `ReserveTenantDomain` → `CheckoutGateway::start()`. Plan/price lookup moves into `StripeCheckoutGateway` (via `PaymentPlanRepository`); the `trial_days ?? config` line at `StripeCheckout:57-60` becomes `TrialResolver::daysFor()`. |
| `StartLocalCheckout` | `Subscription\DevCheckout` — keeps the `app()->isLocal()` route guard in `routes/web.php:51`. |
| `CompleteCheckout` | `Subscription\ProcessSuccessfulCheckout` |
| `CancelCheckout` | `Subscription\ProcessFailedCheckout` |

### `app/Actions/Billing/Subscriptions/`
| New | Was / purpose |
|---|---|
| `RecordSubscription` | `CreateStripeSubscription` — writes the local row; name now says so. Delegates to `SubscriptionRepository`. |
| `LinkSubscriptionToTenant` | `ReconcileTenantSubscription` — one `TenantProvisionData` + `StripeSubscriptionData` param pair instead of 6 positionals. Keeps `Cache::lock("reconcile-subscription:{$id}")` exactly as-is. |
| `SwapSubscriptionPlan` | Extracted from `Billing::handlePlanSwap()` (`app/Filament/TenantAdmin/Pages/Billing.php:172`). |
| `StartPlanChangeCheckout` | Extracted from `Billing::redirectToCheckout()` (same file, :193). |

`CreateMockSubscription` moves out of `app/` into `tests/Support/` — it builds rows
from factories and is used only by `tests/Feature/CreateTenantTest.php` and
`tests/Feature/InterviewShowcaseTest.php`.

### `app/Actions/Tenancy/`
| New | Was / purpose |
|---|---|
| `ProvisionTenant` | Name kept (it is accurate). Signature becomes `handle(TenantProvisionData)`; `getJobUniqueId`/`jobFailed` updated to match. `$jobTries`, `$jobBackoff`, `$jobUniqueFor` **unchanged**. |
| `CreateTenantWithOwner` | `CreateTenant` — says what it does versus `ProvisionTenant`. Body, `Cache::lock("tenant-provision:{$domain}")` and the `find() ?? forceCreate()` idempotency are untouched. |
| `ReserveTenantDomain` | Extracted from `StripeCheckout::reserveDomain()`; writes status `Reserved` and enforces the "already claimed by someone else" guard. |
| `MarkProvisionInProgress` | The identical `updateOrCreate(... STATUS_PROVISIONING, failed_at: null, error: null)` currently copy-pasted into `DevCheckout:31` and `ProcessSuccessfulCheckout:43`. |
| `MarkProvisionFailed` | The identical failure write in `ProvisionTenant::jobFailed:71` and `MakeFirstUserAdmin::failed:78`. |
| `MarkTenantProvisioned` | The success signal (`provisioned_at`, pending-row deletion, `TenantProvisioned` broadcast) currently inlined in `MakeFirstUserAdmin`. |
| `PromoteFirstUserToAdmin` | The actual promotion logic from `MakeFirstUserAdmin::handle()`. |

`app/Jobs/MakeFirstUserAdmin.php` → `app/Jobs/FinalizeTenantProvisioning.php`: **still one
job**, still dispatched as the last statement of `CreateTenantWithOwner`, still
`$tries = 20` / `$backoff = 3`, still the sole emitter of the finished signal. It just
calls `PromoteFirstUserToAdmin` then `MarkTenantProvisioned`. Splitting it into two jobs
is explicitly *not* on the table — `.claude/rules/tenant-provisioning.md` explains why.

## 5. The public API: provider, manager, facade

The old version of this section was pure subtraction — prune `BillingService` to six
methods, fix the `@method` block. That leaves an installing developer with a facade
whose entire surface is internal plumbing (`configureCheckout`, `subscriptionModel`).
Keep every deletion of a *broken or duplicated implementation*; change what the
survivors are for. `BillingService` becomes a thin manager delegating to the §2
contracts — the one class a consumer types when they want something from this package.

### 5.1 `BillingServiceProvider` — wire from config, not from code

Removals stand: drop the empty `__construct` (project PHP rules forbid it), the dead
`'billing'` string singleton (the facade accessor is `BillingService::class`, so the
string is never resolved), and the unused static `authorizeBilling()` — an
authorization rule with no callers that belongs in a policy anyway.

Additions, all of them things a consumer expects from a package provider:

- **`register()` binds every §2 contract from config**, so swapping an implementation is
  one line in the published config file rather than a hunt through providers:

  ```php
  foreach (config('billing.implementations') as $contract => $concrete) {
      $this->app->singleton($contract, $concrete);
  }
  ```

  With `PlanPolicy`, `TrialResolver`, `BillableResolver` and `MoneyFormatter` checking
  their closure hook (§5.2) first, a consumer has a one-liner *and* a class path.
- **`mergeConfigFrom` + `publishes()` groups**: `billing-config`, `billing-migrations`,
  `billing-seeders` (the `PaymentPlanSeeder` stub), `billing-views`. Nothing to publish
  today means nothing installable tomorrow.
- **Cashier model wiring moves to config.** `boot()` currently hardcodes
  `Cashier::useCustomerModel(Tenant::class)` and the two subscription models. Read them
  from `billing.models.*` so a consumer's `Tenant` subclass wins without patching the
  provider.
- **`SyncTenantToStripe` becomes opt-out** (`billing.sync.stripe_customer`, default
  `true`). A consumer with their own `TenantSaved` listener currently gets a duplicate
  Stripe write with no way to stop it short of removing the provider.
- **`AboutCommand::add('Billing', ...)`** listing the resolved gateway, plan source and
  billable model. Cheap, and it is the first thing anyone runs when the package
  misbehaves.
- Register the package's artisan commands here (`tenancy:prune-stalled-provisions`,
  `tenancy:prune-orphaned-databases`) rather than relying on app-level auto-discovery,
  which a package does not get.

### 5.2 `BillingService` — a manager, not a grab bag

Still delete the broken/duplicated implementations: `checkPriceId`, `getPlanBySlug`,
`getPriceIdFromPlanSlug`, `getPlanByPriceId`, `getPlanSlugs`, `getBillingCycle`,
`getPriceIdForBillingCycle`, `subscriptionItemModel`. Every one of them either reads
the config plan array that `EloquentPaymentPlanRepository` replaces, or duplicates
`BillingCycle`/`PaymentPlan` methods that already exist.

What replaces them is a *delegating* surface — one-liners over the §2 contracts, which
is what makes the facade worth having:

| Method | Delegates to |
|---|---|
| `plans(): Collection<Plan>` | `PaymentPlanRepository::available()` |
| `plan(string $slug): ?Plan` | `PaymentPlanRepository::findBySlug()` |
| `planForPrice(string $priceId): ?Plan` | `PaymentPlanRepository::findByPriceId()` |
| `checkout(TenantRegistrationData, ?string, ?string): Responsable` | `CheckoutGateway::start()` |
| `provision(TenantProvisionData): Tenant` | `ProvisionsTenant::provision()` |
| `billable(): (Model&Billable)\|null` | `BillableResolver::resolve()` — replaces `resolveBillable()` |
| `trialDaysFor(Plan, ?Subscribable): ?int` | `TrialResolver::daysFor()` |
| `formatAmount(float\|int, ?string): string` | `MoneyFormatter::format()` |
| `currency(): string` | `config('cashier.currency')` — the method the facade always advertised and never had (§5.3) |

`configureCheckout`, `defaultSuccessUrl`, `defaultCancelUrl` survive but move into
`StripeCheckoutGateway`, which is their only caller once §4 lands; they are gateway
detail, not public API.

**Closure hooks** (Cashier's own pattern), as statics on `BillingService`, checked
before the container binding:

```php
Billing::resolveBillableUsing(fn () => Team::current());
Billing::resolveTrialUsing(fn (Plan $plan, ?Subscribable $for) => $for?->hasTrialed() ? 0 : 30);
Billing::authorizePlanChangeUsing(fn (Subscribable $s, Plan $from, Plan $to) => …);
Billing::formatAmountUsing(fn (int $cents, string $currency) => …);
```

Plus the model setters, mirroring `Cashier::useCustomerModel()`:
`useTenantModel()`, `usePlanModel()`, `useSubscriptionModel()`.

And `Billing::fake()` — swaps the gateway for a recording `FakeCheckoutGateway` and
`ProvisionsTenant` for a no-op, with `assertCheckoutStarted()` /
`assertTenantProvisioned()`. Consumers expect a fake from anything that talks to
Stripe; this repo's own tests are the first customer, which is also how the fake stays
honest.

Keep the class `readonly` (statics are unaffected). Rename to `BillingManager` is
tempting and *not* worth the churn — note it as a follow-up.

### 5.3 `app/Facades/Billing.php`

- Rewrite the `@method` block against §5.2. It currently advertises
  `getPriceIdByForBillingCycle` — a method that has never existed — and omits several
  that do.
- Delete the instance method `currency()`: it is unreachable through `__callStatic`,
  and `PaymentPlan::formatCurrency()` (`app/Models/Central/PaymentPlan.php:102`) calls
  it via `getFacadeRoot()`, which resolves `BillingService` — a class with no
  `currency()`. That path throws `BadMethodCallException`. Nothing calls it today.
- **Changed from the previous plan:** do *not* also delete `PaymentPlan::formatCurrency()`.
  Point it at `Billing::formatAmount()`, which now exists and is contract-backed
  (`MoneyFormatter`). Deleting the only "render a price" helper on the plan model in a
  package that sells plans is a subtraction the consumer immediately re-adds.

`app/Models/Central/Tenant.php:127` `billingPlan()` — still delete. Dead, and it reads
the config plan array; it is the last config-side reader outside
`database/seeders/PaymentPlanSeeder.php`, which legitimately seeds from
`cashier.stripe_price_ids`.

### 5.4 Package-extraction prerequisites (do them now, while this is already open)

- **The package needs its own config file** — see §5.5, which is small enough to do in
  full during this pass.
- **No contract may reference `App\Models\*`** — enforced by §2.0, and worth an
  `arch()` test (`tests/Feature/ArchTest.php` if one exists, else new): everything in
  `app/Contracts/` depends only on other contracts, DTOs, enums and framework classes.
  That single test is what stops the boundary rotting between now and extraction.
- **Route names become config**, not hardcoded `route('billing')` /
  `route('tenants.mine')` inside actions. `billing.redirect.*` already half-exists in
  `cashier.redirect.*`; finish the job. This is what the deleted
  `CheckoutAction::failureRoute()/successRoute()` was groping for.

### 5.5 Extracting the config out of `cashier.php`

Scoped it before planning it. `config/cashier.php` is 347 lines, of which roughly 250
are a custom appendix bolted onto Cashier's published file (`billables`, `features`,
`brand`, `terms_url`, `billing_path`, `billing_webhook_path`, `redirect`, `per_seat`,
`stripe_price_ids`). **Almost none of it is read.** Complete list of app-code readers,
and what the rest of this plan already does to each:

| Key | Readers | Fate |
|---|---|---|
| `billables.tenant.plans` | `BillingService:68,101,132`; `Tenant.php:140` | All four call sites are already deleted by §5.2 / §5.3. |
| `stripe_price_ids` | `BillingService:229-230` | Deleted with `getPriceIdForBillingCycle`. |
| `stripe_price_ids.{plan}.{cycle}` | `PaymentPlanSeeder:40-67` | Seeder — repointed, see below. |
| `billables.tenant.trial_days` | `StripeCheckout:62` | Becomes `TrialResolver` (§2.2). |
| `redirect.success` / `redirect.cancel` | `BillingService:211,216` | Moves with `defaultSuccessUrl`/`defaultCancelUrl` into `StripeCheckoutGateway`. |
| `features.eu_vat_collection` | `BillingService::configureCheckout` | Moves with it into the gateway. |
| `billing_webhook_path` | `routes/web.php` | One-line change. |
| `brand`, `terms_url`, `per_seat`, `billing_path`, `features.*` (bar EU VAT) | **nothing** | Delete. `per_seat` is entirely commented-out stubs. |
| `currency`, `payment_notification`, `key`, `secret`, `webhook.*`, `invoices.*`, `logger`, `path` | Cashier itself | Stay. `cashier.php` returns to stock. |

So the actual mechanical work is **five call-site edits plus the seeder** — and four of
the five are inside classes this refactor is rewriting anyway. Sequence it as step 2 of
the execution order and each later step lands on the final key names first time.

**Turn the dead plans array into the second `PaymentPlanRepository`.** The ~130-line
`billables.tenant.plans` block is about to have zero readers. Rather than delete it,
move it to `billing.plans` and back it with a `ConfigPaymentPlanRepository`. Three
things fall out of that:

- §2.2 claimed `PaymentPlanRepository` was swap-worthy while shipping one
  implementation. This makes the second one real, at the cost of a class that is mostly
  `array_first`.
- A consumer installing the package gets a working plan list with no migration and no
  seeder — the quickstart path every billing package needs.
- It settles the config-vs-DB fork honestly. The fork worth ending is
  `getPlanByPriceId()` consulting **both** sources within one method and silently
  preferring whichever is non-empty. Choosing a repository per application is not that
  fork; `billing.implementations` names the default as `EloquentPaymentPlanRepository`
  and this app keeps its current behaviour.

**Collapse the price-id duplication while moving.** `plans[].monthly_id` and
`stripe_price_ids.{plan}.{cycle}` are the *same env vars* in two shapes
(`STRIPE_STARTER_MONTHLY_PLAN` appears in both), and `payment_plans` rows are a third
copy. Keep the `plans[]` shape only; repoint `PaymentPlanSeeder` at `billing.plans` and
delete `stripe_price_ids` outright. The seeder then reads the same array the config
repository does, so "seeded DB" and "config-only" cannot drift apart — today they can,
and nothing detects it.

**No back-compat shim.** This repo is the sole consumer; nothing external reads
`cashier.billables.*`. Move the keys and delete the old ones in the same commit. (If
extraction ever happens *after* a second app adopts the current shape, that is the point
to add a one-release fallback in `mergeConfigFrom` — not now, when it would be a
deprecation path for zero users.)

**Bonus worth stating:** `config/cashier.php` returning to stock means future
`laravel/cashier` upgrades produce a readable diff. Right now the custom appendix makes
`vendor:publish --force` unusable and any upstream config change invisible.

Guard against regression with a one-line `arch()` assertion: no file under `app/` or
`database/` references `cashier.billables`, `cashier.stripe_price_ids`,
`cashier.redirect`, `cashier.features` or `cashier.brand`.

## 6. Call-site updates

`routes/web.php` (4 route actions), `app/Http/Controllers/Billing/WebhookController.php`
(builds `TenantRegistrationData` + `TenantProvisionData`, depends on `ProvisionsTenant`),
`app/Livewire/Tenant/Registration/Steps/Plan.php:80` (redirect payload unchanged — it is
query-string keyed, and the keys are not changing),
`app/Filament/TenantAdmin/Pages/Billing.php` (delegates to the two new actions; keeps
`Notification::make()` and the `close-modal` dispatch in the page, where UI belongs).

`.claude/rules/tenant-provisioning.md` and `.claude/rules/testing.md` reference
`CreateTenant`, `MakeFirstUserAdmin`, `ProvisionTenant` and `STATUS_*` by name —
update them in the same commit, or the rules start lying.

## Execution order

1. DTOs + `TenantProvisionStatus` enum (additive, nothing breaks).
2. `config/billing.php` (§5.5): create it, move the six live keys, delete the dead
   appendix, repoint `PaymentPlanSeeder`, restore `config/cashier.php` to stock. Five
   call-site edits. Doing it first means steps 3-7 bind and read from their final home
   instead of being rewired twice.
3. Contracts (§2), including the `Plan` interface on `PaymentPlan`, plus the default
   implementations and the config-driven bindings; delete the four dead contracts.
4. Tenancy actions: extract the pending-provision writes, rename `CreateTenant`,
   split `MakeFirstUserAdmin` internals, re-signature `ProvisionTenant`, introduce the
   configurable provisioning step list (§2.3).
5. Billing: gateways, `RecordSubscription`, `LinkSubscriptionToTenant`;
   `TrialResolver` and `PlanPolicy` called from `StartSubscriptionCheckout`.
6. Requests; delete `ValidateCheckout` middleware; `TenantDomainPolicy` becomes the one
   answer to "is this domain allowed", called from the Request and `ReserveTenantDomain`.
7. Filament page extraction (`SwapSubscriptionPlan` calls `PlanPolicy::canSwap()`);
   `BillingService` → manager (§5.2), facade rewrite, provider rewire (§5.1).
8. `Billing::fake()` + the `arch()` contract-boundary test.
9. Rules-file updates.

## Verification during implementation

Tests are **out of scope for this pass** (see the deferred section below). Checks that
still run as we go:

- `vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G` after each step.
  `app/` is at level 8 and must stay there (commit `15ea4cf`); PHPStan is the main
  safety net here, since renamed classes and re-signatured `handle()` methods surface
  as type errors at every call site.
- `vendor/bin/sail bin pint --dirty --format agent` before finishing.
- The test suite will not compile between steps 4 and 7 (test files still reference
  `CreateTenant`, `MakeFirstUserAdmin`, `CheckoutData`, `ValidateCheckout`). That is
  expected and gets resolved in the deferred pass.

---

## Deferred: test steps (separate pass, after the refactor lands)

Nothing below changes what is asserted — only which class and signature is asserted
against. If a rewritten test needs a *weaker* assertion to pass, that is a refactor
bug, not a test that needs relaxing.

**1. Rename-only updates** (imports + call signatures, assertions untouched):

| File | Change |
|---|---|
| `tests/Feature/Actions/Tenancy/ProvisionTenantTest.php` | `CheckoutData` → `TenantRegistrationData`; `ProvisionTenant::run($data)` → `run(new TenantProvisionData(...))`; `jobFailed(Throwable, TenantProvisionData)`. |
| `tests/Feature/Actions/Tenancy/TenantProvisioningSignalTest.php` | `MakeFirstUserAdmin` → `FinalizeTenantProvisioning`; `STATUS_*` → `TenantProvisionStatus` cases. |
| `tests/Feature/CreateTenantTest.php` | `CreateTenant` → `CreateTenantWithOwner`; `CreateMockSubscription` import moves to `Tests\Support\`. |
| `tests/Feature/InterviewShowcaseTest.php` | Same `CreateMockSubscription` import move. |
| `tests/Feature/Actions/Billing/Subscription/*Test.php` | Move alongside the actions: `StripeCheckoutTest` → `StartSubscriptionCheckoutTest`, `DevCheckoutTest` → `StartLocalCheckoutTest`, `ProcessFailedCheckoutTest` → `CancelCheckoutTest`, `CreateSubscriptionTest` → `RecordSubscriptionTest`. |
| `tests/Feature/Filament/TenantAdmin/Pages/BillingTest.php`, `BillingPlanVisibilityTest.php` | Page-level assertions stay; plan-swap assertions become `SwapSubscriptionPlan::shouldRun()`. |
| `tests/Feature/BillingServiceTest.php` | Drop coverage of the deleted `BillingService` methods. |

**2. `tests/Feature/ReconcileTenantSubscriptionTest.php` → `LinkSubscriptionToTenantTest.php`.**
Both existing cases (transfer-existing, create-if-missing) carry over. The hand-built
`\Stripe\Subscription` with `(object)` casts at :62-75 is replaced by
`StripeSubscriptionData::from([...])` — a plain DTO, no Stripe SDK shape guessing.

**3. `tests/Feature/Http/Middleware/ValidateCheckoutTest.php` → `tests/Feature/Http/Requests/Billing/CheckoutSuccessRequestTest.php`.**
Security-sensitive; port case-for-case, do not delete: missing `session_id` → redirect
with error; `ApiErrorException` on retrieve → redirect with error; `payment_status !== 'paid'`
→ redirect with error; metadata `global_id` mismatch → 403; metadata absent → 403 (fails
closed); happy path → `CheckoutSessionData` populated.

**4. New coverage the refactor makes worth having:**
- `StartCheckoutRequest` authorization: a request whose `global_id` belongs to another
  user is rejected. **This is a new guard, not a port** — the current start-checkout
  route accepts any `global_id`, so this test fails against `master` by design.
- `CheckoutGateway` swap: binding `LocalCheckoutGateway` makes
  `StartSubscriptionCheckout` provision without touching Stripe — proves the seam is
  real rather than decorative.
- `PaymentPlanRepository`: `findByPriceId()` resolves monthly and yearly ids, and
  returns `null` for an unknown price, now that the config fallback is gone.
- `ReserveTenantDomain` / `MarkProvisionInProgress` / `MarkProvisionFailed` unit tests,
  asserting the status transitions that were previously implicit in three copy-pasted
  `updateOrCreate` calls.

**4b. Seam tests — these are what make the package claim true.** Each asserts that a
consumer-supplied replacement actually wins, since a binding nothing resolves is exactly
the failure mode this whole refactor exists to fix (`CreatesTenant`, `CreatesSubscription`):

- `BillableResolver`: a custom implementation bound in the test makes
  `Billing::billable()` return that model, inside *and* outside tenant context.
- `PlanPolicy`: a policy that rejects makes `SwapSubscriptionPlan` throw and leaves
  the subscription's `stripe_price` untouched — plus the ported seat-limit case, which
  is the first real coverage `checkPlanEligibility` has ever had.
- `TrialResolver`: `Billing::resolveTrialUsing(fn () => 0)` produces a checkout with no
  trial even for a plan carrying `trial_days`. Covers the closure-hook-beats-binding
  precedence rule from §2.0.
- `TenantDomainPolicy`: a reserved word (`www`) is rejected by `StartCheckoutRequest`
  *and* by `ReserveTenantDomain` — one policy, both call sites.
- Provisioning pipeline: a test-registered step runs, receives the `Tenant` and
  `TenantProvisionData`, and runs **before** `FinalizeTenantProvisioning` stamps
  `provisioned_at`. Assert the ordering explicitly — §2.3's constraint 1 is the one a
  future refactor is most likely to break silently.
- `Billing::fake()`: `assertCheckoutStarted()` / `assertTenantProvisioned()` behave, and
  no Stripe call is made. Convert at least one existing checkout test to use it, or the
  fake ships untested.
- `arch()`: nothing in `app/Contracts/` references `App\Models\*` (§5.4).

Use `lorisleiva` action fakes for wiring assertions (`::shouldRun()`, `::shouldNotRun()`)
and direct `handle()` calls for business rules, per `.claude/skills/laravel-actions`.

**5. Gates for that pass:**
- `vendor/bin/sail artisan test --compact --filter=<Name>` per file while iterating (~5s each).
- Full suite via `vendor/bin/sail composer test` (parallel, ~1 min). Baseline against
  `git stash` first: `tests/Feature/Filament` has 20 known pre-existing failures and
  `tests/Feature/Chat/ChannelTest` is independently broken, per `.claude/rules/testing.md`.

**6. Manual end-to-end, once tests are green:**
- `vendor/bin/sail up -d`, walk `/get-started`, then use the `app()->isLocal()` dev
  route `/checkout/subscription/dev`. Confirm `tenants.mine` shows the spinner and
  flips to a visitable tenant — that transition rides on `provisioned_at` plus the
  `TenantProvisioned` broadcast, exactly the signal path step 3 refactors.
- `stripe listen --forward-to <app>/billing/webhook` with a test card, closing the tab
  on Stripe's page so the browser redirect never fires. `customer.subscription.created`
  must still provision — the case `ProvisionTenant`'s `ShouldBeUnique` keying protects.
