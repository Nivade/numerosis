# Support impersonation

**Status: not executed. Written 2026-09-16.** Wave 2 of
`saas-readiness-roadmap.md`. Needs the shell from `staff-admin-panel.md`.

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
