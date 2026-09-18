# Staff admin panel

**Status: ✅ Executed 2026-09-16** on `feat/staff-admin-panel`, the day it was
written. Wave 2 of `saas-readiness-roadmap.md`, and the shell three other
plans land screens in. Deviations in "What shipped" at the bottom.

## Why there is nothing

`nvade/numerosis-filament` — admin and tenant panels — was deleted outright in
Phase 1 of the six-package collapse, along with `filament/filament`.
`tests/Feature/PackageBoundariesTest.php` fails if any `Filament\` symbol is
named anywhere in the tree. Nothing replaced it.

The consequence is that the host operating this package cannot list tenants,
see why a provision failed, suspend or restore by hand, or look at a
subscription, except through tinker and `database-query`.

Meanwhile the permission vocabulary for all of it is already seeded:
`database/seeders/RoleAndPermissionSeeder.php:26` grants an `admin` role 63
permissions across seven central contexts — `tenants`, `subscriptions`,
`payment_plans`, `users`, `roles`, `permissions`, `features` — and nine
policies already gate on them. The authorization layer is waiting for screens.

## Decision: Livewire and Flux, off by default

Settled 2026-09-16. Reasons, recorded because this reverses a deletion:

- The package ships to hosts. `filament/filament` in `require` puts its
  version constraints, panel provider, asset pipeline and Tailwind config on
  every consumer.
- `nvade/numerosis-ui` already requires `livewire/flux`. Admin screens in Flux
  cost no new dependency and one design language.
- Filament's own panel tenancy assumes scoping through the panel; tenancy here
  is connection switching, two guards and `EnsureSessionMatchesTenant`. That
  integration surface is what was deleted.
- Scope is five screens. Filament repays scaffolding across dozens of
  resources.

`StaffPanelFeature` ships commented out in `config('numerosis.features')`,
exactly as `OneTimePasswordFeature` does. A host adopting the package into an
existing app likely has its own admin, and an unstyled extra panel appearing
on their central domain is a surprise.

## Surface

Central domain, `web` guard, prefix configurable through
`numerosis.routes.staff_prefix`, default `/staff`. Not `/admin` — hosts use
that name for their own product.

| Screen | Reads | Acts |
|---|---|---|
| Tenants index | `Tenant` with owner, plan, status, provisioned date | filter by suspended, closed, failed |
| Tenant detail | memberships, domains, subscription, provision record | suspend, restore, reopen, reassign owner, impersonate |
| Provisions | `TenantProvision` including `step_records` | retry, cancel |
| Subscriptions | `Subscription` with plan and status | open in Stripe |
| Users | `CentralUser` with tenants | none beyond view |

Every mutation goes through an existing action — `SuspendTenant`,
`RestoreTenant`, `TransferTenantOwnership` — never through the model in a
component.

## Phases

### 1. Feature class, routes, guard

`Features\Admin\StaffPanelFeature`, registering nothing when absent from
config. Routes gated by `auth` plus a `can:` check on the `tenants` context,
so a central user without staff permissions gets a 403 rather than a login
loop.

### 2. Layout shell

One Flux layout with navigation, reused by every screen and by the plans that
add screens later. This is the shared foundation — `support-impersonation.md`,
`provisioning-observability.md` and `fleet-tenant-migrations.md` all assume
it exists.

### 3. Tenants index and detail

Paginated, searchable by name, slug and domain. Detail reads across both
connections: central rows directly, tenant-side counts through
`tenancy()->initialize()` in a read-only block. Tenant-side reads must be
explicit about the connection switch and must end tenancy afterwards.

### 4. Mutations

Suspend, restore, reopen, reassign owner. Each confirms, each writes to the
activity log with the acting staff user, each is policy-gated.

### 5. Subscriptions and users

Read-only. A deep link to the Stripe dashboard beats reimplementing billing
screens.

## Tests

- Panel registers nothing when the feature is absent, including no routes and
  no navigation.
- A central user without `tenants` permissions gets 403 on every route.
- Tenant-side reads leave tenancy ended — the classic leak, and the reason
  `CleansUpTenancyDatabases` exists.
- Every mutation writes an activity-log entry naming the staff user.
- Suspend and restore go through the existing actions, asserted by faking
  them.

## Risks

- **Central rows on a central route with no scope is fine; the reverse is
  not.** These screens list every tenant deliberately. The policies must not
  be copied to tenant-side screens where they would be far too permissive.
- **`PackageBoundariesTest` stays green** only if nothing here reaches for a
  Filament symbol out of habit. Keep the assertion.

## What shipped

`Features\Admin\StaffPanelFeature` (`staff_panel`), commented out in
`config('numerosis.features')`, with `numerosis.routes.staff_prefix` defaulting
to `staff`. Five Livewire single-file pages under
`resources/views/pages/staff/`, reached as `numerosis-pages::staff.*` through a
route group carrying the central guard plus
`can:viewAny,<Numerosis::model(Tenant::class)>`. One Flux layout,
`numerosis-layouts::staff`, holds the navigation.

Four deviations from the plan above:

- **Impersonation is not on the detail screen.** It was deleted in Phase 2 of
  the six-package collapse and `support-impersonation.md` is what rebuilds it;
  the surface table listed it, the phases did not.
- **The detail route parameter is `{tenantId}`, not `{tenant}`.** Under the
  path identification mode `{tenant}` is the parameter that initializes
  tenancy, and these are central screens.
- **Activity entries carry no `performedOn()`.** `activity_log.subject_id` is
  an integer column and a tenant key is a string, the same reason
  `TransferTenantOwnershipCommand` logs with `withProperties()` only.
- **The tenants index formats `provisioned_at` itself.** `Tenant` casts
  `suspended_at` and `closed_at` and not that one, so it arrives as the raw
  column value.

Phases 3 and 5 are narrower than written: the index searches name, slug and
domain and filters by status, and the subscriptions screen deep-links to
Stripe rather than reading Cashier state beyond plan, status and end date.

**The tenant detail surface is wider than the four panels above.** Later wave-4
plans added `entitlementUsage()`, `appliedPromotions()` and `recentActivity()`
to `⚡tenant.blade.php`. The readiness review flagged them as undocumented
scope (P4) and they are accepted as additions rather than removed, 2026-09-18:
each is useful on an operator's one screen and each is already tested. The
documented surface is memberships, domains, subscription, provision record,
entitlement usage, applied promotions and recent activity.
