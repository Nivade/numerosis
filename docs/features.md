# Features

A feature is a class implementing `Nvade\Numerosis\Contracts\Feature`, whose
`bootstrap()` runs once from `NumerosisServiceProvider::packageBooted()`.
Turning one off registers nothing — no routes, no UI, no notifications. It
never deletes data.

Every feature is listed in `config('numerosis.features')`. Comment one out to
disable it. There is no separate "satellite feature" registration path
anymore — `nvade/numerosis` is a single package (the only other split,
`nvade/numerosis-ui`, is a Flux component library with no features of its
own; see the scope-reduction plan at
`.claude/plans/archive/humming-nibbling-flame.md`).

> **A feature class named in config but not installed is a hard container
> failure at boot**, and the first symptom is misleading — the name map loses
> the entry silently before the `make()` loop crashes. Any packaging change
> that could remove a feature class needs the config change in the same commit.

## `config/numerosis.php`

| Feature | Key | What it registers | What stays when off |
|---|---|---|---|
| `TurnstileFeature` | `turnstile` | Cloudflare Turnstile markup, script tag, validation rule | `<x-numerosis::turnstile-field />` still resolves and renders nothing, so forms need no conditional. Credentials stay in `config('services.turnstile')`. `ryangjchandler/laravel-cloudflare-turnstile` is a `require`, so this key is the only switch |
| `SocialLoginFeature` | `social` | The whole OAuth surface: redirect/callback routes, provider buttons, connected-accounts manager. `Enums\Auth\SocialProvider` is the source of truth for which providers exist and which are configured — there is no `numerosis.social.providers` config array any more | Existing `social_accounts` rows are untouched; new connections and OAuth logins stop |
| `InvitationsFeature` | `invitations` | The tenant-side issue/revoke routes (`team.invitations.*`) and the central-side landing/accept routes (`invitations.show`/`invitations.accept`), plus the invitation notification | Existing `tenant_invitations` rows. Nobody can invite or accept |
| `RegistrationWizardFeature` | `registration_wizard` | The self-serve wizard and its `/get-started` route | Provisioning itself — tenants can still be created from an admin screen, a job or a Stripe webhook |
| `EmailVerificationFeature` | `email_verification` | Builds the tenant-aware verification URL | **Not a real toggle.** The user models implement `MustVerifyEmail` unconditionally; removing this leaves Laravel building a verification URL with nothing to build it from. Suppress the mail instead |
| `BillingNotificationsFeature` | `billing_notifications` | Payment-confirmed, payment-failed, tenant-suspended notifications | Billing events still fire and still drive state such as suspension. Only the outbound mail is suppressed |
| `PasswordResetFeature` | `password_reset` | Forgot- and reset-password routes | Fortify's own `password.confirm` route for sensitive actions, which this does not gate |
| `StaffPanelFeature` | `staff_panel` | **Off by default.** Five central-domain screens under `numerosis.routes.staff_prefix` (default `/staff`): tenants index and detail, provisions index and detail, queue, subscriptions, users. Every route carries the central guard plus `can:viewAny` on the tenants context, and every mutation goes through the action that already owns it | Nothing. No routes, no navigation, no permissions consumed — the `tenants` context is seeded either way |
| `ImpersonationFeature` | `impersonation` | **Off by default.** Support signing in as a tenant user: a one-time token redeemed on the tenant host, an `impersonation_sessions` audit row, the banner, the exit route, the 60-minute cap and the `impersonation:end-stale` sweep. Mail is suppressed for the duration through `numerosis.tenancy.impersonation.suppress_mail` | The `impersonate tenants` permission, seeded on the central guard either way — spatie throws for a permission name that does not exist rather than denying it |
| `HealthEndpointFeature` | `health_endpoint` | **Off by default.** One unauthenticated JSON document at `numerosis.routes.health_path` (default `up/numerosis`) for uptime monitors: whether the central database answers, provisioning queue depth and oldest job age, provisions failed in the last hour, stalled chains, and how long ago the scheduler ran. 503 when the central connection is down. Counts, booleans and ages only — never a tenant name or slug | The staff queue screen, which reads the same counts behind the staff panel's own authorization |
| `OneTimePasswordFeature` | `one_time_password` | **Off by default.** Replaces Fortify's password step with an emailed one-time code: a pipeline step before `AttemptToAuthenticate`, the `/one-time-password-challenge` routes, its own rate limiter, and the password field disappearing from the login form | Fortify's password login, which is what runs when this is off. It gates the *login method*, not `PasswordResetFeature`, which stays a separate toggle |

There is no marketing-pages feature. Terms, privacy, about and features are the
*product's* pages, so they live in the host app's own `routes/web.php`, which
`Numerosis::routes()` loads into each central-domain group. Core keeps only
`home`, registered unconditionally because OAuth redirects and checkout error
paths fall back to it. Point `numerosis.routes.home_view` at your own view, or
declare `/` yourself — the host file loads after core's, so it wins — and name
that route `home`, or `route('home')` stops resolving.

Auth itself is Fortify's — a feature here only ever gates a *screen* or a
*login method* around it, never account creation, password hashing or
session handling. See [`extending.md`](extending.md) for how to customize
Fortify directly (its own `viewPrefix()`, `*Using()` bindings,
`authenticateThrough()`), which is the seam a host reaches for first, not
this table.

## Adding one

`config('numerosis.features')` is the only registration path — add the class
there. See [`extending.md`](extending.md).
