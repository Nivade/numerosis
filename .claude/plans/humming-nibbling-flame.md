# Scope reduction: six packages → two, and auth onto Fortify

## Context

`numerosis` set out to be a multi-tenant SaaS foundation and grew into a
product. It is now six Composer splits, 307 PHP files in `src/`, 169 test
files, and a hard dependency graph that includes a Filament admin suite, a
module marketplace, a GeoIP database and a Flux component library.

Two problems, one cause:

1. **The four pillars are multi-tenancy, customizable auth, customizable
   subscription/billing, and a customizable onboarding experience.** Roughly a
   third of the codebase is outside them — `packages/filament` (122 files,
   1.1 MB), the per-tenant module system (42 files, ~1,589 lines threaded
   through 13 top-level `src/` directories), impersonation, GeoIP-based
   checkout region ordering.

2. **The auth layer is a fork of a Laravel starter kit.**
   `packages/auth-ui/src/Livewire/ResetPassword::resetPassword()` is verbatim
   the Livewire starter kit's copy, down to Breeze's original comment blocks;
   `Register`, `ForgotPassword`, `VerifyEmail`, `ConfirmPassword` and all four
   of `packages/account`'s `Settings/*` components are the same lineage. Core's
   `Actions/Auth/{CreateRegisteredUser,UpdateUserPassword,UpdateUserProfile}`
   re-implement Fortify's `CreatesNewUsers`, `UpdatesUserPasswords`,
   `UpdatesUserProfileInformation`. Laravel fixes these upstream; these copies
   do not move.

**Verified:** `laravel/fortify` v1.39.0 (released 2026-08-23) requires
`illuminate/support ^11.0|^12.0|^13.0`, PHP `^8.2`. It is Laravel 13 ready
today. It pulls `laravel/passkeys ^0.2.0` transitively — a first-party package
still on 0.x, and the one real stability caveat in this plan.

Outcome: two packages — `nvade/numerosis` (the framework, views included) and
`nvade/numerosis-ui` (a reusable Flux component library) — with auth delegated
to Fortify and customized through Fortify's own published seams rather than a
parallel API invented here.

## Target shape

| Now | After |
|---|---|
| `nvade/numerosis` | `nvade/numerosis` — tenancy, Fortify-backed auth, billing, onboarding wizard, views |
| `nvade/numerosis-ui` | `nvade/numerosis-ui` — reusable Flux components only (stays a `require` of core) |
| `nvade/numerosis-auth-ui` | folded into core |
| `nvade/numerosis-onboarding` | folded into core |
| `nvade/numerosis-account` | deleted (Fortify owns the endpoints; keep only the views worth keeping) |
| `nvade/numerosis-filament` | deleted |

---

## Phase 1 — delete `packages/filament`

Delete `packages/filament/` and every Filament touchpoint. The seams already
exist and are all lazy, so this is subtraction, not surgery.

- **Core's 11 `Filament\` references** (20 occurrences) — all type hints,
  `class_exists()` guards or `Support\Compat\*` shims. Remove them and the two
  shims: `src/Support/Compat/FilamentUserContract.php`,
  `FilamentHasTenantsContract.php`, plus their composition in
  `src/Models/User.php`, `Models/Central/CentralUser.php`,
  `Models/Central/Tenant.php`. **These three models are attribute-configured**
  (`#[Table]`, `#[Fillable]`, `#[Hidden]`, `#[Connection]`) — there is not one
  `protected $fillable` left anywhere in `src/Models/`, nor a `$signature` in
  `src/Console/Commands/`. Stripping `implements`/`use` lines is exactly where
  a class head gets reflexively rewritten into property form; do not.
- `src/Support/Assets.php` — drop the `FilamentAsset::getStyleHref()` /
  `getScriptSrc()` branch entirely. **This closes blocker #1 in
  `.ai/plans/package-scope-reduction-review-fixes.md`** rather than fixing it.
- `src/Http/Middleware/CheckInvitationStatus.php` — drop the
  `class_exists()`-guarded `Notification::make()` branch, keep the session-flash
  fallback as the only path.
- `src/Http/Middleware/Authenticate.php`, `src/Resolvers/PreservingPathTenantResolver.php`,
  `src/Contracts/Tenancy/ModulePlugin.php` — Filament references go with
  Phase 2 for the last one.
- `src/Models/Central/Tenant.php:194` — the `resolveRouteBinding()` override
  exists only for `Filament\Panel\Concerns\HasTenancy`. Delete it. **Closes
  review-fixes minor #4.**
