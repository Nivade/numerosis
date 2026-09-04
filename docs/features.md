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
`.claude/plans/humming-nibbling-flame.md`).

> **A feature class named in config but not installed is a hard container
> failure at boot**, and the first symptom is misleading — the name map loses
> the entry silently before the `make()` loop crashes. Any packaging change
> that could remove a feature class needs the config change in the same commit.

## `config/numerosis.php`

| Feature | Key | What it registers | What stays when off |
|---|---|---|---|
| `TurnstileFeature` | `turnstile` | Cloudflare Turnstile markup, script tag, validation rule | `<x-numerosis::turnstile-field />` still resolves and renders nothing, so forms need no conditional. Credentials stay in `config('services.turnstile')`. Requires `ryangjchandler/laravel-cloudflare-turnstile` (a `suggest`, not a `require`) — `isEnabled()` also checks `class_exists()`, so enabling this without the package stays silently off rather than fataling |
| `SocialLoginFeature` | `social` (`ConfiguredProviders::FEATURE`) | The whole OAuth surface: routes, provider buttons, connected-accounts manager | Existing connections are untouched; new ones stop |
| `InvitationsFeature` | `invitations` | Invite/accept route, invitation-sent notification | Existing invitation rows. Nobody can invite or accept |
| `RegistrationWizardFeature` | `registration_wizard` | The self-serve wizard and its `/get-started` route | Provisioning itself — tenants can still be created from an admin screen, a job or a Stripe webhook |
| `EmailVerificationFeature` | `email_verification` | Builds the tenant-aware verification URL | **Not a real toggle.** The user models implement `MustVerifyEmail` unconditionally; removing this leaves Laravel building a verification URL with nothing to build it from. Suppress the mail instead |
| `BillingNotificationsFeature` | `billing_notifications` | Payment-confirmed, payment-failed, tenant-suspended notifications | Billing events still fire and still drive state such as suspension. Only the outbound mail is suppressed |
| `PasswordResetFeature` | `password_reset` | Forgot- and reset-password routes | Fortify's own `password.confirm` route for sensitive actions, which this does not gate |
| `OneTimePasswordFeature` | `one_time_password` | **Off by default.** Replaces Fortify's password step with an emailed one-time code: a pipeline step before `AttemptToAuthenticate`, the `/one-time-password-challenge` routes, its own rate limiter, and the password field disappearing from the login form | Fortify's password login, which is what runs when this is off. Requires `spatie/laravel-one-time-passwords` (a `suggest`) — enabling it without that package throws at boot, by design. It gates the *login method*, not `PasswordResetFeature`, which stays a separate toggle |
| `MembershipsFeature` | `tenancy.memberships` | The Team screens | Membership rows are still written by provisioning and invitation-accept. This gates only the screens. It does **not** gate `InvitationsFeature` |

There is no marketing-pages feature. Terms, privacy, about and features are the
*product's* pages, so they live in the host app, which registers them through
`Numerosis::addCentralRoutes()`. Core keeps only `home`, registered
unconditionally because OAuth redirects and checkout error paths fall back to
it; point `numerosis.routes.home_view` at your own view
rather than registering a second route on `/`, since core's is declared first
and wins the path match.

Auth itself is Fortify's — a feature here only ever gates a *screen* or a
*login method* around it, never account creation, password hashing or
session handling. See [`extending.md`](extending.md) for how to customize
Fortify directly (its own `viewPrefix()`, `*Using()` bindings,
`authenticateThrough()`), which is the seam a host reaches for first, not
this table.

## Adding one

`config('numerosis.features')` is the only registration path — add the class
there. See [`extending.md`](extending.md).
