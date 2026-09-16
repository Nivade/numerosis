# Two-factor authentication

**Status: not executed. Written 2026-09-16.** Wave 3 of
`saas-readiness-roadmap.md`. Cheapest high-value item in the set — Fortify
ships the mechanism and this package switches it off.

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