- `config/numerosis/panels.php` — delete the file and its entry in
  `config/numerosis.php`'s `array_merge`. Removes the `panels.tenant.login`,
  `panels.admin.tenant_registration_component` and `panels.{admin,tenant}.provider`
  seams; the `access_control.*` navigation keys go with them.
- `composer.json` — drop `filament/filament` and `alizharb/filament-activity-log`
  from `require-dev`, both `suggest` entries, and `nvade/numerosis-filament`
  from `require-dev`.
- `ActivityLogFeature` was registered by that package; `spatie/laravel-activitylog`
  and the `LogsActivityIfInstalled` shim stay — the audit *trail* is core's, only
  the UI was Filament's. Keep the 9 activity_log migrations.
- Tests: 49 of 169 test files reference `Filament`. Delete those covering panels,
  resources and browser panel flows. **`tests/Browser/AdminPanelTest.php` and
  `ModuleMarketplaceTest.php` go entirely** — which also closes review-fixes #7
  (the asset-publish leak) and #8 (`ApplyDefaultBranding` cross-request brand
  bleed).
- `.ai/rules/filament-tenancy.md` — delete. Header-note `optional-dependencies.md`
  and `package-boundaries.md`.

## Phase 2 — delete the module system

Not a pillar, and Phase 1 removed its only UI.

Delete: `src/Actions/Modules/`, `src/Contracts/Modules/`,
`src/Contracts/Tenancy/ModulePlugin.php`, `src/Contracts/Billing/{ModuleCatalog,ModuleOffer}.php`,
`src/Enums/Billing/ModuleBillingMode.php`, `src/Events/Modules/`,
`src/Exceptions/{Modules,Billing/Module*}`, `src/Features/Modules/`,
`src/Listeners/Modules/`, `src/Models/Central/ModuleOffering.php`,
`src/Models/Tenant/Module.php`, `src/Observers/ModuleOfferingObserver.php`,
`src/Policies/Module*Policy.php`, `src/Services/Billing/Modules/`,
`src/Concerns/{InteractsWithTenantModules,ResolvesInstalledModules}.php`,
`src/Console/Commands/{Migrate,Rollback,Seed}TenantModule.php`, the 4 module
migrations, `config/numerosis/modules.php`, and the 11 module tests.

- `composer.json` — drop the `internachi/modular` `suggest` and `require-dev`.
- `config/numerosis/features.php` — remove `ModuleSystemFeature::class`. **The
  config edit must land in the same commit as the class deletion** — a feature
  named in config but not installed is a hard container failure at boot whose
  first symptom is the wrong one (`src/NumerosisServiceProvider.php:238-245`).
- Drop the `modules` permission context from `RoleAndPermissionSeeder`.
- `.ai/rules/module-marketplace.md` — delete. Retract the module paragraphs in
  `package-boundaries.md`.
- Also delete `ImpersonationFeature` + `src/Actions/Tenancy/ImpersonateTenantUser.php`
  and the `impersonate/{token}` route in `routes/tenant.php` — its only entry
  point was `packages/filament`'s `TenantResource`, and it registers stancl's
  `UserImpersonation` central-staff-opens-any-tenant-session surface.

## Phase 3 — collapse to two packages

Move, do not copy. All three satellites already register views under core's
own `numerosis::` namespace via `->hasViews('numerosis')`, so **view strings do
not change** — but a same-named file resolves to whichever package registered
first, silently, so duplicates must never exist mid-move.

- **`packages/auth-ui` → core.** `src/Livewire/*` and `src/Concerns/ThrottlesLoginAttempts.php`
  are deleted rather than moved (Phase 4 replaces them). Move
  `src/Http/Controllers/Socialite/{Login,Redirect}.php` →
  `src/Http/Controllers/Socialite/`, `src/Features/SocialLoginFeature.php` →
  `src/Features/Auth/`, `routes/auth.php` → merged into `routes/web.php`,
  and `resources/views/livewire/auth/*` → `resources/views/auth/*` (they become
  plain Blade views over Fortify endpoints in Phase 4).
- **`packages/onboarding` → core.** `src/Livewire/*`, `src/Support/RegistrationState.php`,
  `src/Features/RegistrationWizardFeature.php` and `routes/onboarding.php` move
  in as-is. Core absorbs `spatie/laravel-livewire-wizard ^3.1`. The
  `Support\Tenancy\SelfServeRegistration::FEATURE` indirection constant can
  collapse back to `RegistrationWizardFeature::NAME` now that core owns the class.
