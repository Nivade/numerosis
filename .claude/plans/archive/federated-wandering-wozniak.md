# Merge numerosis / numerosis-billing / numerosis-tenancy config files

**Status: executed, 2026-08-06 — `numerosis@59f026f`, host repaired in
`thin-app@41bcd57`.** Recorded as decision D13 in `package-extraction.md`.
Steps 1-8 all landed as written; the only deviation is step 5's call-site
list, which missed three files — `database/seeders/PaymentPlanSeeder.php`
(a real `config('numerosis-billing.plans')` read, not a doc comment),
`database/seeders/Central/ModuleOfferingSeeder.php` and `tests/TestCase.php`
(doc comments). `database/` was not among the directories step 5
enumerated; step 8's grep is what caught them. **Widen the grep scope
before trusting an enumerated call-site list** — the same lesson this
extraction has already learned for models, views and factories.

## Context

Package ships three config files, each independently merged/published:
`config/numerosis.php` (core: features, schedule, routes, domains, views,
cache, model overrides), `config/numerosis-billing.php` (Cashier models,
webhook path, plans, trial/quota, contract bindings), `config/numerosis-
tenancy.php` (provisioning steps, contract bindings). User wants these
merged into a single file. Goal: one `config/numerosis.php`, all call sites
updated, no behavior change.

Two providers currently double-register billing/tenancy config:
`NumerosisServiceProvider::configurePackage()` already does
`->hasConfigFile(['numerosis', 'numerosis-tenancy', 'numerosis-billing'])`
(Spatie package-tools — merges all three under those three names, publishes
them together under tag `numerosis-config`, which is what
`InstallNumerosisCommand` calls). `BillingServiceProvider` and
`TenancyServiceProvider` *also* call their own `mergeConfigFrom()` +
`publishes()` with separate tags (`billing-config`, `tenancy-config`) that
nothing calls — dead duplicate publish paths. Merging the files is also a
chance to drop that duplication.

## Key collision

`numerosis.php` has a `models` key (per-model class override map, e.g.
`Tenant::class => env(...)`). `numerosis-billing.php` has a *different*
`models` key (Cashier model bindings: `'tenant' => Tenant::class`, etc).
Both can't live at the top level of one file under the same name. Fix:
nest billing's and tenancy's whole config under `'billing'` and `'tenancy'`
top-level keys; core keys stay top-level, unchanged. This also kills the
other latent collision (`implementations` exists in both billing and
tenancy files today, currently only surviving because they're separate
config namespaces).

Resulting top-level shape of `config/numerosis.php`:

```php
return [
    'features' => [...],       // unchanged
    'schedule' => [...],       // unchanged
    'routes' => [...],         // unchanged
    'domains' => [...],        // unchanged
    'views' => [...],          // unchanged
    'cache' => [...],          // unchanged
    'models' => [...],         // unchanged (core model overrides)
    'billing' => [
        'models' => [...],             // was numerosis-billing.models
        'webhook_path' => ...,
        'trial_days' => ...,
        'plans' => [...],
        'implementations' => [...],
        'unpaid_tenant_cap' => ...,
        'sync' => [...],
    ],
    'tenancy' => [
        'provisioning' => ['steps' => [...]],
        'implementations' => [...],
    ],
];
```

## Changes

1. **`config/numerosis.php`** — merge in the contents of the other two
   files under `'billing'` and `'tenancy'` keys as above. Keep each
   section's doc comments, just re-indented one level. Delete
   `config/numerosis-billing.php` and `config/numerosis-tenancy.php`.

2. **`src/NumerosisServiceProvider.php`** — `->hasConfigFile(['numerosis',
   'numerosis-tenancy', 'numerosis-billing'])` → `->hasConfigFile('numerosis')`.

3. **`src/Providers/BillingServiceProvider.php`** — remove its own
   `mergeConfigFrom()` call and its `publishes([...], 'billing-config')`
   block (main provider's `hasConfigFile` now owns merge+publish
   entirely). Update every `config('numerosis-billing.X')` /
   `Config::string('numerosis-billing.X')` read to
   `config('numerosis.billing.X')` / `Config::string('numerosis.billing.X')`
   — `implementations`, `sync.stripe_customer`, the three `'... gateway/source/model'`
   debug lines, and `billableModel()`'s `models.{$key}` lookup. Update its
   doc comment mentioning `numerosis-billing.models.*`.

