# Plan: De-duplicate against the packages we already depend on

**Status: ✅ Executed** (commits `112696c`, `b334847`). Verified: #1
`EloquentSubscriptionRepository::record()` now uses `updateOrCreate` (both
subscription and items); #2 `App\Contracts\Cacheable` deleted; #3
`app/helpers.php` deleted; #5 `MigrateTenantModule`/`RollbackTenantModule`
rewritten in the stancl-trait image. Items #4, #6, #7, #8 not individually
re-verified line-by-line — spot-check if any resurfaces.

Audit of where this codebase reimplements behaviour that `laravel/cashier`,
`stancl/tenancy`, `spatie/*` or `internachi/modular` already provide. Separate
from `.claude/plans/package-extraction.md` — this stands on its own and is
worth doing whether or not the package extraction happens. Doing it *first*
shrinks what has to be extracted.

## Verdict summary

| # | Item | Verdict | Effort |
|---|---|---|---|
| 1 | `EloquentSubscriptionRepository::record()` vs Cashier webhook sync | Fix — real risk | M |
| 2 | `App\Contracts\Cacheable` | Delete — dead | XS |
| 3 | `app/helpers.php` | Delete — empty | XS |
| 4 | Legacy `subscriptions` + `payments` tables | Delete in migration squash | S |
| 5 | `MigrateTenantModule` / `RollbackTenantModule` | Adopt stancl traits | S |
| 6 | `Money` cast vs `MoneyFormatter` | Fix — float round-trip | S |
| 7 | Impersonation table with the feature disabled | Decide, then act | XS |
| 8 | `Actions/Auth/*` vs Fortify | Keep, align contract names | M |

---

## 1. Subscription rows have two writers

`App\Http\Controllers\Billing\WebhookController::handleCustomerSubscriptionCreated`
calls `parent::` first, so **Cashier already writes the `subscriptions` row and
its `subscription_items`** from the Stripe payload. Then, on the redirect path,
`LinkSubscriptionToTenant` looks the row up by `stripe_id` and — if the webhook
has not landed yet — writes the same row itself through
`SubscriptionRepository::record()`, which hand-maps every column Cashier just
mapped, plus items.

The overlap is legitimate: the browser redirect genuinely beats the webhook,
and the tenant needs a subscription row before the user sees the panel. What is
*not* legitimate is the write mechanics:

```php
// EloquentSubscriptionRepository::record()
$subscription = Subscription::query()->create($data->except('items')->toArray());

foreach ($data->items as $item) {
    $subscription->items()->create($item->toArray());
}
```

- Bare `create()`, not `updateOrCreate()` keyed on `stripe_id`. The only thing
  preventing a duplicate row is `Cache::lock("reconcile-subscription:{id}")` in
  `LinkSubscriptionToTenant` — and Cashier's own webhook write does not take
  that lock, so the two writers are not actually serialised against each other.
  `subscriptions.stripe_id` being unique (per
  `.claude/rules/tenant-provisioning.md`) turns the race into an exception
  rather than a duplicate, which is better but still a failed job.
- Items are inserted unconditionally, so a second pass duplicates them even
  when the parent row is found.

**Fix**

- `record()` becomes `updateOrCreate(['stripe_id' => …], …)`; items become
  `updateOrCreate` on `stripe_id` too.
- Have Cashier's write path and ours agree on the same lock key, or drop our
  lock and rely on the unique constraint plus the upsert.
- Reduce `SubscriptionData` / `StripeSubscriptionData` / `SubscriptionItemData`
  to the fields we add on top of Cashier's mapping (`payment_plan_id`,
  `subscribable_*`), rather than restating Cashier's whole column list.

**Tests**: cover redirect-then-webhook and webhook-then-redirect for the same
`stripe_id`, asserting exactly one `subscriptions` row and one item per price.

## 2. `App\Contracts\Cacheable` — dead

`getCacheKey()` / `getCacheTags()`. Zero implementors; `grep` finds only the
file itself. `App\Support\Cache\CacheKeys` is the actual design that shipped
(see `.claude/rules/tenant-caching.md`). Delete the interface — leaving it
invites someone to implement the losing pattern.

## 3. `app/helpers.php` — empty

