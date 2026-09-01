# Features

A feature is a class implementing `Nvade\Numerosis\Contracts\Feature`, whose
`bootstrap()` runs once from `NumerosisServiceProvider::packageBooted()`.
Turning one off registers nothing — no routes, no UI, no notifications. It
never deletes data.

Two ways a feature gets enabled, and the difference matters:

- **Core features** are listed in `config('numerosis.features')`. Comment one
  out to disable it.
- **Satellite features** are registered by their own package's provider through
  `Features::register()` and are deliberately **not** named in core's config —
  naming a satellite class there would make core boot against a class that may
  not be installed. Disable one by uninstalling its package, or by overriding
  the feature list.

`Features::registered()` distinguishes the two at runtime; `Features::all()`
returns the union.

> **A feature class named in config but not installed is a hard container
> failure at boot**, and the first symptom is misleading — the name map loses
> the entry silently before the `make()` loop crashes. Any packaging change
> that could remove a feature class needs the config change in the same commit.

## Core features — `config/numerosis.php`

| Feature | Key | What it registers | What stays when off |
|---|---|---|---|
| `TurnstileFeature` | `turnstile` | Cloudflare Turnstile markup, script tag, validation rule | `<x-numerosis::turnstile-field />` still resolves and renders nothing, so forms need no conditional. Credentials stay in `config('services.turnstile')` |
| `ModuleSystemFeature` | `modules` | The per-tenant module system: module Filament plugins, marketplace, module resource, `tenants:*-module` commands, purchasing | Purchased module rows and their tenant tables. The system is switched off, not uninstalled. Also requires `internachi/modular` — the combined check is `ModuleSystemFeature::available()`, the one seam |
| `InvitationsFeature` | `invitations` | Invite/accept route, `InvitationResource`, invitation-sent notification | Existing invitation rows. Nobody can invite or accept |
| `EmailVerificationFeature` | `email_verification` | Builds the tenant-aware verification URL | **Not a real toggle.** The user models implement `MustVerifyEmail` unconditionally; removing this leaves Laravel building a verification URL with nothing to build it from. Suppress the mail instead |
| `BillingNotificationsFeature` | `billing_notifications` | Payment-confirmed, payment-failed, tenant-suspended notifications | Billing events still fire and still drive state such as suspension. Only the outbound mail is suppressed |
| `PasswordResetFeature` | `password_reset` | Forgot- and reset-password routes, password settings page | Password *confirmation* for sensitive actions. Login is passwordless, so this is a secondary surface — off means no way to set or recover a password |
| `MarketingPagesFeature` | `ui.marketing` | Terms, privacy, about, features — four routes | The homepage, deliberately: it is the one route guaranteed to exist on the central domain, relied on by OAuth redirects and checkout error paths. Rename it via `numerosis.routes.names.home` instead |
| `AccountPagesFeature` | `ui.account` | Account UI: settings (profile, password, appearance), tenant list, billing portal, invoice downloads | Product surface, not framework — off means build your own. The password settings page also needs `password_reset` |
| `MembershipsFeature` | `tenancy.memberships` | The tenant panel's Team screens | Membership rows are still written by provisioning and invitation-accept. This gates only the screens. It does **not** gate `InvitationsFeature` |
| `ImpersonationFeature` | `impersonation` | "Impersonate owner" action, plus stancl's own `UserImpersonation` feature — the `tenancy()->impersonate()` macro and `impersonate/{token}` route come from that | Nothing. **Security-sensitive**: with it on, central staff can open a real session as any tenant's owner. Remove it if that is not acceptable |

## Satellite features — registered by their own package

| Feature | Key | Package | What it registers | What stays when off |
|---|---|---|---|---|
| `SocialLoginFeature` | via `ConfiguredProviders::FEATURE` | `numerosis-auth-ui` | The whole OAuth surface: routes, provider buttons, connected-accounts manager | Existing connections are untouched; new ones stop |
| `RegistrationWizardFeature` | via `SelfServeRegistration::FEATURE` | `numerosis-onboarding` | The self-serve wizard and its `/get-started` route | Provisioning itself — tenants can still be created from an admin screen, a job or a Stripe webhook. The constant lives in **core** on purpose: a class-constant fetch autoloads the class, so gating on the satellite's own `NAME` would fatal a host that declined the package |
| `AdminPanelFeature` | `ui.admin_panel` | `numerosis-filament` | The central admin panel at `/admin` | Everything below the UI. For running headless or fronting the package with your own admin |
| `TenantPanelFeature` | `ui.tenant_panel` | `numerosis-filament` | The tenant panel at `{tenant}.<domain>` | Same — for fronting tenants with a UI of your own |
| `ActivityLogFeature` | `activity_log` | `numerosis-filament` | The audit-log UI in the tenant panel | Activity is still recorded and existing rows are untouched. This removes the interface, not the audit trail |

## Installed ≠ enabled

These are two independent decisions. A package you installed but whose feature
you removed registers nothing; a feature you left enabled whose package is
absent registers nothing either (satellites only call `Features::register()`
when they load). The wizard is the live example — `/get-started` stays behind
`RegistrationWizardFeature` whether or not `nvade/numerosis-onboarding` is
present.

## Adding one

`Features::register(MyFeature::class)` from your own provider, or add the class
to `config('numerosis.features')`. See [`extending.md`](extending.md).
