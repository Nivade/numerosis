# Security hardening pass

**Status: executed 2026-09-17.** See "What shipped" at the bottom. Wave 3 of
`saas-readiness-roadmap.md`. Three unrelated small items kept together because
each is an afternoon and none justifies its own session.

## Item 1 — breached-password check

Password rules come from `Password::defaults()` and nothing configures
`uncompromised()`. So a password known to be in a public breach corpus is
accepted at registration, at reset, and at password change.

Laravel's `uncompromised()` rule calls the Have I Been Pwned range API with a
k-anonymity prefix — the full password never leaves the process, five hex
characters of its SHA-1 do.

Build:

- `HostConfig` sets `Password::defaults()` to include `uncompromised()`,
  behind `numerosis.auth.check_compromised_passwords`, default on.
- The call is network-dependent. The rule fails **open** on a network error,
  which is Laravel's behaviour and the right one — an unreachable API must not
  block signups.
- Document the flag for hosts in air-gapped deployments.

Not included: rejecting passwords on existing accounts. Checking at
authentication time and forcing a reset is a real feature and a much larger
one, since it turns a login into a mandatory interrupt.

## Item 2 — security headers

No header middleware exists anywhere in the tree. The package serves HTML on
central and tenant domains and sets none of:

| Header | Value |
|---|---|
| `Content-Security-Policy` | Report-only first. Stripe Elements, Turnstile and Livewire all inject scripts and frames, so a blocking policy written blind will break checkout |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`. `includeSubDomains` is load-bearing here — tenants *are* subdomains |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `DENY`, with the Stripe redirect-checkout return path checked against it |
| `Permissions-Policy` | Deny camera, microphone, geolocation by default |

Build a `SecurityHeaders` middleware registered by `MiddlewareRegistrar` into
both `web` and `tenant` groups, every header configurable, CSP shipping in
report-only mode with the Stripe and Turnstile origins already allowed. A host
can tighten it to enforcing once they know their own asset origins.

**`MiddlewareRegistrar` is pure class-string literals** — it runs before
`RegisterFacades`. The header values come from config read inside the
middleware, never from config read while registering it.

## Item 3 — login anomaly signal

The login limiter (five per minute, keyed on tenant, email and IP) stops
brute force and reports nothing. Add a `SuspiciousLoginDetected` event on
limiter exhaustion, carrying scalars, with no listener shipped. Hosts wire it
to their own alerting; `provisioning-observability.md`'s operator channel is
the obvious consumer.

Deliberately not included: geo-anomaly detection ("login from a new country"),
which needs a GeoIP database this package no longer depends on.

## Phases

1. `uncompromised()` plus its config flag and the fail-open test.
2. `SecurityHeaders` middleware, registered, all values configurable.
3. CSP report-only with Stripe, Turnstile and Livewire origins, plus a
   documented procedure for going enforcing.
4. `SuspiciousLoginDetected` event and its dispatch point.

## Tests

- A known-breached password is rejected at registration and reset; the check
  fails open when the API is unreachable (HTTP faked to error).
- The flag disables the check entirely.
- Headers present on both a central and a tenant response, and absent from the
  Stripe webhook route, which is not HTML and must not gain a CSP.
- `includeSubDomains` is present, since a missing one silently exempts every
  tenant.
- CSP report-only does not block: checkout renders Stripe Elements and the
  Turnstile widget with the shipped policy. This is the test that stops a
  well-meaning tightening from breaking payments.
- Limiter exhaustion fires the event exactly once per lockout, not per attempt.

## Risks

- **CSP and Livewire.** Livewire's script injection and Alpine's expression
  evaluation need policy allowances that differ between the Vite dev server
  and the published assets. Report-only first is the mitigation; enforcing by
  default would break `composer serve`.
- **`x-turnstile` compiles `@this` to `$_instance`** — a Turnstile widget in a
  plain Blade form is already a 500 rather than a degraded widget. Do not
  discover that while debugging a CSP report.

## What shipped

All four phases, 2026-09-17.

- `HostConfig::passwordDefaults()` composes `uncompromised()` onto the host's
  own `Password::defaults()` callback, behind
  `numerosis.auth.check_compromised_passwords`, default on. It shipped as
  `Password::min(8)->uncompromised()`, and D4 of the readiness remediation
  settled that minimum as the drop (P13, `2ca23d9`): this item asked only for
  `uncompromised()`, and a length nobody specified is the package deciding
  something the host owns. With no host callback the base is Laravel's own
  default.
  `Tests\TestCase` turns it off for the rest of the suite so no other test
  calls the range API; `CompromisedPasswordTest` opts back in with `Http::fake`.
- `Http\Middleware\SecurityHeaders`, appended to the `web` group through a new
  `MiddlewareRegistrar::groupAppends()` — `groups()` replaces a stack and the
  `web` group is the host's, so appending needed its own seam. The `tenant`
  group nests `web`, which is why one entry covers both. Its config prefix had
  eight concatenated copies and it exposed five `protected` hooks with no
  subclass in the tree; one accessor and five private methods since 2026-09-18
  (S28, S30, `2ca23d9`). The two-factor route name is read from
  `numerosis.routes.names` in the same commit, where both new middleware had it
  as a literal (S29).
- The webhook exemption is a path list (`numerosis.security.headers.except`),
  not a content-type check: Cashier answers the webhook with `text/html`. It
  shipped carrying `telescope/*`, in both that list and the CSRF exceptions, for
  a package this repo does not depend on; removed 2026-09-18 (P14, `2ca23d9`),
  since a host running Telescope adds its own paths.
- `Events\Auth\SuspiciousLoginDetected`, dispatched from a `Limit::response()`
  callback that **throws** `ThrottleRequestsException` rather than returning a
  response, so the limiter's behaviour is unchanged. Once per lockout window
  via `CacheKeys::loginLockout()`.
- Documented in `docs/host-requirements.md` (both the flag and the
  report-only-to-enforcing procedure) and `docs/extending.md` (the event).

Not done, and deliberately: the plan's "checkout renders Stripe Elements under
the shipped policy" test. A report-only policy blocks nothing, so the test
would pass against a policy missing every origin. What it asserts instead is
that the shipped directives name `js.stripe.com`, `challenges.cloudflare.com`
and `fonts.bunny.net`, which is the fact a tightening would break.