- **`packages/account` → mostly folded in.** `Settings/{Profile,Password}` and
  `DeleteUserForm` move to core **as Livewire components**, with their bodies
  rewired onto Fortify's action contracts in Phase 4 (see 4d — settings screens
  stay Livewire; only the *guest* auth screens become plain forms). Their views
  move with them. `Settings/Appearance` is a starter-kit nicety — delete.
  `resources/views/pages/tenant/⚡mine.blade.php`
  (the `tenants.mine` workspace list) moves to core: it has only 3 real call sites
  (`Support/Routes/RouteNames.php`, `Support/Ui/AccountPages.php`,
  `Models/Central/PendingTenantProvision.php` — the "~20 sites" comment in
  `config/numerosis/routes.php` is stale), but it is the post-login landing for a
  multi-workspace user, which is framework, not product.
  `Support\Ui\AccountPages::FEATURE` and `AccountPagesFeature` are deleted.
- **`nvade/numerosis-ui` stays a `require` of core**, unchanged — core does ship
  views, and `ui` is the reusable component library they build on.
- `composer.json` — remove all four `nvade/numerosis-*` `require-dev` entries and
  the `suggest` entries for auth-ui / onboarding. Keep the `{"type":"path","url":"packages/*"}`
  repository for `ui`. Add `laravel/socialite ^5.21` to `require`; move
  `socialiteproviders/{discord,zoho}` to `suggest` (`ConfiguredProviders` already
  gates them).
- **`Support\Contributions`, `Features::register()` and the `add*()` seams stay**
  — they are the *host* extension API documented in `docs/extending.md`, not just
  the satellite one. But with no satellite appending on every application boot,
  review-fixes #6 (unbounded per-boot accumulation) and #5 (a registered feature
  cannot be turned off) both lose their only in-repo trigger. Still dedup on
  registration in `Contributions` for Octane's sake; drop #5 to a docblock
  correction.
- `tests/Feature/PackageBoundariesTest.php` and `SatelliteRouteContributionTest`
  need rewriting against a two-package world, not deleting — they are what
  enforces boundaries now that the filesystem does not.

## Phase 4 — auth onto Fortify

Add `laravel/fortify ^1.39` to core's `require`. Fortify becomes the auth
engine; numerosis supplies the tenancy wiring and the views.

> **Fortify is not vendored yet** (`vendor/laravel/` has no `fortify` as of
> 2026-09-03). Every claim below about Fortify's internals — controller
> behaviour, contract names and signatures, `Fortify::viewPrefix()`, the shape
> of `routes/routes.php` — is from documentation and memory, not from reading
> the installed source. **First action of this phase is `composer require
> laravel/fortify` and re-checking this section against `vendor/laravel/fortify/src`.**

### 4a. Route registration across central and tenant domains

Fortify registers its routes once, inside a single
`Route::group(['domain' => config('fortify.domain'), 'prefix' => config('fortify.prefix')])`.
Numerosis needs them on every central domain *and* inside the tenant group.

In `NumerosisServiceProvider::packageRegistered()`, call `Fortify::ignoreRoutes()`.
In `Support\Numerosis::routes()` (`src/Support/Numerosis.php:161`), load
Fortify's own route file inside each existing group:

```php
// inside the per-central-domain loop
$this->loadFortifyRoutes(Config::string('numerosis.auth.guards.central'));

// inside the Route::middleware('tenant') group
$this->loadFortifyRoutes(Config::string('numerosis.auth.guards.tenant'));
```

where the helper temporarily swaps **four** config keys around a `require` of
Fortify's route file, restoring **in a `finally`**:

```php
$original = Config::array('fortify');

config([
    'fortify.guard' => $guard,
    'fortify.middleware' => [],   // the outer group already applied `web`/`tenant`
    'fortify.domain' => null,     // the outer group already scoped the domain
    'fortify.prefix' => '',       // ditto the prefix
]);

try {
    require $fortifyRoutes;
} finally {
    config(['fortify' => $original]);
}
```

The `finally` is load-bearing, not tidiness: if the `require` throws part-way
through the tenant group, `fortify.guard` is left pointing at the tenant guard
**process-wide**. Under `php artisan route:cache` that bakes the wrong
`guest:` middleware into the cached route table with no error surfaced.

