# Plan: expand core's domain events

**Status:** approved 2026-09-04, not yet executed.
**Order:** execute this **before** `.claude/plans/invitations-social-redesign.md`.
That plan depends on `Actions\Tenancy\EnsureTenantUserExists` and
`MembershipObserver::created()`, both created here.
**Executor:** a fresh session. Read this file top to bottom before touching code.

## Why

Core dispatches 9 events. Whole domains dispatch none: a membership changing
is silent, deleting an account is silent, a plan change is silent. A host
integrating this package has no hook for seat-based billing, offboarding,
audit, or entitlement recomputation, and core itself has one outright bug from
reusing the wrong event.

Note before you start: **the tenancy lifecycle is already fully evented.**
`Providers\TenancyServiceProvider::events()` wires ~25 stancl events with empty
listener arrays — `CreatingTenant`, `DeletingTenant`, `DomainCreated`,
`DatabaseCreated/Migrated/Seeded/Deleted`, `TenancyInitialized`,
`TenancyEnded`. Do **not** add a core event that duplicates one of those. The
gaps this plan fills are all in core's own domains.

## The bug this starts with

`Actions\Tenancy\RestoreTenant` dispatches `Events\Billing\PaymentSettled`.
`NumerosisServiceProvider` maps `PaymentSettled` → `SendPaymentConfirmedNotification`.
So un-suspending a tenant emails its owner a payment-confirmation for a
payment that did not occur. Restoration is not a settlement; they only share a
notification today by accident.

## Read before you start

- `CLAUDE.md`; `.ai/rules/index.md`, then `tenant-provisioning.md`,
  `billing-checkout.md`, `testing.md`, `architecture-conventions.md`.
- `src/NumerosisServiceProvider.php` — the explicit `Event::listen()` map in
  `packageBooted()` (~line 415-435) and `registerPolicies()` above it.
- `src/Providers/TenancyServiceProvider.php::events()` — the stancl map.
- `src/Events/Tenancy/TenantProvisioned.php` — the shape to copy for anything
  that broadcasts.

---

## Conventions for every event added here

1. **Past tense, domain fact.** `MemberJoined`, not `AddMember` or
   `MemberJoinEvent`. Namespace `src/Events/<Domain>/`, matching the existing
   `Auth/ Billing/ Invitations/ Tenancy/` split.
2. **Carry scalars alongside models.** `SerializesModels` re-queries on
   unserialize, which fails for a deleted model and resolves against the wrong
   connection for a tenant-scoped one. Every event here carries the ids it is
   about (`tenantId`, `globalUserId`) even when it also carries the model.
   `TenantProvisioned` already does this with `ownerId` + `broadcastWith()`.
3. **`ShouldDispatchAfterCommit`** on anything dispatched from inside a
   transaction. `event()` fires immediately; a queued listener can otherwise
   read a row that has not committed, or one that never will.
4. **Listeners are unordered — never encode a sequence across two of them.**
   Work core *must* do stays in an action that the code path calls directly.
   Events are for reactions. The one deliberate exception is documented in
   Phase 2 and is idempotent by construction.
5. **Broadcast only where a UI is waiting.** `TenantProvisioned` broadcasts
   because the registration wizard polls for it. Nothing in this plan
   broadcasts. Do not add `ShouldBroadcast` "for completeness" — it costs a
   channel authorization and a payload contract.
6. **A new event with no dispatch site is not built.** See "Deferred" at the
   bottom.

---

## Phase 1 — `TenantRestored`, and stop the phantom receipt

- `src/Events/Tenancy/TenantRestored.php` — `Tenant $tenant`, `string|int $ownerId`.
- `Actions\Tenancy\RestoreTenant` dispatches it instead of `PaymentSettled`.
- Decide what the owner is told on restore. Two acceptable outcomes; pick one
  and make it explicit:
  - a new `Notifications\Tenancy\TenantRestored` + a
    `Listeners\Tenancy\SendTenantRestoredNotification`, registered in the
    listener map (preferred — the tenant *was* suspended, the owner should
    learn it is back);
  - or no notification at all, and `TenantRestored` exists purely as a host
    hook.
- **Do not** leave `PaymentSettled` wired to restoration in any form.

Test: restoring a suspended tenant sends no `Billing\PaymentConfirmed`
notification, and dispatches `TenantRestored` exactly once. Restoring a tenant
that is not suspended dispatches nothing (the existing early return).

---

## Phase 2 — membership events, and the tenant-side user row

Today `MembershipObserver` only busts a cache. This phase gives memberships
events *and* makes "a membership implies a tenant-side `users` row" a single
enforced invariant instead of a block copy-pasted per call site.

### The mechanism you must understand first

