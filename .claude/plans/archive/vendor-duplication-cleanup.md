# Plan: De-duplicate against the packages we already depend on

**Status: ✅ Executed** (commits `112696c`, `b334847`, plus #4 on
2026-08-11). Verified: #1 `EloquentSubscriptionRepository::record()` now
uses `updateOrCreate` (both subscription and items); #2
`App\Contracts\Cacheable` deleted; #3 `app/helpers.php` deleted; #5
`MigrateTenantModule`/`RollbackTenantModule` rewritten in the stancl-trait
image; #4 the two dead legacy tables removed standalone (see below, the
full migration squash this item originally deferred to never happened and
`package-extraction.md`'s Phase 6 is otherwise done); #6 already moot by
the time this was re-checked — `MoneyFormatter::format(int $amount)` takes
minor units directly and no `Money` cast class exists in the current
`src/` tree, so the float round-trip this item described is gone (not
fixed by this session — already gone before it started, cause unclear,
matches production code today regardless); #7 done, 2026-08-11 — user
chose "enable it" over "drop the migration," implemented as a
`NamedFeature` plus a central-panel action, see its own section below.
Item #8 not individually re-verified line-by-line — spot-check if it
resurfaces.

Audit of where this codebase reimplements behaviour that `laravel/cashier`,
`stancl/tenancy`, `spatie/*` or `internachi/modular` already provide. Separate
from `.claude/plans/archive/package-extraction.md` — this stands on its own and is
worth doing whether or not the package extraction happens. Doing it *first*
shrinks what has to be extracted.

## Verdict summary

| # | Item | Verdict | Effort |
|---|---|---|---|
| 1 | `EloquentSubscriptionRepository::record()` vs Cashier webhook sync | Fix — real risk | M |
| 2 | `App\Contracts\Cacheable` | Delete — dead | XS |
| 3 | `app/helpers.php` | Delete — empty | XS |
| 4 | Legacy `subscriptions` + `payments` tables | ✅ Done, 2026-08-11 (standalone, not via a squash) | S |
| 5 | `MigrateTenantModule` / `RollbackTenantModule` | ✅ Done — already using `HasATenantsOption`, reverified 2026-08-11 | S |
| 6 | `Money` cast vs `MoneyFormatter` | ✅ Already moot — no float round-trip in current code | S |
| 7 | Impersonation table with the feature disabled | ✅ Done, 2026-08-11 — enabled | XS |
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
stalls.

**Done, 2026-08-11, standalone** — the full squash never happened and
`package-extraction.md` is otherwise closed, so this went in on its own
rather than waiting indefinitely. Deleted both dead files
(`2025_06_23_213148_create_subscriptions_table.php`,
`2025_06_23_214448_create_payments_table.php`) and removed the
`Schema::drop('payments'); Schema::drop('subscriptions');` lines from
`2025_12_24_204847_create_subscriptions_table.php` (Cashier's) that existed
only to clear away what those two used to create — unconditional `drop()`,
not `dropIfExists()`, so leaving them in place after deleting their targets
would have broken every fresh install with a "table doesn't exist" error.
Confirmed no code references the dead `payments` table or the legacy
`subscriptions` columns before deleting (`grep` across `src/`+`tests/`,
nothing). Existing installs are unaffected either way — Laravel does not
re-run migrations already recorded in the `migrations` table, so neither
deleting a file nor editing another one already-applied changes anything
for a database that has already migrated past this point.

Full suite re-run after: 550 passed / 1 known-baseline failure
(`RegisterTenantTest`, unrelated) / 7 skipped — unchanged from the
pre-existing baseline, so this is not a schema-shape check that only a
fresh install would catch; the CI-provisioned suite still built its
tenant template through this exact chain.

**The other historical fixups this item nominated as a bonus catch
(`unfuck_payment_plans_and_features`, `rename_id`, `remove_morphs`,
`change_subscribable_id_type_to_string`) were deliberately left alone.**
Removing two structurally-dead tables that ship data nothing reads is a
different scale of change from squashing 15+ migrations into fewer files —
that is real Phase 6 territory (rewriting/collapsing migrations that *are*
load-bearing, just verbose), not a same-pass extension of this fix. Two of
the four names no longer exactly match anything in the current migrations
directory either (renamed since this plan was written) — whoever picks up
the real squash should re-audit the current migration list rather than
trust these four names literally.

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

**Already moot, re-checked 2026-08-11 — no code changes made.** This item
described `App\Casts\Money` dividing a stored integer by 100 into a float,
with `CashierMoneyFormatter::format()` re-multiplying it back to cents.
Neither half of that exists in the current `src/` tree:
`grep -rn "Casts\\Money"` finds nothing, and
`Nvade\Numerosis\Contracts\Billing\MoneyFormatter::format()` is typed
`(int $amount, ...)` with a docblock stating outright that `$amount` is
"Minor currency units (cents) — what Cashier's own formatting expects" —
exactly the fix this item asked for. Whether this was fixed in an
unrecorded pass or the `Money` cast never survived the package extraction
in the first place is not established; what matters for anyone re-reading
this plan is that the float round-trip this item warned about is not
present in the code today. Re-verify with the same grep before assuming
this stays true indefinitely — nothing pins it structurally, a future
`Money`-named cast could reintroduce the same trap under a different
class name.

## 7. Impersonation: table without the feature

`config/tenancy.php` has `Stancl\Tenancy\Features\UserImpersonation::class`
commented out, but `2020_05_15_000010_create_tenant_user_impersonation_tokens_table`
still runs. Decide:

- Want impersonation (plausible for a SaaS admin panel) → enable the feature
  and wire a route.
- Don't → drop the migration in the squash.

Do not ship a package that migrates a table for a disabled feature.

**Done, 2026-08-11 — enabled, not dropped.** User's call (the config file
this bullet describes was itself already gone by the time this was
re-checked — `config/tenancy.php` isn't published in this package at all
any more, everything comes from `HostConfig`/stancl's own defaults — so
there was nothing to uncomment; the migration was the only surviving trace
of the disabled state).