The path is derived, not hardcoded:
`dirname((new ReflectionClass(Fortify::class))->getFileName(), 2).'/routes/routes.php'`.

The last three matter because `routes/routes.php` opens its *own*
`Route::group(['middleware' => config('fortify.middleware', ['web']), 'domain' => …, 'prefix' => …])`
inside ours. Leaving `fortify.domain`/`prefix` set double-scopes every route.
Leaving `fortify.middleware` set is not fatal — `Illuminate\Routing\Route::gatherMiddleware()`
`array_unique`s the stack, so a second `'web'` collapses — but clearing it is
explicit and survives a host that put something non-idempotent in that key.

Why the guard swap is necessary and sufficient: `routes/routes.php` bakes
`'guest:'.config('fortify.guard')` into route middleware **at registration
time**, so it must be correct per group. Everything downstream reads the guard
at request time — `FortifyServiceProvider` does
`$this->app->bind(StatefulGuard::class, fn () => Auth::guard(config('fortify.guard')))`,
a `bind` not a `singleton`, so `AuthGuardBootstrapper`'s `Auth::shouldUse()`
during tenancy initialization is already honoured.

Loading the file twice produces duplicate route names (`login`, `register`, …).
That is **already the status quo** — `Numerosis::routes()` registers
`routes/web.php` once per central domain today. `Numerosis::routes(withAuth: false)`
stays as the host opt-out; its docblock at `src/Support/Numerosis.php:145-152`
already anticipates exactly this Fortify collision.

### 4b. Rebind, don't delete

Most of what looked like duplication is Fortify's **action slot already filled
with tenancy-aware logic**. The class stays; only the interface it satisfies
changes, so the behaviour keeps its tests and hosts get Fortify's published
seam instead of a numerosis-only one.

| Core class | Becomes | Note |
|---|---|---|
| `Actions/Auth/CreateRegisteredUser` | `implements Fortify\Contracts\CreatesNewUsers`, bound via `Fortify::createUsersUsing()` | method is already `create(array $data)`. **Must absorb validation** — `RegisteredUserController` performs none; the contract expects the action to validate. Delete `Contracts/Auth/CreatesRegisteredUser`. |
| `Actions/Auth/UpdateUserProfile` | `implements UpdatesUserProfileInformation` | merge, don't pick: keep numerosis's `isDirty('email')` → null `email_verified_at`, **add** Fortify's re-send of the verification notification, which core's version omits. |
| `Actions/Auth/UpdateUserPassword` | `implements UpdatesUserPasswords` | |
| *(new)* `Actions/Auth/ResetUserPassword` | `implements ResetsUserPasswords` | no core action today — the logic lives inside `auth-ui`'s `ResetPassword` Livewire component. Extract it rather than losing it. |
| `Actions/Auth/ResolvePostLoginRedirectUrl` | body moves into a `LoginResponse` binding | `toResponse($request)` receives the request, which removes the `request()` helper call inside `intendedUrlForCurrentHost()`. While moving it, swap `parse_url($intended, PHP_URL_HOST)` for `Uri::of($intended)->host()`. Delete `Contracts/Auth/ResolvesPostLoginRedirectUrl`. |

#### Cross the `array $input` boundary with a Data object

Every Fortify action contract is typed `array $input` — `create(array $input)`,
`update($user, array $input)`. Satisfying them as written drops an untyped array
into the auth boundary, in a codebase that uses `spatie/laravel-data` for
exactly this (8 classes under `src/Data/`, no `$fillable` arrays anywhere). The
signature cannot change, so convert on entry:

```php
public function create(array $input): CentralUser
{
    $data = RegistrationData::validateAndCreate($input);
    // …
}
```

This discharges the "must absorb validation" row above in one line rather than
a hand-rolled `Validator::make`, and `validateAndCreate()` throws into the
**default** error bag — which is precisely what 4d needs for the Livewire
settings screens. Same treatment for `UpdatesUserProfileInformation` and
`UpdatesUserPasswords`.

For the profile-update Data class, type optional fields `Optional|string` with
`= new Optional` defaults, **not** `?string`. `UpdateUserProfile` does
`$user->fill($data)` then checks `isDirty('email')`; a `null` arriving from a
field the form simply did not submit would clear the address rather than leave
it alone.