`CentralUser::tenants()` is `->using(Membership::class)`, so `attach()` goes
through `attachUsingCustomClass()` → `newPivot()->save()` and pivot model
events fire. `Membership extends TenantPivot`, whose `boot()` hooks `saved` →
`CentralUser::triggerSyncEvent()` → `SyncedResourceSaved` →
`Listeners\Tenancy\UpdateSyncedResource`, which creates the tenant-side row if
missing. That listener is **queued** (`$shouldQueue = true`, `tries = 20`,
`backoff = 20`), which is why `Actions\Tenancy\AddTenantOwner` also writes the
row by hand: the owner must be able to log in on the redirect out of checkout.

A raw `DB::table('memberships')->insert()` bypasses all of it silently. Attach
through the relation, always.

### 2a. `Actions\Tenancy\EnsureTenantUserExists` — one implementation

`handle(Tenant $tenant, CentralUser $user): void`. Inside `$tenant->run()`,
`firstOrCreate` the tenant-side `User` on `global_id`, copying `name`, `email`,
`password`, `email_verified_at`, `is_bot => false` — lift the payload verbatim
from `AddTenantOwner`.

Two things it must get right:

- **Recursion.** The tenant `User` composes `ResourceSyncing`, so creating it
  fires its own `saved` → a `SyncedResourceSaved` with tenant context → a write
  back to central. Wrap the create in `$tenantUserClass::withoutEvents(...)`,
  exactly as `Stancl\Tenancy\Listeners\UpdateSyncedResource::updateResourceInTenantDatabases()`
  does.
- **Idempotency.** `firstOrCreate` on `global_id` — the queued stancl listener
  will run over the same tenant moments later, and Phase 2c may too.

Then **delete the `$tenant->run()` block from `AddTenantOwner`**, leaving it
the attach plus its existing early return. Provisioning tests must stay green
across that edit; a red one means the observer did not fire, not that the
observer is wrong.

### 2b. `MembershipObserver::created()`

- Resolve the `CentralUser` by `global_user_id` and the `Tenant` by `tenant_id`.
- If the tenant is provisioned, call `EnsureTenantUserExists`. **If it is not,
  do nothing** — Phase 2c backfills. Check provisioning state off the tenant
  (`PendingTenantProvision` / whatever `MarkTenantProvisioned` sets); do not
  try/catch a missing-database exception as the control flow.
- Dispatch `Events\Tenancy\MemberJoined($tenantId, $globalUserId, $role, $invitedBy)`.

`MembershipObserver::deleted()` (already exists, busts cache) additionally
dispatches `Events\Tenancy\MemberRemoved($tenantId, $globalUserId, $role)`.

**Why the row-creation lives in the observer and not in a listener on
`MemberJoined`:** it is a data invariant core itself depends on, not a
reaction. Convention 4 above. A host may listen to `MemberJoined`; it may not
be responsible for core's own consistency.

### 2c. `Listeners\Tenancy\BackfillTenantUsers` on `TenantProvisioned`

Iterate that tenant's memberships and call `EnsureTenantUserExists` for each.
This is what closes the window where somebody is attached to a tenant whose
database does not exist yet — deterministically, at the moment the database
becomes ready, instead of hoping 20 retries at 20s covers it.

Register it in the `packageBooted()` listener map. Queued is fine here (the
user is not waiting on it), but it must be idempotent, which 2a guarantees.

After this phase there are three independent nets, in order of preference:
observer (synchronous, normal path) → `TenantProvisioned` backfill
(deterministic, provisioning race) → stancl's queued `UpdateSyncedResource`
(last resort, and now genuinely a last resort).

Tests:
- attach a membership to a provisioned tenant → tenant-side row exists **with
  the queue faked**. A test that lets `UpdateSyncedResource` run passes either
  way and proves nothing.
- attach a membership to an unprovisioned tenant, then fire `TenantProvisioned`
  → row exists, and the observer threw nothing on the way.
- `MemberJoined` / `MemberRemoved` dispatch with the right payload.
- existing provisioning tests still green after `AddTenantOwner` is trimmed.

---

## Phase 3 — account lifecycle

`Actions\Auth\DeleteUserAccount` dispatches nothing. A host has no hook to
purge or export its own rows, and no way to veto.

- `Events\Auth\UserAccountDeleting(CentralUser $user, string $globalId)` —
  dispatched **before** `$user->delete()`, while the data is still readable.
  This is the one that matters; a listener cannot read a deleted user.
- `Events\Auth\UserAccountDeleted(string $globalId, string $email)` — scalars
  only. Do not pass the model; it no longer exists.

Note the action's existing early return (owners cannot delete their account)
must dispatch **neither** event.

Tests: deleting a non-owner dispatches both, in order, and the *-ing* listener
can still read `$user->tenants`. An owner attempting deletion dispatches
neither.

---

## Phase 4 — billing semantics

Cashier gives raw Stripe webhooks; core exposes only settled / failed /
suspended. Add the tenant-scoped facts a host actually reacts to. Dispatch
sites are all in `Http\Controllers\Billing\WebhookController` and
`Actions\Billing\Checkout\*` — read `.ai/rules/billing-checkout.md` first,
especially on `subscribable_id` and stale `stripe_status`.

