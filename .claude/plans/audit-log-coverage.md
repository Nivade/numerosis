# Audit log: full coverage and a way to read it

**Status: executed 2026-09-17.** See "What shipped" at the bottom. Wave 3 of
`saas-readiness-roadmap.md`.

## What is logged today

One model. `src/Models/Tenant/User.php:69` composes `LogsActivity` with
`logAll()`. `spatie/laravel-activitylog` is a `require`, the central
`activity_log` table is migrated (`2026_01_19_151109`), and nothing else
writes to it.

Unlogged: `Tenant` (created, suspended, restored, closed), `Membership`
(joined, role changed, removed), `Subscription` (created, swapped,
cancelled), `Invitation` (issued, accepted, revoked), `SocialAccount`
(linked, unlinked), `CentralUser` (profile and email changes). Every one of
those is a question a customer or an auditor asks after the fact.

There is also no screen. The table is write-only in practice.

## Two logs, deliberately

| Log | Home | Read by |
|---|---|---|
| Central `activity_log` | central connection | staff, and tenant owners for their own tenant |
| Tenant `activity_log` | tenant database | that tenant only |

Tenant-side user activity stays in the tenant database, where it already is.
Central events about a tenant — suspension, ownership transfer, plan change —
are central rows scoped by subject. Do not merge them: a single log means
either tenant rows in the central database or central rows duplicated per
tenant, and both leak.

## Phases

### 1. Coverage

Compose `LogsActivity` onto the central models named above, each with an
explicit `logOnly()` list rather than `logAll()`. `logAll()` on `Subscription`
writes a row for every Stripe webhook field change, which buries the events
worth reading. Never log `two_factor_secret`, `remember_token` or anything
Stripe-secret-shaped — an audit log that contains credentials is worse than no
audit log.

### 2. Domain events, not just attribute diffs

Attribute diffs answer "what changed"; auditors ask "what happened". The
package already fires twenty-odd domain events. Add a listener that writes a
named activity entry per meaningful event — `TenantSuspended`,
`MemberRemoved`, `InvitationAccepted`, `TenantOwnershipTransferred`,
`ImpersonationStarted` — with the event's scalars as properties.

Registration goes in the existing explicit `Event::listen()` map. Listeners
are unordered and are reactions only; this one writes a row and nothing else.

### 3. Causer correctness

Two traps, both of which silently produce a wrong log:

- **Impersonation.** Entries written while a staff user is impersonating
  default to the impersonated user. Set the causer explicitly to the staff
  user and record the impersonated identity as a property.
- **Queued jobs.** Provisioning steps run with no authenticated user, so the
  causer is null. Set a system causer rather than leaving rows that look
  anonymous.

### 4. Screens

Tenant-side: "Activity" on the team screen, that tenant's log, Owner and Admin
only, filterable by actor and date. Staff-side: the central log on the tenant
detail screen, plus a global feed.

### 5. Retention

Activity logs grow without bound and contain personal data, so retention is
both an operations and a compliance question. `activitylog:clean` is the
package's, scheduled behind `numerosis.schedule.*` like the other prunes, with
the retention window configurable and documented in `docs/host-requirements.md`
as a decision the host owns.

## Tests

- Suspending a tenant writes exactly one entry naming the actor and the
  tenant.
- No entry ever contains `two_factor_secret` or a Stripe secret, asserted
  across every logged model by inspecting stored properties.
- An action performed while impersonating records the staff user as causer and
  the impersonated user as a property.
- A provisioning step's entry has the system causer, not null.
- Tenant-side log is invisible to another tenant — the central-model-on-tenant-route
  scoping trap applies to the reading screen too.
- Retention command deletes outside the window and nothing inside it.

## Risks

- **Volume.** `logAll()` on `Subscription` plus Stripe's webhook chatter will
  write thousands of rows per tenant per month. The `logOnly()` lists are the
  control; write them before turning the trait on, not after.
- **`activity_log` exists centrally and per tenant.** Two tables, same name,
  different connections. Any query that forgets which connection it is on
  reads the wrong one and returns plausible, wrong data.

## What shipped

All five phases, 2026-09-17.

- `Models\Activity` replaces Spatie's, wired through
  `activitylog.activity_model`. It resolves its connection from the subject: a
  central-connection subject writes centrally, so a `Membership` attached
  inside a tenant request no longer records against a tenant database. It also
  stamps two properties on every entry — `impersonated_global_id` and an
  `actor` of `user`/`staff`/`system` (`Enums\Audit\ActivityActor`), which is
  phase 3 for both the impersonation and the queued-job halves.
- `activity_log.subject_id` is a string column now, not `unsignedBigInteger`.
  Nothing about a tenant could be logged before this: `tenants.id` is a UUID.
  `causer_id` moved with it.
- `logOnly()` lists on `Tenant`, `Membership`, `Subscription`, `Invitation`,
  `SocialAccount` and `CentralUser`, plus
  `activitylog.default_except_attributes` holding the four credential columns,
  both backfilled by `HostConfig` and checked by two new `verify*()` methods.
- `Listeners\Audit\RecordDomainEventActivity` on nine events, registered from
  its own loop: three of them already had a listener in the map, and an event
  may only be a key there once.
- Screens behind `Features\Audit\ActivityLogFeature` (on by default), and
  `Actions\Queries\ReadActivityLog` as the one place either log is read from.
  `MembershipPolicy::viewActivity()` is what keeps a plain member out.
- Retention is `numerosis:prune-activity-log`, not Spatie's
  `activitylog:clean`. That command deletes through the model's *default*
  connection, which is the tenant's inside tenancy — under `RefreshDatabase`
  it silently deleted nothing, which is how this was found.

One test-support fix came with it: `Tests\Support\SubscriptionWithoutStripe`
had to name its `items()` foreign key, since logging reads the model and
Cashier eager-loads that relation off the class basename.

Not done: nothing. The plan's tenant-side "that tenant's log" is the central
entries scoped to the tenant rather than the tenant database's own table —
the tenant table holds tenant-user attribute diffs, and the questions the
screen exists to answer (who was removed, when a role changed) are central.
