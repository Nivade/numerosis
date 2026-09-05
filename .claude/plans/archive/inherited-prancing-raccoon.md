# Checkout → Tenant Reconciliation: Unify, Harden, Clean Up

**Status: ✅ Executed.** `WebhookController::ensureTenantExists`/
`ensureSubscriptionIsLinked` removed, reconciliation unified into
`LinkSubscriptionToTenant`, `PruneOrphanedStripeCustomers` command exists and
is scheduled.

## Context

Investigating a webhook error (`tenants.stripe_id` missing column, already fixed via migration) surfaced a deeper architectural issue in the Stripe Checkout → Tenant provisioning flow:

1. Checkout is deliberately started against `CentralUser` (not `Tenant`), because a `Tenant` doesn't exist yet at checkout time, **and** creating one is not free — `TenantCreated` runs `CreateDatabase` + `MigrateDatabase` + `SeedTenantDatabase` via a queued job pipeline (`app/Providers/TenancyServiceProvider.php:69-78`, `shouldBeQueued(true)`). Being queued means it doesn't block the user's redirect/request, but it still provisions a whole physical database per tenant — doing that for every abandoned checkout would leave orphaned databases to clean up, which is worse than the alternatives below. This deferral is correct and should be **kept**.
2. Because `Checkout::create()` calls `$owner->createOrGetStripeCustomer()` synchronously *before* the Stripe redirect (`vendor/laravel/cashier/src/Checkout.php:66`, `ManagesCustomer.php:96-106`), a Stripe customer + `users.stripe_id` gets persisted immediately, regardless of whether payment ever completes. Confirmed live in DB: `users.id=1` has `stripe_id=cus_UgEdp4H4fyU5Qe` with zero matching rows in `subscriptions` — a real orphaned customer from an abandoned checkout.
3. After payment succeeds, **two independent code paths** race to reconcile (create Tenant, link subscription, set `tenant.stripe_id`):
   - Sync (browser redirect): `ProcessSuccessfulCheckout::handle()` → `CreateTenant` → `TransferSubscriptionToTenant` (queued, takes a full Stripe `Checkout\Session`).
   - Async (webhook): `WebhookController::handleCustomerSubscriptionCreated()` → its own `ensureTenantExists()` / `ensureSubscriptionIsLinked()`, duplicating the same job with different code.

   This duplication is the actual root cause worth fixing — it's the same class of bug that already bit us (divergent logic, one path missing a fix the other had).

Goal: unify the reconciliation logic into one implementation, make tenant creation race-safe, and add cleanup for orphaned Stripe customers. Keep the "checkout on CentralUser, defer tenant creation" pattern as-is.

## A. Unify reconciliation logic

Single source of truth for "link this Stripe customer + subscription to this Tenant," callable from both the webhook and the sync redirect path.

- Refactor `App\Actions\Billing\Subscription\TransferSubscriptionToTenant::handle()` (`app/Actions/Billing/Subscription/TransferSubscriptionToTenant.php`) to stop requiring a Stripe `Checkout\Session` object. Change its signature to accept primitives both callers already have without extra Stripe API calls:
  ```php
  handle(string $stripeCustomerId, string $stripeSubscriptionId, ?\Stripe\Subscription $stripeSubscription, CheckoutData $checkoutData, Tenant $tenant, string|int $userId): void
  ```
  - Sync caller (`ProcessSuccessfulCheckout`) already has the expanded `$session->subscription` object — pass it through.
  - Webhook caller (`WebhookController::handleCustomerSubscriptionCreated`) has the raw payload array (`$payload['data']['object']`) — pass `$stripeSubscription['id']`, `$stripeSubscription['customer']`, and `null` for the expanded Stripe object (the existing "webhook hasn't arrived yet, create manually" branch already tolerates not having a full Stripe subscription object — invert it: webhook path already has the full subscription in `$payload`, so it can pass enough data to skip the manual-create fallback entirely; sync path is the one more likely to race ahead of the webhook, so it keeps the fallback).
  - Preserve `ShouldQueue`; both callers dispatch it the same way.