| Event | Payload | Dispatch site |
|---|---|---|
| `Billing\SubscriptionPlanChanged` | tenant, fromPriceId, toPriceId, direction (upgrade/downgrade) | `handleCustomerSubscriptionUpdated()`, and `Actions\Billing\Checkout\StartPlanChangeCheckout`'s settle path — dispatch from **one** of them, whichever observes the completed change, not both |
| `Billing\SubscriptionCancelled` | tenant, gracePeriodEndsAt | `handleCustomerSubscriptionDeleted()` |
| `Billing\CheckoutStarted` | tenant-or-domain, planId, sessionId | `StartSubscriptionCheckout` |
| `Billing\CheckoutCompleted` | tenant, planId, sessionId | `SettleCheckout` / `CompleteRedirectCheckout` — whichever is the single point both the sync redirect and the async webhook funnel through |

`SubscriptionCancelled` is deliberately distinct from the existing
`TenantSuspended`: cancellation is the customer's intent, suspension is core's
enforcement, and they can be days apart. A retention flow hangs off the first;
an access check off the second.

For `CheckoutStarted`/`CheckoutCompleted`, `.ai/rules/tenant-provisioning.md`
documents a race between the synchronous checkout redirect and the async Stripe
webhook. These events make the ordering observable instead of inferred from
logs — but do **not** make anything in core depend on their order.

Tests: one per event, asserting payload. For the checkout pair, assert that the
race path (webhook first, redirect second) still dispatches `CheckoutCompleted`
exactly once.

---

## Phase 5 — provisioning and domain ops

| Event | Payload | Dispatch site | For |
|---|---|---|---|
| `Tenancy\TenantProvisioningStarted` | domain, globalId | `Actions\Tenancy\MarkProvisionInProgress` | Symmetry — `Failed` and `Cancelled` exist, `Started` does not. Progress UI, timing metrics |
| `Tenancy\TenantDomainReserved` | tenant-or-domain string, mode | `Actions\Tenancy\ReserveTenantDomain` / `CreateTenantDomain` | The seam for DNS automation and certificate issuance in `custom_domain` mode. Today a host is never told a domain appeared |
| `Auth\AdminGranted` | globalId, grantedBy (nullable) | `Actions\Auth\PromoteFirstCentralUserToAdmin`, `Actions\Tenancy\PromoteFirstUserToAdmin` | Privilege escalation should be observable by default |

`TenantProvisioningStarted` should **not** broadcast even though
`TenantProvisioned` does — check whether the registration wizard actually needs
it client-side before adding a channel. If it does, copy `TenantProvisioned`'s
`broadcastOn`/`broadcastAs`/`broadcastWith` shape exactly and add nothing else.

---

## Phase 6 — make them a documented seam

Events are public API the moment a host listens to one. Add an **Events** table
to `docs/extending.md` (a section, not a new file): event → when it fires →
payload → typical use. Include the 9 existing events, not just the new ones,
and point at `TenancyServiceProvider::events()` for the stancl lifecycle a host
can also hook.

Also state the two things a host will otherwise get wrong:

- **Listener auto-discovery never scans a package's `src/`.** Core registers
  its own with an explicit `Event::listen()` map in `packageBooted()`. A *host*
  registering listeners in its own `app/Listeners` is discovered normally —
  that asymmetry is worth one sentence.
- Core's events are dispatched with `event()`, so a host listener throwing
  takes down the request that dispatched it unless the listener is queued.

Record a rule via `record-rule` (glob `src/Events/**`, `src/Listeners/**`,
`src/Observers/**`) covering conventions 2, 3 and 4 above plus the membership
chain from Phase 2 — pivot events fire only because of `->using()`, stancl's
sync is queued, hence the observer, hence a raw insert into `memberships`
produces a member with no tenant-side user row and no error.

---

## Deferred — real gaps with no dispatch site yet

Do not build these now; there is nothing to fire them from, and a speculative
event is a maintenance cost with no consumer. Listed so the next person knows
they were considered:

- `MemberRoleChanged` — nothing in the codebase changes a pivot role. There is
  no `updateExistingPivot` call anywhere. Add the event **with** the feature
  that changes roles.
- `TenantOwnershipTransferred` — no transfer path exists. It is the event that
  matters most when it does, because the Cashier subscriber changes with it.
- `SubscriptionTrialEnding` — needs a scheduled sweep over trial end dates,
  which does not exist. Belongs with that command.
- `TenantSessionPromoted` (central→tenant guard promotion, for audit and
  anomaly detection) — the promotion happens inside `Actions\Auth\LoginUser`
  and `Http\Middleware\Authenticate`; locate the single point both funnel
  through before adding it, or it fires two or three times per request.

---

## Definition of done

```
vendor/bin/pint --dirty --format agent     # first — Pint edits files
composer analyse                            # PHPStan level 9, no new baseline entries
composer test
```

Every event added here has at least one test asserting it dispatches with the
right payload, and Phase 1 has a test asserting the phantom notification is
gone. No new `phpstan-baseline.neon` entries.