Putting validation inside the action is a **deliberate deviation** from this
repo's Form Request convention. Fortify requires it — the same action is
invoked from its controller *and* from a Livewire component (4d), and only one
of those has a Form Request. Record it in `docs/extending.md` so a later review
does not "fix" it back.

#### `#[\SensitiveParameter]` on every new plain-string secret

The repo has zero uses today, and Phase 4/5 add the code that most needs it:
the OTP verify code (Phase 5), the reset-token path, `AuthenticateLoginCandidate`.
Sentry is wired in this package (`src/Concerns/TagsSentryScopeWithTenant.php`),
so stack traces leave the machine. The attribute cannot cover an array-shaped
`$input` — a second reason to convert those to Data objects immediately.

**Genuinely deleted, because Fortify has a real equivalent:**
`Actions/Auth/RegisterUser`, `Http/Controllers/Auth/VerifyEmailController`,
`Actions/Auth/{SendEmailVerificationNotification,ResendVerificationNotification}`
plus `Contracts/Auth/SendsEmailVerificationNotification` (keep the notification
class itself), `auth-ui`'s `Register`/`ForgotPassword`/`ResetPassword`/
`VerifyEmail`/`ConfirmPassword`, and `auth-ui`'s `Concerns/ThrottlesLoginAttempts`
(→ `LoginRateLimiter` + `EnsureLoginIsNotThrottled`).

**Not deleted, contrary to the earlier draft:** `Actions/Auth/DeleteUserAccount`
and `account`'s `Settings/DeleteUserForm`. Account deletion is **Jetstream's**
`DeletesUsers`, not Fortify's — Fortify ships no equivalent, so there is nothing
to delegate to. `Settings/{Profile,Password}` also stay as Livewire components;
see 4d.

### 4c. Keep — genuinely tenancy-specific, no Fortify equivalent

`Actions/Auth/LoginUser` (dual-guard login: current guard **and** central
guard, with the `Context::fromGuard()` model resolution),
`ResolveLoginCandidate` / `AuthenticateLoginCandidate`,
`PromoteFirstCentralUserToAdmin`, `{Connect,Disconnect}SocialAccount`,
`Models/SocialiteLogin`, `ConfiguredProviders`, the Socialite controllers.

`LoginUser`'s second-guard login becomes a numerosis pipeline step appended
after `AttemptToAuthenticate` (see 4e), not a replacement for it.

#### `LogoutUser` — Fortify's controller actively fights it

`AuthenticatedSessionController::destroy()` logs out **only**
`config('fortify.guard')` and then invalidates the session. Two defects against
this codebase, both of which `LogoutUser` exists to prevent:

1. the second guard is never logged out, so a tenant logout leaves the central
   session alive;
2. it calls `$guard->logout()`, and `SessionGuard::logout()` resolves the user
   first — the exact cross-connection write that produces
   `ModelNotSyncMasterException` (see the docblock on
   `LogoutUser::endTenantSession()` and `.ai/rules/auth-guards.md`).

Do **not** fork the controller. Register a listener on
`Illuminate\Auth\Events\Logout` holding the current `endTenantSession()` body
plus the other-guard logout; it fires before Fortify invalidates the session,
so the ordering works. Bind `LogoutResponse` for the redirect. The route stays
Fortify's. `LogoutUser` shrinks to that listener and its
`asController()`/`htmlResponse()` methods go.

**Register it with an explicit `Event::listen()` in `packageBooted()`.** Laravel's
listener auto-discovery scans the *host application's* `app/Listeners`; it never
scans a package's `src/`. A discovered-by-convention listener here is a listener
that silently never fires, and its absence looks exactly like the bug it exists
to prevent.

### 4d. The Livewire boundary — where each screen lives

Fortify controllers and Livewire do not conflict, but they **cannot be combined
on a single screen action**: Livewire 4 posts every interaction to
`/livewire/update`, never to an arbitrary route, so no Livewire component can
submit into a Fortify controller. Split by layer — this is exactly what
Jetstream's Livewire stack does.

**Guest auth screens → plain Blade `<form method="POST">` posting Fortify's
routes.** `resources/views/auth/*` become non-Livewire.

- Flux inputs render as ordinary `<input>` elements and are not
  Livewire-dependent, so the views survive the conversion. `wire:model` becomes
  `name=` + `old()`.
- Inline reactive validation is lost; errors arrive via redirect-back and
  `$errors`. Same as Breeze's Blade stack — acceptable.
- `<x-numerosis::turnstile-field />` gets *easier*, not harder: no Livewire
  re-render to destroy and re-mount the widget.