- Delete `WebhookController::ensureTenantExists()` and `ensureSubscriptionIsLinked()` (`app/Http/Controllers/Billing/WebhookController.php:52-96`). Replace `handleCustomerSubscriptionCreated()` body with: resolve/create tenant via the existing `CreatesTenant` contract (already shared), then dispatch the unified `TransferSubscriptionToTenant`.
- Net effect: one action owns "set tenant.stripe_id, link/create the subscription row, point it at the tenant." Both entry points call it identically.

## B. Race-safety for Tenant creation

`tenants.id` (the domain) is the primary key, so a genuine unique constraint already exists — use it instead of relying on non-atomic existence checks.

- In `App\Actions\Tenancy\CreateTenant::handle()` (`app/Actions/Tenancy/CreateTenant.php:19-42`), wrap the `Tenant::forceCreate(...)` in try/catch for `Illuminate\Database\QueryException` (unique violation). On catch: re-fetch `Tenant::findOrFail($data->domain)` and return it instead of letting the exception bubble into a 500 on the user's return-from-Stripe redirect.
- This makes both call sites (`ProcessSuccessfulCheckout`'s pre-check and the webhook's pre-check) safe even though neither uses a DB lock — worst case both attempt creation, one wins, the other gets the existing row back cleanly.

## C. Orphaned Stripe customer cleanup

Recommend a scheduled sweep over relying on `checkout.session.expired` webhooks — a cron sweep doesn't depend on that event being enabled on the Stripe webhook endpoint and catches every abandonment path uniformly (expired session, closed tab, network drop, etc.).

- New command `App\Console\Commands\PruneOrphanedStripeCustomers` following the existing `DeleteTenants.php` convention (`#[Illuminate\Console\Attributes\Signature]` / `#[Description]` attributes):
  - Signature: `billing:prune-orphaned-customers {--hours=48 : Grace period before pruning}`
  - Query: `CentralUser::whereNotNull('stripe_id')->where('updated_at', '<', now()->subHours($hours))` filtered to rows with no matching `subscriptions` row (no `Subscription::where('stripe_id', ...)` for any of the user's Stripe subscriptions — in practice: no subscription row at all references this customer, since these users never got that far).
  - For each match: `Cashier::stripe()->customers->delete($user->stripe_id)`, then `$user->update(['stripe_id' => null])`. Wrap the Stripe API call in try/catch (customer may already be gone) and log.
- Register in `routes/console.php` (already home to `Schedule::command('telescope:prune')->daily()`): `Schedule::command('billing:prune-orphaned-customers')->daily();`
- Skipping a `checkout.session.expired` webhook handler for now — can be added later as a faster/event-driven addition if needed, but the cron sweep is the reliable baseline and sufficient on its own.

## Files touched

- `app/Actions/Billing/Subscription/TransferSubscriptionToTenant.php` — signature change, drop `Session` dependency
- `app/Actions/Billing/Subscription/ProcessSuccessfulCheckout.php` — update call site to new signature
- `app/Http/Controllers/Billing/WebhookController.php` — delete `ensureTenantExists`/`ensureSubscriptionIsLinked`, call unified action
- `app/Actions/Tenancy/CreateTenant.php` — add unique-violation catch/idempotent return
- `app/Console/Commands/PruneOrphanedStripeCustomers.php` — new
- `routes/console.php` — schedule the new command

## Verification

- `vendor/bin/sail artisan test --compact --filter=Checkout` and `--filter=Webhook` (check existing Pest coverage under `tests/Feature` for these flows; extend if a race/idempotency test doesn't already exist).
- Manually re-run a real Stripe test-mode checkout (test card) end-to-end and confirm: tenant created once, subscription `subscribable_type/id` points at Tenant, `tenant.stripe_id` set — via `mcp__laravel-boost__database-query` against `subscriptions`/`tenants`.
- Simulate the race: temporarily delay the webhook (or replay it manually via Stripe CLI `stripe trigger customer.subscription.created` after the sync path already ran) and confirm no duplicate subscription rows / no 500.
- `vendor/bin/sail artisan billing:prune-orphaned-customers --hours=0 --dry-run` (add a `--dry-run` flag if useful) against the known orphan (`users.id=1`, `cus_UgEdp4H4fyU5Qe`) to confirm it's correctly detected before wiring the schedule.
- `vendor/bin/sail bin pint --dirty --format agent` after edits.