4. **`src/Providers/TenancyServiceProvider.php`** — same pattern: drop its
   `mergeConfigFrom()` and `publishes([...], 'tenancy-config')`, rewrite
   `config('numerosis-tenancy.implementations')` →
   `config('numerosis.tenancy.implementations')`.

5. **Other `src/` call sites** — rewrite the `numerosis-billing.` / `numerosis-tenancy.`
   prefix to `numerosis.billing.` / `numerosis.tenancy.`; everything already on bare
   `numerosis.` (features, cache prefix, route names, domains, core model
   overrides) is untouched:
   - `src/Actions/Tenancy/RunProvisioningSteps.php`,
     `src/Actions/Tenancy/ProvisionTenant.php` —
     `numerosis-tenancy.provisioning.steps` → `numerosis.tenancy.provisioning.steps`
   - `src/Services/Billing/Resolvers/DefaultUnpaidTenantQuota.php` —
     `numerosis-billing.unpaid_tenant_cap` → `numerosis.billing.unpaid_tenant_cap`
   - `src/Services/Billing/Resolvers/PlanOrDefaultTrialResolver.php` —
     `numerosis-billing.trial_days` → `numerosis.billing.trial_days`
   - `src/Services/Billing/Plans/ConfigPaymentPlanRepository.php` —
     `numerosis-billing.plans` → `numerosis.billing.plans`
   - `routes/web.php` — `numerosis-billing.webhook_path` → `numerosis.billing.webhook_path`
   - Doc-comment-only mentions (`src/Services/Billing/BillingService.php`,
     `src/Contracts/NamedFeature.php`, various `Features/*.php` docblocks
     referencing `config('numerosis.features')`) — leave as-is, they already
     use the unprefixed core key.

6. **Tests** — same prefix rewrite, no behavior change:
   `tests/TestCase.php`, `tests/Feature/Http/Controllers/Billing/WebhookControllerSetupIntentTest.php`,
   `tests/Feature/Http/Controllers/Billing/WebhookControllerLifecycleTest.php`,
   `tests/Feature/Actions/Billing/Subscriptions/SubscriptionDualWriterTest.php`,
   `tests/Feature/Actions/Billing/Checkout/CreateInlineSubscriptionTest.php`,
   `tests/Feature/Actions/Billing/Checkout/CompleteRedirectCheckoutTest.php`,
   `tests/Feature/Actions/Billing/Checkout/StartSubscriptionCheckoutTest.php`,
   `tests/Feature/Actions/Modules/CancelModuleTest.php`,
   `tests/Feature/Actions/Modules/PurchaseModuleTest.php`,
   `tests/Feature/Services/Billing/Resolvers/DefaultUnpaidTenantQuotaTest.php`.
   (`tests/Feature/Support/FeaturesTest.php` already reads bare `numerosis.features` — untouched.)

7. **`src/Commands/InstallNumerosisCommand.php`** — no change needed;
   `--tag numerosis-config` already covers the single merged file once (2)
   lands. Sanity-check after the change that Spatie's `hasConfigFile()`
   still emits a tag named `numerosis-config` for a single-file array
   (it does — tag name derives from `$package->name()`, not file count).

8. Grep for stragglers after edits: `grep -rn "numerosis-billing\.\|numerosis-tenancy\."`
   across `src/`, `tests/`, `routes/`, `docs/`, `README.md`, `workbench/`
   should return nothing.

## Verification

- `vendor/bin/phpstan analyse` — catches any missed `config()->string()`
  typed-accessor call left pointing at a dead key.
- `vendor/bin/sail artisan test --compact --filter=Billing` and
  `--filter=Tenancy` (or run the specific files touched above) — confirms
  runtime config resolution still matches (webhook path routing, plan
  lookups, provisioning steps, unpaid-tenant-cap enforcement all exercise
  these keys directly).
- `vendor/bin/sail artisan config:show numerosis` — spot-check the merged
  shape has `billing.*` and `tenancy.*` nested correctly, no top-level
  `models`/`implementations` collision.
- `vendor/bin/sail artisan vendor:publish --tag=numerosis-config --force`
  in a scratch check (or just read the command) to confirm only one file
  publishes now, not three.
