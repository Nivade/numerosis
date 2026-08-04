# Plan: purchasable tenant modules + reusable checkout

**Status: ✅ Executed.** Commits `549223e`, `5a1496b`, `b65d920`, `949d6c6`
match this plan directly — real Stripe-billed modules, `MigrateTenantModule`
rewritten, reusable checkout extracted. See
`.claude/rules/module-marketplace.md` and `billing-checkout.md` ("Known gap —
fixed").

## Context

The marketplace is a stub. `Marketplace::purchaseAction()` sets `purchased_at`
and `enabled = true` and charges nothing; there is no price anywhere in the
system. The only module that exists, `app-modules/alerts`, is empty
scaffolding. So "modules" today is a boolean flag with no billing, no schema,
and no feature behind it.

The goal is a marketplace a tenant can buy from: a central, admin-editable
catalogue with real Stripe prices; purchases billed as add-ons on the tenant's
existing subscription, or as a one-off, per module; module migrations that
actually run; Filament plugins registered only when the tenant has the module
enabled; and four modules that do something.

That needs the payment plumbing only the registration wizard has today — the
3DS `requires-action` round trip, the settled allowlist. It is extracted into a
shared component and a trait, which also gives checkout its own route and so
makes it resumable after a refresh (`Payment::mount()` currently bounces back
to the Plan step because the client secret lives only in wizard state).

Decisions taken with the user, in order: per-module billing mode (recurring or
one-time); shared checkout component the wizard embeds, plus its own route;
central `modules` catalogue edited in the central admin panel; four modules —
Tasks, Notes, Announcements, Branding; **automatic tax on, with a billing
address required and a VAT number optional at checkout**; recurring modules
must carry both billing
cycles; module permissions seeded by a seeder, not a migration; 3DS on the
marketplace handled by a small Alpine confirm handler.

The project is not live. Tenants that predate the address requirement are
deleted (`tenants:delete`), not migrated — so there is no backfill path and no
mixed-cohort tax behaviour anywhere in this plan.

---

## Two findings that change the shape of the work

Both were verified against `vendor/internachi/modular` during planning, and
both mean the "known gap" recorded in `.claude/rules/billing-checkout.md` is
worse than a missing caller.

**1. The commands `MigrateModules` and `RollbackModules` call do not exist.**
internachi/modular v3 registers the make-commands and a `db:seed` override
(`ModularizedCommandsServiceProvider:37-59`) and nothing else — there is no
`module:migrate` and no `module:migrate-rollback` anywhere in vendor. So
`MigrateModules` → `tenants:migrate-module` → `$this->call('module:migrate')`
raises `CommandNotFoundException`, and `RollbackModules` fails the same way.
The one path that works is `RollbackTenantModule`, which calls core
`migrate:rollback --path=<module>/database/migrations/tenant --realpath`
inside `$tenant->run()` — and nothing calls it.

`MigrateTenantModule` is therefore rewritten in that image (core `migrate`,
explicit `--path`, `--realpath`, inside `$tenant->run()`), and `RollbackModules`
is pointed at `tenants:rollback-module`. The exit-code checks both jobs already
make are correct and stay — see `.claude/rules/exception-handling.md`.

**2. A module migration under `database/migrations/` runs against the central
database.** `MigratorPlugin` hands every `app-modules/*/database/migrations`
directory to the global `Migrator`, and `Migrator::getMigrationFiles()` globs
`*_*.php` non-recursively — so `database/migrations/tenant/` is invisible to
it. That is precisely why the `tenant` subpath convention exists, and
`app-modules/alerts/database/migrations/2026_01_13_035241_set_up_alerts_module.php`
is in the wrong one, i.e. it runs on `artisan migrate` against central.
Fix it in passing; every new module puts tenant schema in
`database/migrations/tenant/` only.

---

## Architecture

### Two tables, two responsibilities

| | table | owns |
|---|---|---|
| central | `modules` | the catalogue: name, description, Stripe price ids, prices, billing mode, `available` |
| tenant | `modules` (exists) | per-tenant state only: `purchased_at`, `enabled`, `stripe_subscription_item_id`, `billing_cycle`, `migrated_at` |

Deliberately the `payment_plans` shape. It also removes an existing drift
source: `SynchronizeModules` currently copies name and description into every
tenant database on every marketplace render — a write on a GET. After this
change a tenant row exists **only** for a module that tenant purchased, and
the marketplace renders `ModuleCatalog::available()` intersected with the
modules installed on the node (`Modules::modules()`), left-joined against
tenant state. `SynchronizeModules` shrinks to that intersection helper.

`App\Models\Central\ModuleOffering` (not `Module` — `App\Models\Tenant\Module`
holds that name) mirrors `PaymentPlan`: `CentralConnection`, an `available()`
scope, and a `booted()` hook forgetting the catalogue cache key on
save/delete. Bounded TTL **and** an explicit invalidator, no
`rememberForever`, per `.claude/rules/tenant-caching.md`.

### Contracts

Mirroring `App\Contracts\Billing\Plan` / `PaymentPlanRepository`:

```php
namespace App\Contracts\Billing;

interface ModuleOffer
{
    public function slug(): string;
    public function name(): string;
    public function description(): ?string;
    public function billingMode(): ModuleBillingMode;   // App\Enums\ModuleBillingMode
    public function priceId(?BillingCycle $cycle): ?string;
    public function price(?BillingCycle $cycle): ?int;  // minor units
}

interface ModuleCatalog
{
    /** Available only. Every purchase path resolves a client-supplied slug through here. */
    public function findBySlug(string $slug): ?ModuleOffer;
    /** Admin/reporting: describes retired modules a tenant still pays for. */
    public function findAnyBySlug(string $slug): ?ModuleOffer;
    /** @return Collection<int, ModuleOffer> */
    public function available(): Collection;
}
```

`App\Services\Billing\Modules\EloquentModuleCatalog` implements it, bound in
`config('billing.implementations')` beside the existing entries. The
`findBySlug` scoping is not cosmetic: it is the rule
`.claude/rules/billing-checkout.md` records for `PaymentPlanRepository`. A
retired module must stop being purchasable at its old price the moment an
admin unticks `available`, and the marketplace slug is client input.

Files: `app/Contracts/Billing/{ModuleOffer,ModuleCatalog}.php`,
`app/Enums/ModuleBillingMode.php` (`Recurring` / `OneTime`, TitleCase keys),
`app/Models/Central/ModuleOffering.php`,
`app/Services/Billing/Modules/EloquentModuleCatalog.php`,
`app/Support/Cache/CacheKeys::availableModules()`.

### Central admin resource

`app/Filament/Admin/Resources/Central/Modules/`, copying the
`Central/PaymentPlans/` layout exactly (Resource + `Schemas/ModuleForm` +
`Tables/ModulesTable` + `Pages/{List,Create,Edit}`). Fields switch on
`billing_mode` with `Get $get`.

**A recurring module must declare both cycles.** Stripe rejects a subscription
whose items do not share a recurring interval, so a monthly-only module could
never be added to a yearly tenant. Rather than a runtime guard, the admin form
makes `monthly_id` and `yearly_id` both `->required()` when
`billing_mode === 'recurring'`. The mismatch becomes unreachable: no exception
class, no branch in `PurchaseModule`, no test for it. `PurchaseModule` reads
the cycle off the tenant's current subscription price — the derivation
`Billing::mount()` already performs.

Seeded from a new `config/modules.php` catalogue block by
`Database\Seeders\Central\ModuleOfferingSeeder`, the way `PaymentPlanSeeder`
seeds `config('billing.plans')`, with price ids from env.

---

## Automatic tax, the billing address, and VAT numbers

`Cashier::calculateTaxes()` is on globally (`BillingServiceProvider:48`) and
`CreateInlineSubscription:93` opts out per call, because Stripe rejects
automatic tax on a customer with no address. The decision is to make the
address real and delete the override.

- **Checkout collects the address.** A Stripe **Address Element**
  (`mode: 'billing'`) mounts beside the existing Payment Element in the shared
  checkout component. Elements in one group merge automatically, so
  `confirmSetup()` attaches the address to the PaymentMethod's
  `billing_details` with no extra client payload — and therefore nothing
  client-supplied to trust.
- **The server reads it back, never the client.** `ResolveSetupIntent` already
  retrieves the SetupIntent for its ownership checks; it expands
  `payment_method`, and a new `App\Actions\Billing\SyncBillingAddress` writes
  `billing_details.address` onto the Stripe customer. The Stripe customer is
  the single source of truth for the address — **no local column and no
  `stripeAddress()` override**, because nothing in `app/` calls
  `syncStripeCustomerDetails()` and `SyncTenantToStripe` sends only
  `name`/`email`, so there is nothing that could clear it.
- **Checkout also collects an optional VAT number.** A plain
  `flux:input` beside the Address Element; `SyncBillingAddress` attaches it
  with `customers->createTaxId()`, type inferred from the address country
  (`eu_vat`, `gb_vat`, …). Stripe validates the format, applies reverse charge,
  and annotates the invoice. Without this, EU cross-border B2B customers are
  charged VAT they should not pay — Cashier's only tax-ID collection
  (`CheckoutBuilder::collectTaxIds`) belongs to hosted Checkout, which this app
  removed. The tenant Billing page gets an action to add or replace it later.
- **`automatic_tax` is dropped from `CreateInlineSubscription`,** and module
  charges pass nothing — the global default applies everywhere. One tax
  behaviour, one cohort.
- **`PurchaseModule` still guards.** A tenant whose customer has no address
  throws `BillingAddressRequired` rather than letting Stripe 400. Pre-launch
  there should be no such tenant; the guard is a cheap assertion, not a UI
  flow.
- **Tenants created before this land are deleted** with the existing
  `tenants:delete` command, as a one-off step in phase 2. No backfill.

Two Cashier behaviours this relies on, both verified:

- `Subscription::addPrice()` / `addPriceAndInvoice()`
  (`vendor/laravel/cashier/src/Subscription.php:905,974`) send **no**
  `automatic_tax` payload — an add-on inherits whatever the subscription was
  created with. So recurring modules are taxed *because* new subscriptions are
  created with automatic tax on, which is what dropping the override achieves.
  A subscription created before that change could never tax its add-ons
  without a separate Stripe update; deleting those tenants is what makes this
  a non-issue rather than a migration.
- One-time purchases are covered directly: `invoicePrice` goes through
  `ManagesInvoices:191,222`, which does send `automatic_tax`.

> **Blocking prerequisite, verify before phase 2:** Stripe Tax must be enabled
> in the dashboard with at least one tax registration for the account's home
> country. Without it, `automatic_tax.enabled = true` makes *every* charge
> fail — signup included. Confirm in test mode first; this is the one change
> here that can break the existing signup path.

---

## Billing the purchase

`App\Actions\Modules\PurchaseModule` (AsAction), the single choke point:

```php
handle(Tenant $tenant, CentralUser|Tenant\User $actor, string $slug): void
```

1. `$offer = $catalog->findBySlug($slug)` → `ModuleNotFound` otherwise.
2. Module package installed on this node → `ModuleNotInstalled`.
3. Already purchased → `ModuleAlreadyPurchased`.
4. Customer has a tax address → `BillingAddressRequired`.
5. **Authorise in the action, not only the UI.** Owner-only, the test
   `Billing::canAccess()` makes (`$tenant->owner()?->global_id === $actor->global_id`).
   `.claude/rules/billing-checkout.md` records this exact class of bug: the
   Livewire path bypasses every FormRequest rule, so the rule lives underneath
   it or not at all.
6. Branch on `billingMode()`:
   - **Recurring** — requires an `active`/`trialing` tenant subscription
     (`SubscriptionRequired` otherwise). `$subscription->addPriceAndInvoice($priceId)`
     normally; `$subscription->addPrice($priceId)` while `onTrial()`, so the
     add-on bills at trial end instead of charging a trialing customer today.
   - **One-time** — `$tenant->invoicePrice($priceId, 1)`. Works because
     `App\Concerns\Billing\Billable` composes Cashier's `Billable`, and
     `LinkSubscriptionToTenant` writes the owner's `stripe_id` onto the tenant,
     so the tenant billable already holds the card from signup.
7. `IncompletePayment` is **not** swallowed — it propagates, exactly as
   `CreateInlineSubscription` documents, because turning it into a second
   `confirmPayment()` round trip is the UI's job.
8. On success, `App\Actions\Modules\RecordModulePurchase` (idempotent
   `updateOrCreate`) writes the tenant row: `purchased_at`, `enabled = true`,
   `stripe_subscription_item_id`, `billing_cycle`.
9. `MigrateModules::dispatch($tenant, $slug)`.

Every refusal is an `App\Exceptions\Billing\*` subclass of `DomainException`
with customer-grade copy, per `.claude/rules/exception-handling.md` — no bare
`throw new Exception`.

**Cancelling must never destroy tenant data.** `App\Actions\Modules\CancelModule`
calls `$subscription->removePrice($priceId)` for recurring modules and sets
`enabled = false`, keeping every row the module wrote. `RollbackModules` is
wired only to a separate, explicitly-confirmed "uninstall and delete data"
action. Cancelling a subscription item and dropping tables are different
operations and stay different buttons.

---

## Module migrations and seeders

`MigrateModules` becomes migrate-then-seed, checking both exit codes:

```
tenants:migrate-module {module} --tenants=<key>
tenants:seed-module    {module} --tenants=<key>
```

- `MigrateTenantModule` is rewritten to mirror `RollbackTenantModule`: resolve
  the module through `Modules::module()`, take
  `$module->path('database/migrations/tenant')`, and call core `migrate` with
  `--path` + `--realpath` inside `$tenant->run()`. The current
  `$this->call('module:migrate', …)` targets a command that does not exist.
- `tenants:seed-module` is new, same shape, calling modular's `db:seed`
  override with `--module=<name> --class=<Name>PermissionSeeder`.
- `RollbackModules` is repointed at `tenants:rollback-module`.

**Permissions are seeded, not migrated.** Each module ships
`database/seeders/<Name>PermissionSeeder` creating its Spatie permissions with
`guard_name = 'tenant'` (idempotent `firstOrCreate`) and granting them to the
tenant admin role. Permissions are data: re-running the seeder repairs a
tenant without a migration rollback, and changing a grant does not need a new
migration file. Skipping this is not a 403 — `hasPermissionTo()` throws
`PermissionDoesNotExist` and the panel 500s, per `.claude/rules/auth-guards.md`.

---

## Reusable checkout

Two extractions out of `App\Livewire\Tenant\Registration\Steps\Payment`.

**`App\Concerns\Billing\ConfirmsPayments`** — the payment protocol currently
inline in `Payment::confirmed()`:

- `public ?string $paymentError`
- `handleIncompletePayment(IncompletePayment $e)` → `dispatch('requires-action', clientSecret: $e->payment->clientSecret())`
- `hasSettled(Subscription $s): bool` → `syncStripeStatus()` inside a
  `try/catch (ApiErrorException)` that `report()`s, then the
  `['active','trialing']` **allowlist**. Never a denylist — both the comment in
  `Payment::confirmed()` and `.claude/rules/billing-checkout.md` record why
  (`incomplete_expired` is ~23h away, so a denylist provisions unpaid tenants).

Used by the checkout component and by the marketplace, which needs the same
round trip when Stripe challenges an add-on invoice.

**`App\Livewire\Billing\Checkout`** — owns the Address + Payment Elements,
`subscribe()`, `confirmed()` and the settle handoff. Reached two ways:

- **Embedded** by the wizard's `Payment` step:
  `<livewire:billing.checkout :pending-domain="…" :embedded="true" />`. The
  step keeps its own `back()` / `showStep('plan')` chrome, so signup looks
  unchanged — this is the "seamless" requirement, and the existing checkout
  tests passing untouched is what proves it.
- **Routed** at `/checkout/{domain}` (central domain, `auth`), resolved by a
  new `App\Actions\Billing\Checkout\ResumeCheckout` that loads the
  `pending_tenant_provisions` row by domain and rejects any row whose
  `global_id` is not the authenticated `CentralUser`'s — the ownership test
  `ResolveSetupIntent` and `Payment::settle()` already make.

Resuming needs no schema change: `InlineCheckoutGateway::begin()` already
persists `stripe_setup_intent_id`, so `ResumeCheckout` retrieves the
SetupIntent and reuses its `client_secret`. If it comes back `succeeded`, the
checkout is past collection and the component settles instead of mounting an
Element.

`#[Locked] public string $pendingDomain` and the re-check in `settle()` carry
over verbatim — the lock is the Livewire guarantee, the ownership check the
domain one, and `.claude/rules/billing-checkout.md` records what happened when
only one existed.

Files: `app/Livewire/Billing/Checkout.php`,
`resources/views/livewire/billing/checkout.blade.php`,
`resources/views/components/billing/address-element.blade.php`,
`app/Concerns/Billing/ConfirmsPayments.php`,
`app/Actions/Billing/Checkout/ResumeCheckout.php`,
`app/Actions/Billing/SyncBillingAddress.php`, route in `routes/web.php` beside
the existing `checkout.subscription.*` pair. `Payment.php` and its Blade shrink
to wizard chrome plus the embed. `resources/js/stripe-checkout.js` gains the
Address Element in the same `elements` group and is otherwise untouched — it
already talks only to `$wire`.

---

## Marketplace page

`app/Filament/TenantAdmin/Pages/Modules/Marketplace.php` rewritten:

- `getModules()` reads `ModuleCatalog::available()` ∩ installed, left-joined
  with tenant `modules` rows. No writes on render — the
  `SynchronizeModules::make()->handle()` call on every render goes.
- `purchaseAction(string $alias)` — a public Livewire method taking a client
  string — becomes a Filament `Action` with a confirmation modal showing price,
  billing mode, and for recurring add-ons a "prorated onto your existing
  subscription" line. It resolves the slug through `findBySlug`
  (available-scoped) and calls `PurchaseModule`.
- Catches `ShowsMessageToUser` → danger `Notification`, matching
  `Billing::changePlan()`. Never `catch (Throwable)`.
- Catches `IncompletePayment` → `ConfirmsPayments::handleIncompletePayment()`.

**3DS without an Element.** The card is already on file, so the existing
`stripeCheckout` Alpine component — which wants a client secret at init and
mounts a Payment Element — does not apply. A second, small
`resources/js/stripe-confirm.js` listens for `requires-action` and calls
`stripe.confirmPayment({ clientSecret, redirect: 'if_required' })`, then
`$wire.confirmed()`. No Element, no SetupIntent, purchase stays on one page.

A `cancel` action on the tenant `ModuleResource` calls `CancelModule`.

---

## Plugin registration

`TenantAdminPanelProvider->plugins()` hardcodes its list. Module plugins are
registered conditionally through `App\Concerns\InteractsWithTenantModules`
(orphaned today; this is its intended consumer), driven by a slug →
plugin-class map in `config/modules.php`.

One prerequisite: `getModuleModel()` opens a fresh temporary database
connection **per call**, so N plugins means N connections and N queries at
panel-register time. It gains a method that fetches the enabled slug set once
and memoises it for the request. Do not put that set in `global_cache()` — it
is tenant-derived, and `.claude/rules/tenant-caching.md` is about exactly that
mistake. A per-request memo is correct and sufficient.

---

## The four modules

Scaffolded with `artisan make:module`, following `app-modules/alerts`' layout:
`src/<Name>Plugin.php`, `src/Providers/`, `src/Models/`,
`src/Filament/Resources/`, `database/migrations/tenant/`,
`database/seeders/`, `tests/Feature/`.

| module | billing | schema | UI |
|---|---|---|---|
| **Tasks** `nvade/tasks` | recurring | `tasks`: title, description, status enum, priority, `assignee_id`, `due_at` | `TaskResource` with `SelectFilter` on status/assignee, overdue filter |
| **Notes** `nvade/notes` | recurring | `notes`: title, body, `author_id`, pinned | `NoteResource`, pinned-first list |
| **Announcements** `nvade/announcements` | recurring | `announcements`: title, body, level enum, `starts_at`, `ends_at`, dismissible | `AnnouncementResource` + a `PanelsRenderHook::CONTENT_START` banner for active ones, the hook `payment-status-banner` already uses |
| **Branding** `nvade/branding` | **one-time** | `branding_settings`: primary_color, logo_path, favicon_path (single row) | Filament settings Page; makes `ApplyPanelColorMiddleware` read the tenant's colour instead of the hardcoded `Color::Rose` |

Branding is deliberately the one-time module: it exercises the second billing
mode end to end rather than leaving it untested, and "buy your branding once"
is the honest shape for it.

`assignee_id` / `author_id` reference tenant users. Note
`.claude/rules/tenant-provisioning.md` on `Tenant\User` — a tenant user cannot
be created without its central counterpart, so module factories take an
existing user rather than creating one.

---

## Phases

1. **Catalogue** — done. Enum, contracts, central `modules` migration +
   model, `EloquentModuleCatalog`, config binding, `config/modules.php`,
   seeder, central Filament resource with the both-cycles rule.
2. **Tax + checkout extraction** — done. Address Element, optional VAT
   field, `SyncBillingAddress`, the Billing-page tax-id action, the
   `automatic_tax` override dropped; `ConfirmsPayments`,
   `Livewire\Billing\Checkout`, `ResumeCheckout`, the route, the wizard
   embed.
3. **Module commands** — done. `MigrateTenantModule` rewritten,
   `tenants:seed-module` added, `RollbackModules` repointed, the `alerts`
   migration moved into `database/migrations/tenant/`.
4. **Purchase** — done. Tenant `modules` columns
   (`stripe_subscription_item_id`, `billing_cycle`, `migrated_at`),
   `PurchaseModule`, `RecordModulePurchase`, `CancelModule`, the four
   exceptions, `stripe-confirm.js`, marketplace rewrite (real `Action` +
   confirm modal), `ModuleResource` cancel action. 15 new tests, pint/phpstan
   clean.
5. **Plugin registration** — done. Memoised
   `InteractsWithTenantModules::getEnabledModuleNames()`, conditional
   `plugins()` wiring in `TenantAdminPanelProvider::enabledModulePlugins()`.
6. **Modules** — done. Tasks, Notes, Announcements, Branding scaffolded
   under `app-modules/`, each with a tenant migration, model, factory,
   permission seeder, Filament resource/pages (or, for Branding, a settings
   page), and a `ModulePlugin`, registered in `config('modules.plugins')`.
   Test-mode Stripe prices created via the API and written to `.env`;
   `ModuleOfferingSeeder` re-run to pick them up. `ApplyPanelColorMiddleware`
   reads the tenant's `branding_settings.primary_color` (via
   `Filament\Support\Colors\Color::generatePalette()`) when the Branding
   module is enabled, falling back to `Color::Rose` otherwise — gated on
   `enabled`, not just `purchased_at`, so a cancelled-but-not-deleted row
   doesn't keep painting the panel for free. `MigrateModules` now also
   stamps `modules.migrated_at` on success — it existed as a column since
   phase 4 but nothing wrote it, which is what let a tenant sit with
   `enabled=true` and no migrated table silently (a stale module-registry
   cache on a long-running queue worker was the proximate cause the first
   time it happened; `artisan modules:clear` plus a worker restart is the
   fix whenever a new module is added on a running queue).