- **Every converted form needs `@csrf`.** Livewire supplied the token; a plain
  form does not. Seven views, one omission each. It fails loudly as a 419 rather
  than silently, but it is the single most likely defect in this conversion.
  No Data object is bound as a public property on any of these components today
  (verified), so nothing is lost on the `WireableData` side by dropping Livewire
  here.

**Authenticated settings screens → stay Livewire, calling Fortify's actions**
(`account`'s `Settings/{Profile,Password}`, `DeleteUserForm`):

```php
public function updateProfileInformation(UpdatesUserProfileInformation $updater): void
{
    $updater->update($this->authenticatedUser(), $this->only('name', 'email'));
}
```

**Method injection, not `app()`.** Livewire resolves action-method parameters
from the container; it does not support constructor injection on components.
This also keeps the action mockable in the component test, which `app()` inside
the body does not.

Livewire catches the thrown `ValidationException` and renders it; no
`$this->validate()` call is needed. One trap: Fortify's own actions use
`validateWithBag('updateProfileInformation')`, while Livewire's `@error('name')`
reads the **default** bag. Since numerosis owns these actions, validate into the
default bag (which `Data::validateAndCreate()` in 4b already does) and keep the
views plain — then document in `docs/extending.md` that a host swapping in
Fortify's stock action must switch to
`@error('name', 'updateProfileInformation')`.

Note the `#[ErrorBag('…')]` attribute is **not** the fix here: it applies to
Form Requests, and nothing in this path is one.

**Login itself never runs inside Livewire.** `throttle:login` is *route*
middleware and does not cover `/livewire/update`, so a Livewire login form
silently has no rate limiting at all. This is the same defect class as the
out-of-order-method takeover recorded in `.ai/rules/auth-login.md`, and it is
the concrete reason Phase 5's OTP challenge is a controller rather than a
component.

**Unverified, needs a browser test rather than an assumption:** the
`password.confirm` middleware issuing its redirect *during* a Livewire update
request.

### 4e. Views and the pipeline — the customization story

Core's `packageBooted()` does:

```php
Fortify::viewPrefix('numerosis::auth.');   // registers all seven views at once
Fortify::authenticateThrough(fn (Request $request) => array_filter([...]));
```

A consumer customizes exactly the way Fortify's own docs describe — no
numerosis-specific API to learn:

| To change | Call |
|---|---|
| a screen | `Fortify::loginView(...)` / `registerView(...)` / … (accepts a closure receiving `$request`, so a tenant-aware screen is one branch) |
| where login lands | bind `LoginResponse` |
| how users are created | `Fortify::createUsersUsing(...)` |
| the login pipeline | `Fortify::authenticateThrough(...)` |
| which screens exist at all | `config('fortify.features')` |
| URLs | `config('fortify.paths.*')` |
| routes entirely | `Fortify::ignoreRoutes()` (core already owns this — expose it through `Numerosis::routes(withAuth: false)`) |

`numerosis.features` and `fortify.features` are the same shape and stay
separate: numerosis's gates tenancy/billing surfaces, Fortify's gates auth
screens.

**Every numerosis-only auth contract that Fortify already covers is deleted**,
so a host learns one API rather than two: `ResolvesPostLoginRedirectUrl`,
`CreatesRegisteredUser`, `SendsEmailVerificationNotification`. Fortify's
per-controller `*Response` and `*ViewResponse` contracts are strictly richer
than what core hand-rolled. The contracts with **no** Fortify counterpart stay
exactly as they are: `ResolvesLoginCandidate`, `AuthenticatesLoginCandidate`,
`SocialAccountRepository`, `CentralUserModel`, `TenantUserModel`.

Bind numerosis's implementations as plain `singleton`s in
`packageRegistered()`. A host's `AppServiceProvider` registers afterwards and
overwrites the binding, so override is free — no opt-in seam to build.

The `#[Singleton]` container attribute does **not** replace these bindings.
It sets a class's *lifetime*; it does not map an interface to a concrete, and
`LoginResponse::class` → numerosis's implementation is precisely an
interface-to-concrete mapping. Leave the provider registrations alone.

### 4f. Known integration points, to resolve during implementation

- **Email verification uses a global id — resolved.** `EmailVerificationFeature`
  builds `URL::temporarySignedRoute('verification.verify', ['id' => $notifiable->getGlobalIdentifierKey(), …])`,
  while Fortify's `VerifyEmailRequest::authorize()` compares the `id` route
  parameter against `$this->user()->getKey()`. Do **not** disable
  `Features::emailVerification()`. Fortify's controller type-hints the concrete
  `VerifyEmailRequest`, and `$this->app->bind(VerifyEmailRequest::class, NumerosisVerifyEmailRequest::class)`
  resolves a subclass for a concrete type-hint, so override `authorize()` to
  compare against `getGlobalIdentifierKey()`. Fortify keeps its route,
  controller and signed-URL handling.
- **The login rate limiter is one bucket across every tenant.** Fortify's
  default `login` limiter keys on `lower(username).'|'.$request->ip()`. Two
  tenants with a user at the same email address share a lockout counter, so
  tenant A's failed attempts lock out tenant B's user — a cross-tenant denial of
  service reachable by anyone who can guess an email. Register numerosis's own
  `login` limiter (and the OTP one in Phase 5) with the tenant key mixed into
  `->by(...)`. Regression-test it with two tenants, not one.
- **`fortify.passwords`** (the reset broker) is a single config value and needs
  the same per-group swap as `fortify.guard` if central and tenant brokers differ.
- **Password confirmation timeout** and `password.confirm` middleware move to
  Fortify's `ConfirmablePasswordController`; `routes/tenant.php`'s comment about
  contributing that route from auth-ui becomes stale.

## Phase 5 — OTP as a feature built on Fortify

Passwordless email OTP stays, as a `NamedFeature`, layered on Fortify rather
than replacing it. It is structurally identical to Fortify's own 2FA challenge:
identify user → issue challenge → verify code → `PrepareAuthenticatedSession`.

- New `Features\Auth\OneTimePasswordFeature` (`NAME = 'one_time_password'`),
  listed in `config/numerosis/features.php`, **off by default** since Fortify's
  password login is now the default.
- New `Actions\Auth\RedirectIfOneTimePasswordAuthenticatable`, modelled on
  Fortify's `RedirectIfTwoFactorAuthenticatable`, inserted by
  `Fortify::authenticateThrough()`:

```php
Fortify::authenticateThrough(fn (Request $request) => array_filter([
    config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
    OneTimePasswordFeature::enabled() ? RedirectIfOneTimePasswordAuthenticatable::class : null,
    FortifyFeatures::enabled(FortifyFeatures::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
    AttemptToAuthenticate::class,
    PrepareAuthenticatedSession::class,
    LogInToCentralGuard::class,   // numerosis: the second half of today's LoginUser
]));
```

- New `OneTimePasswordChallengeController` + `numerosis::auth.one-time-password-challenge`
  view, mirroring `TwoFactorAuthenticatedSessionController`. Challenge routes
  register only when the feature is on.
- `spatie/laravel-one-time-passwords` stays a `suggest`; keep both
  `one_time_passwords` migrations (central **and** tenant — the tenant copy is
  load-bearing, see `.ai/rules/auth-login.md`).
- **Two properties this design buys, which the deleted `PasswordlessLogin`
  Livewire component did not have:** the code check cannot be bypassed by
  invoking a public method out of order (the takeover bug in
  `.ai/rules/auth-login.md`), and verification attempts are throttled by
  Fortify's own limiter rather than by a helper that had to be remembered.
  Regression-test both, and **verify each test fails against a stubbed-out
  challenge step** before trusting it.

## Phase 6 — dependency cuts

- **`torann/geoip`** — remove from `require`. Delete
  `Actions/Billing/Checkout/ResolveCheckoutRegion`'s GeoIP branch (fall back to
  `numerosis.billing.payment_methods.default_order` unconditionally),
  `HostConfig::geoipService()`, the weekly `geoip:update` schedule entry in
  `NumerosisServiceProvider:429-435`, and the `MAXMIND_LICENSE_KEY` step in
  `InstallNumerosisCommand:804`.
