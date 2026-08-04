# Customizable login flow (package extension points)

**Status: ✅ Executed.** `App\Contracts\Auth\{ResolvesLoginCandidate,
AuthenticatesLoginCandidate,ResolvesPostLoginRedirectUrl,CreatesRegisteredUser,
SendsEmailVerificationNotification}` all exist, bound in
`AppServiceProvider::register()` against their default implementations.

## Context

saas-m ships `nvade/*` sub-packages (`app-modules/*`) and is consumed as a
starter/package by downstream projects. Right now the entire login flow —
resolving a user by email, performing the authentication, and picking the
post-login redirect — is hardcoded inside `App\Livewire\Auth\PasswordlessLogin`
(the single component serving both central `/login` and every tenant
subdomain, per `.claude/rules/auth-login.md`). A consumer who needs different
logic (e.g. SSO-backed user lookup, extra post-login side effects, a different
landing page) currently has no way to swap it in short of forking the
component — which is exactly the kind of drift `.claude/rules/auth-login.md`
already warns about twice over.

Laravel Fortify (`Fortify.php`, referenced by the user) solves the same
problem with static callback registration (`Fortify::authenticateUsing(...)`).
This codebase already has its own established convention for this instead:
an `App\Contracts\*` interface, a default implementation, bound as a
`singleton` in a `ServiceProvider::register()` (see
`UserResolver::class => GetAuthenticatedUser::class` and
`App\Contracts\Tenancy\ProvisionsTenant`, `App\Contracts\Billing\CheckoutGateway`).
Consumers override by rebinding the interface in their own service provider —
no fork, no static mutable state. This plan follows that pattern rather than
introducing a second, Fortify-style customization mechanism.

## Extension points

Three seams in `PasswordlessLogin`/`LoginUser` become interfaces:

1. **`App\Contracts\Auth\ResolvesLoginCandidate`**
   `find(string $email): ?Authenticatable`
   Default impl `App\Actions\Auth\FindLoginCandidate` — moves the existing
   `PasswordlessLogin::findUser()` body (`$this->userModel()::firstWhere('email', ...)`,
   using `TenancyAwareUserModel`) here unchanged.

2. **`App\Contracts\Auth\AuthenticatesLoginCandidate`**
   `authenticate(Authenticatable $user, bool $remember): void`
   Default impl `App\Actions\Auth\AuthenticateLoginCandidate` — moves the
   existing `PasswordlessLogin::authenticate()` body (the `instanceof User`
   guard + `LoginUser::run()`) here unchanged. Lets a consumer add side
   effects (audit log, welcome email) or replace the guard call outright
   without touching rate-limiting/OTP verification, which stays in the
   component.

3. **`App\Contracts\Auth\ResolvesPostLoginRedirectUrl`**
   `url(): string`
   Default impl `App\Actions\Auth\ResolvePostLoginRedirectUrl` — moves the
   existing `intendedUrlForCurrentHost()` + `tenancy()->initialized ? '/' :
   route('tenants.mine')` fallback here unchanged.

All three are plain, stateless, constructor-free classes (`AsAction` trait,
matching the rest of `app/Actions/Auth`), so they're easy to type-hint and
easy for a consumer to replace with a class that does something else
entirely.

## Wiring

- Bind all three as singletons in `App\Providers\AppServiceProvider::register()`,
  next to the existing `UserResolver::class` binding, same style.
- `PasswordlessLogin` resolves them via `app(Contract::class)` inside the
  method bodies (`findUser()`, `authenticate()`, `submitOneTimePassword()`'s
  redirect line) — not as constructor-injected or public properties, since
  Livewire components are serialized between requests and public properties
  are client-writable state, not service references.
- No behavior changes for the default path — this is a pure extract-to-
  interface refactor. `submitOneTimePassword()`'s rate-limiting, OTP
  validation, and throttle calls stay exactly where they are; only the three
  named seams move.
- A consumer overrides by rebinding in their own `ServiceProvider::register()`,
  e.g. `$this->app->singleton(ResolvesLoginCandidate::class, MyResolver::class);`.

## Files

- New: `app/Contracts/Auth/ResolvesLoginCandidate.php`,
  `app/Contracts/Auth/AuthenticatesLoginCandidate.php`,
  `app/Contracts/Auth/ResolvesPostLoginRedirectUrl.php`
- New: `app/Actions/Auth/FindLoginCandidate.php`,
  `app/Actions/Auth/AuthenticateLoginCandidate.php`,
  `app/Actions/Auth/ResolvePostLoginRedirectUrl.php`
- Edit: `app/Livewire/Auth/PasswordlessLogin.php` (delegate the three seams)
- Edit: `app/Providers/AppServiceProvider.php` (three new singleton bindings)

## Verification

- Existing `tests/Feature/Livewire/Auth/PasswordlessLoginTest.php` must keep
  passing unmodified — proves the refactor is behavior-preserving.
- Add one new test per contract: bind a fake/spy implementation in the
  container for the duration of a test, log in through `Livewire::test`, and
  assert the fake was invoked (and that its return value drove behavior —
  e.g. a fake `ResolvesPostLoginRedirectUrl` changes where the component
  redirects to).
- Run `vendor/bin/sail artisan test --compact --filter=PasswordlessLogin`
  after the change.
- Run `vendor/bin/sail bin pint --dirty --format agent` on all touched files.