7. **Webhook reconciliation** — done.
   `App\Actions\Modules\ReconcileModuleSubscriptionItems`, called from
   `WebhookController::handleCustomerSubscriptionUpdated` after the
   suspend/restore match. Disables any enabled, recurring tenant module
   whose `stripe_subscription_item_id` is no longer among the subscription's
   current items — never re-enables, never touches tenant data. Manages
   tenancy manually with an explicit `try/finally` rather than
   `$tenant->run()`: that helper (`Stancl\Tenancy\Database\Concerns\TenantRun`)
   has no try/finally of its own, so an exception thrown by the callback —
   exactly the "tenant database not reachable yet" case this guards
   against — skips its revert step and leaves the app's default connection
   pointed at a nonexistent database for the rest of the request/process.
   Catches `QueryException|PDOException` and reports rather than throws,
   since a subscription-updated webhook can race ahead of tenant
   provisioning finishing (or, in older tests, land on a central-only
   fixture tenant with no real database by design).

---

## Verification

- `vendor/bin/sail bin pint --dirty --format agent` and
  `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"`
  after each phase. PHPStan covers `tests/`, so a rename surfaces immediately
  rather than as a runtime error in a file nobody ran.
- Per-phase Pest runs, touched files only:
  `vendor/bin/sail artisan test --compact --filter=…`. New tests:
  - `EloquentModuleCatalogTest` — a retired module is invisible to
    `findBySlug`, visible to `findAnyBySlug`.
  - `SyncBillingAddressTest` — the address off the SetupIntent's payment
    method lands on the customer; a supplied VAT number becomes a Stripe tax
    id of the right type for the address country; an invalid one surfaces as a
    `DomainException`, not a 500.
  - `MigrateTenantModuleTest` — runs a module's `database/migrations/tenant`
    against the tenant database and **not** against central; non-zero exit
    propagates out of `MigrateModules` as an exception.
  - `PurchaseModuleTest` — recurring adds a subscription item; one-time
    invoices; each guard refuses (non-owner, already purchased, retired slug,
    no subscription, no address); `MigrateModules` is dispatched.
  - `CancelModuleTest` — removes the price, sets `enabled = false`, and does
    **not** dispatch `RollbackModules`.
  - `ResumeCheckoutTest` — another user's domain is refused.
  - `MarketplaceTest` and the four module resource tests, entered through
    `Tests\TestCase::actingAsTenantPanelUser()` — Filament's tenant is not set
    by `Livewire::test()`, per `.claude/rules/filament-tenancy.md`.
  - The existing `tests/Feature/Actions/Billing/Checkout/*` and
    `RegistrationCheckoutHandoffTest` stay green through phase 2 unchanged.
- Stripe-touching tests follow `CreateInlineSubscriptionTest`: real test-mode
  API, `markTestSkipped` when the price env var is absent. Module test prices
  need their own test-mode Stripe prices in `.env`.
- Manual pass: sign up and confirm the address reaches the Stripe customer and
  the invoice shows tax; sign up again with an EU VAT number and confirm the
  invoice is reverse-charged at 0%; buy Tasks on a trialing tenant (bills at trial end, no
  charge today); buy Branding on an active tenant (immediate invoice); confirm
  the Tasks resource appears in the panel only after purchase; toggle it off
  and confirm the data survives.