- **`ryangjchandler/laravel-cloudflare-turnstile`** — `require` → `suggest`.
  `TurnstileFeature` already gates it and `<x-numerosis::turnstile-field />`
  already renders nothing when off; add the `class_exists()` seam in
  `TurnstileFeature::available()`, one seam, not one guard per call site.
- Resulting core `require`: PHP, the `illuminate/*` set, `laravel/framework`,
  `laravel/cashier`, `laravel/fortify`, `laravel/socialite`, `livewire/livewire`,
  `lorisleiva/laravel-actions`, `nvade/numerosis-ui`, `spatie/laravel-data`,
  `spatie/laravel-package-tools`, `spatie/laravel-permission`,
  `spatie/laravel-livewire-wizard`, `stancl/tenancy`.

## Phase 7 — docs and rules

- `docs/features.md` — the satellite-features table collapses to one section;
  drop `AdminPanelFeature`, `TenantPanelFeature`, `ActivityLogFeature`,
  `ModuleSystemFeature`, `ImpersonationFeature`, `AccountPagesFeature`.
- `docs/extending.md` — the auth section becomes "customize through Fortify",
  with the table from 4e, plus the error-bag note from 4d. The panel seams go.
- `docs/architecture.md`, `docs/host-requirements.md`, `README.md`,
  `src/Commands/InstallNumerosisCommand.php` — two packages, not six.
