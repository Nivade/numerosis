# Two-factor authentication

**Status: executed 2026-09-17 on `feat/two-factor-authentication`.** Written
2026-09-16. Wave 3 of `saas-readiness-roadmap.md`. See "What shipped" at the
bottom for the three places it deviates.

## Where it stands

`HostConfig.php:407-411` enables Fortify's registration, password reset,
profile, password update and email verification features.
`Features::twoFactorAuthentication()` is not among them, and the install
command's config check already warns about its absence. `Features::passkeys()`
is likewise off.

So today: password or OAuth or emailed one-time code, and nothing else. No
TOTP, no recovery codes, no way for a tenant to require a second factor of its
members. That last one is the item enterprise buyers ask about by name.

## Settled scope

Decided 2026-09-16: **per-user opt-in, per-tenant requirement toggle,
mandatory for central `admin`.** All three, because they are one mechanism and
three switches.

## Phases

### 1. Turn Fortify's feature on

Add `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`
in `HostConfig`. `confirm` matters: without it, enabling 2FA does not verify
the user can actually produce a code, and the first thing they discover is a
lockout.

Migration for Fortify's columns on both user models — `two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at`. Tenant users and
central users both need them; the two guards authenticate separately, so a
factor enrolled centrally does not protect the tenant login and vice versa.

**Decision inside this phase:** whether enrolment is per-guard (two secrets)
or central-only with the tenant guard trusting it. Recommend **per-guard**,
matching how everything else in the package treats the two guards, and
document that a user enrols twice. Central-only sounds friendlier and quietly
means a tenant's 2FA requirement is enforced by the *central* login, which is
not what the tenant asked for.

### 2. Screens

`Livewire\Settings\TwoFactor`, alongside the four settings components that
exist: enable, show QR and secret, confirm with a code, display recovery
codes once, regenerate them, disable behind password confirmation.
The challenge view for login, matching the one-time-password challenge view
already in the tree.

### 3. Rate limiting

The 2FA challenge needs its own limiter. The login limiter is five attempts
per minute keyed on tenant, email and IP
(`NumerosisServiceProvider.php:603`), and the OTP challenge already has a
separate one — follow that pattern rather than reusing either. Six digits at
five tries a minute is still brute-forceable over hours, so the challenge
limiter should be tighter and should lock the session, not just the IP.

### 4. Per-tenant requirement

`tenants.requires_two_factor`, a boolean the Owner sets on the team screen.
Enforced by middleware on the tenant group: an authenticated member without a
confirmed factor is redirected to enrolment and can reach nothing else.
A grace period (`requires_two_factor_from`) so turning it on does not lock the
team out mid-afternoon.

### 5. Mandatory for staff

Central users holding the `admin` role cannot reach the staff panel without a
confirmed factor. No grace, no toggle. The panel can suspend tenants and
impersonate users; it is the highest-value credential in the system.

### 6. Recovery

Recovery codes are Fortify's. What this adds is the support path: a staff user
can clear a target user's 2FA from the staff panel, always activity-logged,
never silently. Without it, every lost phone is a database edit.

## Tests

- Enrolment requires confirmation; an unconfirmed secret does not challenge at
  login and does not satisfy a tenant requirement.
- Recovery code logs in once and is then dead.
- Challenge limiter locks after its threshold and the lock is per session, not
  only per IP.
- A tenant with `requires_two_factor` redirects an unenrolled member to
  enrolment from every tenant route, and the grace period suppresses that
  until it lapses.
- Staff panel is unreachable without a confirmed factor even for a user with
  every permission.
- Staff clearing a user's factor writes an activity-log entry naming both.
- 2FA composes with OAuth login and with the one-time-password feature —
  each of those is a first factor, and the challenge still fires.

## Risks

- **Interaction with `OneTimePasswordFeature`.** Emailed OTP replaces the
  password step. Layering TOTP on top means two codes, which is absurd; treat
  an emailed OTP login as already two-factor and skip the challenge, but do
  **not** let it satisfy a tenant requirement — email possession is a weaker
  factor and the tenant asked for a device.
- **Passkeys.** Fortify has them, and they make the TOTP flow redundant for
  users who adopt them. Out of scope here, but do not design the enrolment
  screen as if TOTP is the only possible factor.

## What shipped

Three deviations from the plan above, and a fourth that shipped unrecorded and
has since been closed.

**Enrolment is central-only, not per-guard.** Phase 1 recommended two secrets,
one per guard, on the premise that "the two guards authenticate separately".
They do not: Fortify's `StatefulGuard` binding resolves
`config('fortify.guard')`, which is `'web'` for the whole process, so a login
on a tenant domain checks credentials against the *central* provider and
`Http\Middleware\Authenticate` then promotes that session into the tenant
guard. A secret on a tenant user would never be challenged by anything. So the
columns live on central `users` only, `RouteLoader` strips the two-factor
feature out of `fortify.features` while it registers the tenant group — leaving
Fortify's enrolment endpoints on the central domains — and hand-registers the
challenge routes there, since a login that begins on a tenant domain has to
finish on it.

**The challenge limiter keys on `login.id`, not the session id.** Phase 3 asked
for the lock to follow the session. Keying on the challenged account is
strictly stronger: cycling the session cookie no longer buys a fresh set of
guesses, and the request never carries that id, so a caller cannot choose whose
bucket to spend. 5 attempts per 5 minutes, against login's 5 per minute. The
session-id version was also untestable — the test harness hands every request a
new session id.

**The enrolment screen calls Fortify's actions rather than posting its routes.**
`Livewire\Settings\TwoFactor` behind `password.confirm.if-set`, which
`Livewire::addPersistentMiddleware()` re-applies to `/livewire/update` —
route middleware does not reach it. Fortify's own endpoints keep
`confirmPassword => true`, so a stolen session cannot enrol through them, while
an account that registered through OAuth and has no password can still enrol
through the screen.

**The tenant requirement gated only this package's routes, and no longer does.**
`EnsureTwoFactorEnrolled` was kept off the `tenant` middleware group on the
grounds that it redirects to a central route, so a tenant that required a second
factor still let an unenrolled member reach the dashboard and every route a host
added. Closed 2026-09-18 by the readiness remediation (P8, phase 1, `e411bed`):
the alias sits on the `tenant` group, since redirecting to a *central* route is
exactly what makes it safe there — the subscription gate is the one that would
loop. The team screen and the requirement switch opt out, so an owner who let
the grace period lapse can still reach what turned it on.
`TenantTwoFactorRequirementTest` asserts the dashboard and a product route, not
one route.

Two smaller notes. `RevokeSessionsAfterTwoFactorDisabled` now reads
`$event->user` instead of the current guard's id — it was revoking the *staff*
user's sessions when staff cleared someone else's factor. And
`EnsureStaffTwoFactor` sits after the `can:` check on the staff group, so a
visitor with no staff permissions still gets a 403 rather than an invitation to
enrol.
