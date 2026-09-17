# Support impersonation

**Status: ✅ Executed 2026-09-16** on `feat/support-impersonation`, the day it
was written. Wave 2 of `saas-readiness-roadmap.md`, on the shell
`staff-admin-panel.md` landed the same day. Deviations in "What shipped".

## Why it is being rebuilt

`Actions/Tenancy/ImpersonateTenantUser` was deleted in Phase 2 of the
six-package collapse. Without it, support cannot reproduce a tenant-side bug:
tenant data lives in a separate database behind a separate guard on a separate
hostname, so "log in as them" is the only way to see what they see. The
alternative that teams reach for otherwise — asking customers for passwords —
is worse than anything in this plan.

## Settled scope

Decided 2026-09-16:

| Question | Ruling |
|---|---|
| Who | Central `admin` role only, permission-gated, never a tenant member |
| How long | Short-lived signed token, 60 seconds to redeem, session capped at 60 minutes |
| Writes | Allowed. Read-only impersonation cannot reproduce the bugs support is chasing |
| Record | Every session start and end in the activity log, naming staff user, tenant, target user |
| Visible | Persistent banner for the whole session, with an exit button |
| Consent | Not required. Recorded here as the rejected alternative: owner-approval is safer and too slow for live support |

## Mechanism

stancl ships `Stancl\Tenancy\Features\UserImpersonation`, which is exactly
this: a token table, `impersonate()` to mint, a route to redeem, a signed
one-time link that logs the user in on the tenant domain. Use it rather than
hand-rolling session juggling across two guards.

What this package adds around it:

1. **Authorization** — `tenants` context permission plus an explicit
   `impersonate` ability, so it can be withheld from a staff user who may
   otherwise administer tenants.

   Decided 2026-09-16, before implementation. The permission is
   `impersonate tenants` on guard `web`, and **each seeder grows a protected
   `additionalActions()` mirroring its existing `contexts()`** — the central
   one returns `['tenants' => ['impersonate']]`, the tenant one delegates to
   `Permission::additionalActions()`, so that seam is untouched and
   `SeedsAdminRole` keeps one code path. Guard is already decided at the
   seeder, which is why the per-guard vocabulary belongs there rather than on
   the model, where a context-keyed map would seed a dead
   `impersonate tenants` row into every tenant database. This closes the
   central/tenant split recorded in `.ai/rules/auth-guards.md`, so update that
   bullet in the same pass.

   **The row is written whether or not the feature is enabled.** Spatie throws
   `PermissionDoesNotExist` on a missing name rather than denying, so seeding
   behind the flag turns "enable the feature on an existing database" into a
   500 on every page carrying the check. One unused row per install is the
   price.
2. **Audit** — a central `impersonation_sessions` row: staff user, tenant,
   target user, started, ended, token id. This is the compliance artefact;
   the activity log is the human-readable one.
3. **Banner** — rendered from the tenant layout whenever the impersonation
   marker is in session, naming both identities and offering exit.
4. **Exit** — ends the impersonated session, closes the audit row, returns to
   the staff panel's tenant detail.

## The session trap

`EnsureSessionMatchesTenant` exists because one session spans every subdomain
and `SessionGuard` stores only a primary key: without it, user id 2 on tenant
A authenticates as whoever id 2 is on tenant B. Impersonation deliberately
crosses that boundary, so it must go *through* the guard's rules, not around
them — the redeem step establishes a genuine tenant session for the target
user, with the impersonation marker carried alongside rather than instead of
the tenant check. Any implementation that special-cases the middleware is
wrong.

The staff user's own central session must survive, so exit returns them
without re-login.

## Phases

1. Migration and `ImpersonationSession` model, central connection.
2. `StartImpersonation` and `EndImpersonation` actions wrapping stancl's
   feature, opening and closing the audit row, firing
   `ImpersonationStarted` / `ImpersonationEnded`.
3. Redeem route on the tenant domain, signed, one-time, 60-second window.
4. Banner component in the tenant layout, behind the session marker.
5. Entry point: button on the staff panel's tenant detail, per member.
6. Expiry: sessions older than 60 minutes are ended by the guard on next
   request, and swept by the existing schedule.

## Tests

- Token is single-use and dead after 60 seconds.
- A staff user without the ability gets 403; a tenant Admin can never mint one.
- The impersonated session is a real tenant session — `EnsureSessionMatchesTenant`
  still rejects a mismatched tenant while impersonating.
- Banner renders for the whole session and the exit route restores the staff
  user's central session without re-login.
- Audit row opens and closes with both identities; an abandoned session is
  closed by expiry rather than left open.
- Writes made while impersonating are attributed in the activity log to the
  staff user, not the customer. This is the assertion that makes the audit
  trail worth having.

## Risks

- **Attribution.** Activity-log entries written during impersonation default
  to the authenticated (impersonated) user. Set the causer explicitly or every
  support action looks like the customer did it.
- **Notifications.** Actions taken while impersonating may mail the customer.
  Suppress outbound mail for impersonated sessions, or support will send
  confusing email from inside someone's account.

## What shipped

`Features\Admin\ImpersonationFeature` (`impersonation`), commented out in
`config('numerosis.features')`. `StartImpersonation` mints a stancl
`ImpersonationToken` and opens an `impersonation_sessions` row;
`RedeemImpersonation` spends it on the tenant host through
`UserImpersonation::makeResponse()`, stamps `started_at` and marks the session;
`EndImpersonation` closes the row and forgets the tenant guard's session
without resolving its user. `GuardImpersonation` sits on the `tenant`
middleware group, ends a session past the cap, and points spatie's
`CauserResolver` at the staff user for the rest of the request.
`impersonation:end-stale` sweeps what nobody came back to.

Five deviations, each with its reason:

- **The link carries no signature.** The token is 128 random characters, single
  use, with its own TTL. Signing it would mean generating a URL for another
  host, and path mode has no `URL::defaults(['tenant' => …])` to build one
  from — the same trap that keeps `route()` off tenant-group names.

  **Reversed 2026-09-18 by the readiness remediation (D1, phase 1, `e411bed`).**
  The roadmap had settled "short-TTL signed token" and this deviation
  contradicted it, so the link is signed. `StartImpersonation::sign()` builds
  the signature by hand over `$tenant->baseUrl().'/impersonate/'.$token`,
  HMAC-SHA256 under `app.key` with an `expires` stamp, which is the shape
  `Request::hasValidSignature()` verifies; the route carries Laravel's `signed`
  middleware. Building it by hand is what the foreign-host problem actually
  forces, not leaving it unsigned. The 128-character single-use token and its
  TTL stay, with the signature as defence over them.
- **`Authenticate` skips its central-to-tenant promotion while impersonating.**
  Otherwise a staff user who happens to be a member of the tenant is signed
  back in as themselves and the impersonation ends with no symptom. The
  session-tenant check is untouched, which is what the plan's "do not
  special-case the middleware" is about.
- **Mail suppression is a listener returning `null`, never `true`.**
  `Dispatcher::until()` returns the first non-null response, so answering
  `true` outside impersonation would stop every other listener on
  `MessageSending`/`NotificationSending` from running at all.
- **`GetCurrentImpersonation` is not memoized.** A static would hand the next
  request on the same worker somebody else's session.
- **`Tenant::baseUrl()` and `Routing\RouteUrls`** are new, because both the
  redeem link and the exit redirect cross hosts, in three identification modes.

The permission decision recorded above shipped with it: each seeder now owns
its guard's non-CRUD vocabulary in a protected `additionalActions()`, and
`.ai/rules/auth-guards.md`'s bullet was rewritten to match.