- `.ai/rules/`: delete `filament-tenancy.md` and `module-marketplace.md`;
  rewrite `package-boundaries.md` and `package-split.md` for two packages;
  rewrite `auth-login.md` around Fortify (keep the takeover and
  rate-limit-drift lessons — they are why Phase 5 is shaped the way it is);
  header-note `optional-dependencies.md` and `auth-guards.md`.
- Update `.ai/rules/index.md`'s table in the same commit as any rule file
  added, renamed or removed.
- **Fix `.ai/skills/laravel-data/SKILL.md` — it is written for a different
  application.** It instructs the reader to open `.ai/rules/data.md`, to run
  `vendor/bin/sail artisan make:data`, and states Data classes live in
  `app_path('Data')` with `config/data.php` controlling structure caching. None
  of that holds here: this repo has `src/Data/`, no Sail, no `data.md` rule and
  no `config/data.php`. Phase 4 leans on this skill (the `array $input`
  conversion in 4b), so it misdirects at exactly the wrong moment. Edit under
  `.ai/skills/`, never through the `.claude/skills/` symlink.
- Overwrite `.claude/plans/next-session.md`; copy this plan to `.claude/plans/`.

## Sequencing

Seven phases, each independently green and committable. **This is not one
session.** Suggested split:

1. Phases 1–2 (deletion) — largest diff, lowest risk, no design decisions left.
2. Phase 3 (collapse to two packages) — pure moves plus composer/provider surgery.
3. Phase 4 (Fortify) — the only phase with open questions (4f). Resolve those first.
4. Phases 5–7.

## Verification

Run `composer format` **before** `composer test` — Pint edits files, so
formatting after testing means testing twice.

- `composer test` — baseline is 697 passed / 7 skipped / 7,428 assertions.
  Expect a large drop from deleted panel and module tests; **account for the
  delta explicitly** rather than accepting whatever number comes out.
- `composer analyse` — PHPStan level 9. Compare **cold-vs-cold**; a warm result
  cache hides errors (`.ai/rules/static-analysis.md`). The 202-entry baseline
  should shrink; regenerate it, do not add to it.
- Per phase, before deleting a test: confirm what it covered is gone, not just
  untested. Scanning tests go vacuous rather than red when their subject
  disappears (`.ai/rules/package-split.md`).
- Auth, end to end against the workbench (`composer serve`) — not tests alone:
  register, verify email, log in, reset password, confirm password, update
  profile and password, log out. Then repeat **on a tenant subdomain**, which is
  what proves 4a's per-group guard swap works.
- OTP: enable `OneTimePasswordFeature`, confirm the challenge is required and
  that setting `email` and invoking the verify endpoint directly does **not**
  authenticate.
- Browser tests need `npx playwright install chromium` first
  (`.ai/rules/testing.md`).
- After Phase 4, assert the Livewire boundary from 4d holds: no guest auth view
  contains `wire:model`, every converted form contains `@csrf`, and
  `Settings/{Profile,Password}` render their validation errors from the
  **default** bag. Also cover a `password.confirm` redirect triggered from
  inside a Livewire update request — that one is unverified.
- Rate limiting, with **two** tenants: failed logins against tenant A's user
  must not lock out tenant B's user at the same email address (4f). A
  single-tenant test passes whether or not the limiter is tenant-keyed, so it
  proves nothing here.
- Host smoke test in `../numerosis-thin-app` after Phase 3 and after Phase 4 —
  it is the only real (non-Testbench) boot, and `Numerosis::middleware()` fatals
  there in ways Testbench does not (`.ai/rules/package-host-bootstrap.md`).
