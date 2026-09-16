# Closing a tenant, and getting it back

**Status: executed 2026-09-16 on `feat/tenant-close-and-recovery`.** Wave 1 of
`saas-readiness-roadmap.md`. Depends on `tenant-ownership-transfer.md`. See
"What shipped" at the bottom for the three places it deviates.

## Today: hard delete, no undo

`src/Console/Commands/DeleteTenants.php:23` deletes the tenant row, which
fires `TenantDeleted`, which chains stancl's `DeleteDatabase` job
(`src/Providers/TenancyServiceProvider.php:89`). The database is gone. There
is no confirmation beyond the command's own, no recovery window, and no
customer-facing way to close an account at all — a tenant who wants to leave
has to ask a human, and that human has one irreversible command.

A 30-day grace exists, but only for *suspended* tenants, and only in
`PruneOrphanedTenantDatabases.php:90`. Suspension is the billing path, not the
closure path.

## Decision

Owner-initiated close, 30-day recovery window, subscription cancelled at
period end. Chosen 2026-09-16 over staff-only closure. Thirty days matches the
grace `PruneOrphanedTenantDatabases` already uses, so there is one number in
the system rather than two.

## States

`tenants` gains `closed_at` alongside the existing `suspended_at` and
`provisioned_at` (`src/Models/Central/Tenant.php:52`). Deliberately not
Laravel's `SoftDeletes`: `deleted_at` on a stancl tenant interacts with
tenant resolution and every central query that joins it, and the package's own
prune commands already read explicit timestamps rather than trashed scopes.

| State | Reachable | Database | Billing |
|---|---|---|---|
| Active | yes | present | charged |
| Suspended (`suspended_at`) | blocked by `EnsureTenantSubscriptionActive` | present | dunning |
| Closed (`closed_at`) | blocked, closure notice shown | present | cancelled at period end |
| Purged | row and database gone | dropped | none |

Closed to purged is 30 days, and is the only transition that destroys data.

## Phases

### 1. `closed_at` and the guard

Migration, model cast, and a middleware branch. `EnsureTenantSubscriptionActive`
already blocks suspended tenants and renders `tenant.suspended`; closure needs
its own view, because "you did this and here is how to undo it" reads nothing
like "payment failed".

Owner and Admins can still reach the closure screen while closed. Everyone
else sees the notice only.

### 2. `CloseTenant` and `ReopenTenant` actions

`CloseTenant`: set `closed_at`, cancel the subscription at period end through
Cashier (never immediately — the customer paid for the period), fire
`TenantClosed`. `ReopenTenant`: clear `closed_at`, and resume the subscription
if it has not yet lapsed; if it has, send the owner to checkout.

Both idempotent.

### 3. Customer-facing close flow

On `/team`, Owner only. Requires typing the tenant name, password
confirmation, and states plainly: access stops now, data is kept 30 days,
purge on a named date, billing stops at period end. Offers the transfer path
as the alternative.

### 4. Purge

Extend `PruneOrphanedTenantDatabases` rather than adding a command — it
already holds the grace-period logic and the drop path. It gains the closed
cohort, and every purge takes a final backup first when
`tenant-backup-restore.md` has landed. Until then, purge of a closed tenant
stays behind a config flag defaulting to off, so nothing destroys data
unattended before there is a backup to destroy it against.

### 5. Staff view

Closed tenants listed in the staff panel with days remaining and a reopen
button, since the common real case is a customer changing their mind on day
three and mailing support.

## Tests

- Closing cancels at period end, not immediately; `ends_at` is set and the
  subscription is not deleted.
- A closed tenant's routes render the closure notice for members and the
  closure screen for the Owner.
- Reopen before the subscription lapses restores access with the same
  subscription id.
- Purge skips closed tenants inside the window and takes them outside it.
- Purge is off by default until backups exist.
- Closing is refused for a tenant in `past_due` without an explicit
  acknowledgement, so an unpaid balance is not closed away silently.

## Risks

- **`DeleteTenants` remains a hard delete.** Keep it — staff need it — but it
  should refuse a tenant inside its recovery window without `--force`, or the
  window is decorative.
- **Webhooks after closure.** Stripe keeps sending events for a cancelling
  subscription. `WebhookController` must not resurrect a closed tenant through
  `SuspendUnlessEntitled` or the subscription-updated path.

## What shipped

`closed_at` on `tenants`, `Contracts\Tenancy\Closable`, `CloseTenant` /
`ReopenTenant` / `AssertTenantClosable`, `TenantClosed` / `TenantReopened`, the
`account-closed` notice with the owner's reopen, `team/close` behind
`CloseTenantRequest` (typed name, `current_password`, unpaid-balance
acknowledgement) and `MembershipPolicy::manageClosure`, the closed cohort in
`tenancy:prune-orphaned-databases` behind
`numerosis.tenancy.closure.purge_closed`, and the recovery-window guard on
`tenants:delete`. Both webhook paths now stand aside for a closed tenant, and
`SuspendTenant` does too: reopening clears `closed_at` and would otherwise
leave `suspended_at` behind.

Three deviations.

**Phase 5 is two console commands, not a staff panel.** There is no staff
panel — `staff-admin-panel.md` has not been executed and Filament went in
Phase 1 — so the reopen-on-day-three case is `tenancy:closed-tenants` (days
remaining) plus `tenancy:reopen`, the same substitution
`tenant-ownership-transfer.md` made for the same reason.

**Grace days are one config key, and `--days` now defaults to it.**
`numerosis.tenancy.closure.grace_days` is the single number; the command's
`--days` falls back to it rather than to a literal 30, and the suspended
cohort excludes closed tenants so it cannot delete one the `purge_closed`
flag is holding.

**A reopen whose subscription has lapsed lands on `tenants.mine`, not on
checkout.** `checkout/subscription/new` needs a plan slug and there is no
plan-picker screen for an existing tenant to link to.

`Testing\FakeStripeHttpClient` gained subscription retrieve/update and
subscription-item retrieve, since `cancel()` and `resume()` are real API calls
and `Subscription::currentPeriodEnd()` reads the item, not the subscription.