Three lines: `<?php`, `declare(strict_types=1)`, nothing. Still listed under
composer `autoload.files`, so it is loaded on every request. Either delete both
the file and the autoload entry, or (in the package world) make it the home for
guarded package helper functions. Do not leave it as is.

## 4. Legacy billing tables

- `2025_06_23_213148_create_subscriptions_table` builds a bespoke subscriptions
  table (own `status` enum, `starts_at`, `cancelled_at`, `stripe_subscription_id`).
  `2025_12_24_204847_create_subscriptions_table` — Cashier's — opens with
  `Schema::drop('subscriptions')` and replaces it. Pure history.
- `2025_06_23_214448_create_payments_table` creates `payments`. No `Payment`
  model, no factory, no reference anywhere in `app/`. Never used.

Neither costs anything at runtime, both cost a reader's time and both ship to
consumers if the migration set is published as is. Remove them as part of the
migration squash (`package-extraction.md` Phase 6), or standalone if that plan
stalls. Same pass should catch the other historical fixups:
`unfuck_payment_plans_and_features`, `rename_id`, `remove_morphs`,
`change_subscribable_id_type_to_string`.

## 5. Module migration commands reimplement stancl's tenant iteration

`App\Console\Commands\MigrateTenantModule`:

```php
#[Signature('tenants:migrate-module {module?} {--tenant=}')]
…
$tenant = Tenant::find($tenantId);
if (! $tenant) { $this->error(…); return; }
$tenant->run(fn () => $this->call('module:migrate', $params));
```

Hand-rolled: single `--tenant`, manual lookup, manual error strings, no events.
stancl ships exactly this as reusable traits — `HasATenantsOption` (accepts
`--tenants=*`, resolves and iterates), `DealsWithMigrations`,
`ExtendsLaravelCommand` — used by `Stancl\Tenancy\Commands\Migrate`. Adopting
them gives multi-tenant runs and `DatabaseMigrated` events for free.
`RollbackTenantModule` is the same shape and gets the same treatment.

Keep the delegation to `internachi/modular`'s `module:migrate` — that part is
not duplicated, and `App\Jobs\MigrateModules` already checks the exit code
correctly per `.claude/rules/exception-handling.md`.

## 6. Two money representations

`App\Casts\Money` divides the stored integer by 100 and returns a **float**;
`CashierMoneyFormatter::format()` takes that float and does
`(int) round($amount * 100)` to hand Cashier cents again. Money makes a
float round-trip for no gain, and every consumer of a cast attribute is doing
float arithmetic on currency.

**Fix**: keep integer minor units on the model (drop the cast, or cast to `int`),
and let `MoneyFormatter` take minor units directly — which is what
`Cashier::formatAmount()` wants anyway. Audit the Filament columns and Blade
views that read the cast before flipping it; the display path is where the
float currently gets consumed.

## 7. Impersonation: table without the feature

`config/tenancy.php` has `Stancl\Tenancy\Features\UserImpersonation::class`
commented out, but `2020_05_15_000010_create_tenant_user_impersonation_tokens_table`
still runs. Decide:

- Want impersonation (plausible for a SaaS admin panel) → enable the feature
  and wire a route.
- Don't → drop the migration in the squash.

Do not ship a package that migrates a table for a disabled feature.

## 8. `Actions/Auth/*` vs Fortify — keep, but align

`RegisterUser`, `LoginUser`, `LogoutUser`, `UpdateUserPassword`,
`UpdateUserProfile`, `ResendVerificationNotification`,
`ConnectSocialAccount`/`DisconnectSocialAccount`, `DeleteUserAccount` cover
close to Fortify's action surface. Not duplication *today* — Fortify is not
installed — but it becomes duplication the moment a consumer installs the
package alongside Fortify or a Laravel starter kit, which is the common case.

Not worth rewriting onto Fortify. Worth doing:

- Name the contracts after Fortify's (`CreatesNewUsers`, `UpdatesUserPasswords`,
  `UpdatesUserProfileInformation`, `DeletesUsers`) so either implementation can
  satisfy the binding.
- Gate the whole group behind the planned `features.auth` flag so a consumer
  with their own auth stack loads none of it.

Both changes belong to the package work; listed here because this is where the
overlap was found.

## Suggested order

1 (correctness) → 6 (correctness-adjacent) → 5 → 2, 3, 7 (trivia, one commit) →
4 (with the squash) → 8 (with the package work).