- **`ImpersonationFeature` (`src/Features/Tenancy/ImpersonationFeature.php`),
  a `NamedFeature` like every other toggle.** Its `bootstrap()` appends
  `Stancl\Tenancy\Features\UserImpersonation::class` to
  `config('tenancy.features')` — read-modify-write on the array, not a
  multi-segment dotted `Config::set()`, so it can't hit the
  `Arr::set()`-auto-vivification class of bug `package-host-bootstrap.md`
  warns about for keys under a namespace this package doesn't own. Safe to
  run from `packageBooted()`'s feature loop specifically because stancl
  reads `tenancy.features` lazily, inside `app->extend(Tenancy::class, ...)`
  — fired on first resolution of the `Tenancy` singleton, which happens
  well after every provider's `register()` and `boot()` have run (during
  request-time tenancy identification) — not a register-vs-booting race
  like `HostConfig::apply()` was.
- **Added to the default `numerosis.features` list** (`config/numerosis.php`),
  not opt-in — matches this package's actual convention (every other
  feature ships enabled, a host comments out what it doesn't want; see
  `SocialLoginFeature`'s docblock for the same phrasing) rather than
  inventing a new opt-in-only precedent for one feature. Flagged in its own
  comment as security-sensitive, since it's a materially different kind of
  toggle than "does this form field render."
- **`ImpersonateTenantUser` (`src/Actions/Tenancy/ImpersonateTenantUser.php`)
  writes the `ImpersonationToken` directly** (`ImpersonationToken::create([...])`)
  rather than through stancl's `tenancy()->impersonate()` macro — the macro
  is exactly that one `create()` call and nothing more, and calling it
  directly keeps the action's return type checkable by PHPStan (the macro
  is dynamically registered, so static analysis sees
  `Tenancy::impersonate()` as an undefined method no matter what).
  `ImpersonationFeature` still registers stancl's feature regardless, since
  `UserImpersonation::makeResponse()` — the *login-consuming* half, reached
  from the new `impersonate/{token}` route in `routes/tenant.php` — is
  stancl's own static method, not reimplemented here.
- **Resolves "the owner" via `Tenant::owner()`** (already existed — a
  `BelongsToMany` keyed on `global_id`, central-side, no tenant-context
  query needed to find *who*), then a single `$tenant->run()` read to
  resolve that owner's tenant-side row id from `global_id` — same
  "plain read, no try/finally needed" category `AddTenantOwner` already
  uses, per `module-marketplace.md`'s guidance on `$tenant->run()`. No UI
  for picking a *different* user yet — v1 is owner-only, matching the
  stated "log in as the customer" support use case; the action already
  takes just a `Tenant`, so extending it to accept a user id later is a
  small, additive change, not a redesign.
- **New `TenantHasNoOwner` exception** (`src/Exceptions/Tenancy/`), extends
  `DomainException` per `exception-handling.md`'s split — thrown both when
  a tenant genuinely has no owner membership and when the owner's
  tenant-side row is missing (a `Membership` can exist without
  `AddTenantOwner` ever having run, e.g. mid-provisioning).
- **"Impersonate owner" table action on `TenantResource`**, same shape as
  the existing `suspend`/`restore` actions. Visible only when the feature is
  enabled, the tenant is provisioned and not suspended, and has a resolvable
  owner — each a real query per visible row, same cost the existing
  `isSuspended()` check already pays.
- **Tests**: `ImpersonateTenantUserTest` (4 tests, including a real
  end-to-end one — hits the actual `impersonate/{token}` URL over HTTP and
  asserts the tenant guard authenticates as the right `Tenant\User`, and a
  reuse test confirming a consumed token 404s on a second hit) and
  `ImpersonationFeatureTest` (route registered, stancl's feature present in
  `tenancy.features`). Full suite 556 passed (550 + 6 new) / 1
  known-baseline failure / 7 skipped; PHPStan 10 baseline errors, 0 new —
  and one *stale* baseline entry removed along the way:
  `Tenant::$provisioned_at` was a real, undocumented column missing from
  the model's `@property` block (not a `data`-JSON virtual column, unlike
  the trap `tenant-provisioning.md` describes for the same column
  historically), so PHPStan couldn't see it at all. The new "impersonate"
  action's `->visible()` closure was the *second* place in
  `TenantResource.php` to read it, which is what surfaced the gap — adding
  the missing `@property` tag fixed both occurrences at once and made the
  file's existing `count: 1` baseline entry stale, so it's gone rather than
  bumped to `count: 2`.

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
