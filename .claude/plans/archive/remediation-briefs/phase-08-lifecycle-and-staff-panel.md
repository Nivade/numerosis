# Phase 8 — Tenant lifecycle and the staff panel

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P1, P2, P7, P4.

Rules to read: `.ai/rules/middleware-registration.md` (the closed/suspended
ordering and the `to_route()` trap), `.ai/rules/views.md`.

## 8.1 — Admins reach the closure screen (P1)

`tenant-close-and-recovery.md` says "Owner and Admins can still reach the
closure screen while closed".

- `src/Http/Middleware/EnsureTenantSubscriptionActive.php:22` — `handle()`
  redirects every role to `tenant.closed`, role-agnostic.
- `resources/views/pages/tenant/⚡closed.blade.php:27` — the `isOwner` computed
  property gates the reopen button.

So an Admin reaches the notice and sees nothing else on it.

Show the closure detail — who closed it, when, what happens next — to Owner and
Admin. **Reopen stays owner-only.** Do not touch the middleware's redirect: both
roles are meant to land on that screen, and the redirect is not what is wrong.

`.ai/rules/middleware-registration.md`: `tenant.closed` sits outside the
`tenancy.subscription` group deliberately, and both branches call `to_route()`,
which throws `UrlGenerationException` in path identification mode. A test cannot
see that in the default mode — `Tests\TestCase` forces a central root URL, so a
bare `assertRedirect()` passes either way. `TenantClosureTest` uses
`assertRedirectContains()` for that reason; keep doing so.

## 8.2 — Fold the scheduler age into the health status (P2)

- `src/Data/Observability/HealthReport.php:32` — `healthy()` reads the central
  database only.
- `src/Actions/Queries/GetSystemHealth.php:86` — `schedulerLastRunSeconds()`
  publishes the age from a cached heartbeat.

The age is published and never reaches the status code, so a stopped cron
reports 200.

**Decision, already made: fold it in.** A stopped scheduler means provisioning
has stopped, which is not a healthy system. Put it behind a configurable
threshold with a default, as a sibling key under the health config. A deployment
with no scheduler at all — or a heartbeat that has never been written — must not
report unhealthy on that basis alone; treat "never run" as unknown, not failed.

## 8.3 — The banner on every tenant layout (P7)

`resources/views/layouts/app/header.blade.php:7` is the only layout rendering
`x-numerosis::impersonation.banner`. `layouts/app/none.blade.php` renders none,
so a page on that layout impersonates with no banner, against the roadmap's
"persistent banner". Only the registration wizard uses that layout today, so
this is latent rather than live.

Add it. Then add a test that **every** tenant layout renders the banner, so the
next layout cannot reintroduce the gap — that test is the point of this item,
more than the one missing line.

## 8.4 — Record the extra staff panels (P4)

`staff-admin-panel.md`'s detail surface is "memberships, domains, subscription,
provision record". `resources/views/pages/staff/⚡tenant.blade.php:307` renders
those plus `entitlementUsage`, `appliedPromotions` (nested under subscription)
and a conditional `recentActivity`.

They are useful and already tested by
`tests/Feature/Staff/StaffTenantScreenTest.php` (10 tests). **Remove nothing.**
This item is a documentation change only: record them in that plan's "What
shipped" as an accepted addition. Do it in phase 12's pass, and note here that
8.4 is closed that way.

## Tests

- `tests/Feature/Tenancy/TenantClosureTest.php` (10 tests) — add: an Admin
  reaching the closed screen sees the closure detail and no reopen control; the
  Owner sees both.
- `tests/Feature/Observability/HealthEndpointTest.php` (4 tests) — add: a stale
  heartbeat past the threshold makes the endpoint report unhealthy; a heartbeat
  that has never been written does not.
- Impersonation banner: the new every-layout test. Enumerate the layout files
  from disk rather than listing them, or the test cannot catch the next one.

Prove each: write it, revert the fix, watch it fail, restore.

## Commit

```
fix(tenancy): admins see the closure, and a stopped cron is not healthy

The closure screen told Admins nothing. Both roles are redirected to it
while closed, which is right, but the screen gated all of its detail on
isOwner, so an Admin got a notice and no answer. Detail is Owner and Admin
now; reopening stays the Owner's.

The health endpoint published the scheduler's last-run age and never let
it reach the status code, so a host whose cron had stopped -- which means
provisioning has stopped -- still got a 200. The age counts now, behind a
configurable threshold, and a heartbeat that has never been written reads
as unknown rather than failed.

One tenant layout rendered no impersonation banner. Only the registration
wizard uses it, so nobody had been impersonated without one yet. The test
that came with the fix enumerates the layouts from disk, so the next one
cannot reopen the gap.

Closes P1, P2 and P7. P4 closed as an accepted addition, recorded in the
staff panel plan.
```
