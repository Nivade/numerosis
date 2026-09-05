# Collapse the two passwordless-login components into one

**Status: ✅ Executed.** Confirmed via `.claude/rules/auth-login.md`: central
`/login` and every tenant subdomain now both serve `App\Livewire\Auth\PasswordlessLogin`,
`TenancyAwareUserModel` fix and shared `resources/views/livewire/auth/passwordless-login/`
in place.

## Context

`.claude/rules/auth-login.md` documents that central `/login` and the tenant
Filament panel's login page are two independently-maintained
components — `resources/views/pages/auth/⚡passwordless-login.blade.php`
(an anonymous Livewire full-page component, routed via
`Route::livewire('login', 'pages::auth.passwordless-login')`) and
`App\Livewire\Auth\PasswordlessLogin` (a real class, wired into
`TenantAdminPanelProvider->login()`). Both extend Spatie's
`OneTimePasswordComponent` but share no code, and protections have already
drifted twice: Turnstile was added to one and not the other, and later the
`OneTimePasswordRule` validation itself was found commented out on one side —
an account-takeover bug, per the rules file.

Investigating this file pair now for the consolidation surfaced a **third,
currently-live instance of the same drift**: `App\Livewire\Auth\PasswordlessLogin`
(the tenant panel's login class) does not override `findUser()`, so it inherits
`OneTimePasswordComponent::findUser()`, which resolves
`config('auth.providers.users.model')` — hardcoded to
`App\Models\Central\CentralUser` in `config/auth.php`. On an actual tenant
subdomain this looks up the wrong model (`CentralUser` instead of
`App\Models\Tenant\User`), so tenant-panel login via this component would
either fail to find any user or authenticate against the wrong row. The
central page's anonymous class already got this right, via
`App\Concerns\TenancyAwareUserModel`. This plan fixes that alongside the
consolidation, rather than leaving it for a fourth rediscovery.

Goal: one component, one set of protections, serving both the central `/login`
route and the tenant panel — so hardening it once actually hardens both
surfaces, closing the structural cause of the drift rather than patching the
current instance of it.

## Approach

Keep `App\Livewire\Auth\PasswordlessLogin` as the single implementation — it
already carries every protection (`ThrottlesLoginAttempts`, OTP-verification
rate limiting, the `intendedUrlForCurrentHost` host check) that the central
page lacks. Point the central route at it directly instead of the separate
anonymous page component, and delete the now-redundant page/view files.

`routes/auth.php` already has the target line commented out immediately under
the current one — this swap was clearly anticipated and never finished:

```php
Route::livewire('login', 'pages::auth.passwordless-login')->name('login');
//    Route::get('login', PasswordlessLogin::class)->name('login');
```

### 1. Fix `findUser()` tenancy-awareness (the bug found above)

In `app/Livewire/Auth/PasswordlessLogin.php`:
- `use App\Concerns\TenancyAwareUserModel;`, add `use TenancyAwareUserModel;`
  to the class (same trait the central anonymous class already uses).
- Add:
  ```php
  protected function findUser(): ?Authenticatable
  {
      return $this->userModel()::firstWhere('email', $this->email);
  }
  ```
  (matches the anonymous class's current implementation exactly). This makes
  the single component resolve `CentralUser` outside tenancy and `Tenant\User`
  inside it, correct for both routes it will now serve.

### 2. Merge the two view pairs into one

Views live in `resources/views/livewire/auth/passwordless-login/` (already
the tenant class's `render()` target: `email-form.blade.php`,
`one-time-password-form.blade.php`). The OTP-entry view is already identical
between the two surfaces — no change needed there. The email-form view needs
two differences folded in from the central page's version:

- **Divider**: central inlines raw divider markup; tenant already uses the
  shared `<x-auth.social-divider />` component
  (`resources/views/components/auth/social-divider.blade.php`), which is
  equivalent but also has dark-mode classes the inline version lacks. Keep
  the shared component — strictly better, no behavior change for either
  surface.
- **Register link**: central shows a "Don't have an account? Sign up" link
  guarded by `@if (Route::has('register'))`; tenant has none, correctly,
  since the tenant panel shouldn't offer public registration. `Route::has()`
  checks the name globally regardless of domain, so it isn't a safe guard on
  its own — add `@unless(tenancy()->initialized)` around the block so it only
  renders on the central login, matching current behavior on both sides:

  ```blade
  @unless (tenancy()->initialized)
      @if (Route::has('register'))
          <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
              {{ __('Don\'t have an account?') }}
              <flux:link :href="route('register')" wire:navigate="true">{{ __('Sign up') }}</flux:link>
          </div>
      @endif
  @endunless
  ```

### 3. Repoint the central route, delete the old files

- `routes/auth.php`: replace the `Route::livewire(...)` line with
  `Route::get('login', PasswordlessLogin::class)->name('login');` (uncomment
  and keep, drop the now-obsolete `Route::livewire` line), add
  `use App\Livewire\Auth\PasswordlessLogin;`.
- Delete `resources/views/pages/auth/⚡passwordless-login.blade.php`.
- Delete `resources/views/components/auth/⚡passwordless-login-form.blade.php`
  and `resources/views/components/auth/⚡one-time-password-form.blade.php`
  (central-only duplicates, superseded by the `livewire/auth/passwordless-login/*`
  pair).

### 4. Update `.claude/rules/auth-login.md`

Replace the "two components" bullet and the "Suggested better approach"
section with a short note that they're now unified, keeping the historical
bug descriptions (they're still valuable — same lesson could resurface if a
third login surface is ever added) but removing the now-stale table and
future-tense suggestion.

## Tests

- `tests/Feature/Livewire/Auth/PasswordlessLoginTest.php` already targets
  `App\Livewire\Auth\PasswordlessLogin` directly — should keep passing
  unchanged and continues covering the shared class.
- Add one test proving the `findUser()` fix, following the
  `tenancy()->initialize()` + `actingAsTenantPanelUser`-adjacent pattern used
  in `tests/Feature/Filament/App/RoleResourceUiTest.php` (`Tenant::factory()->create()`
  then `tenancy()->initialize($tenant)`): create a `Tenant\User`, initialize
  tenancy, `Livewire::test(PasswordlessLogin::class)` through the OTP flow,
  and assert it authenticates as the `Tenant\User` — this is the regression
  test for the bug this plan fixes, and should be shown failing against the
  pre-fix `findUser()` first (per this repo's testing convention of proving a
  test fails against the bug before trusting it).
- Add a lightweight feature test hitting `GET /login` on the central domain
  (`$this->get('http://' . config('app.central.default') . '/login')` or
  equivalent central-domain host used elsewhere in the suite) asserting 200
  and that it's rendering the `PasswordlessLogin` component, as a smoke test
  for the route repoint.
- Run: `vendor/bin/sail artisan test --compact --filter=PasswordlessLogin`
  after the above, then a targeted run also covering `TenantAdminAuthTest`
  and `RoleResourceUiTest` (nearby tenant-login-adjacent tests) since they
  exercise the same panel login wiring.
- `vendor/bin/sail bin pint --dirty --format agent` on all touched PHP files.

## Files touched

- `app/Livewire/Auth/PasswordlessLogin.php` (findUser fix)
- `routes/auth.php` (route repoint)
- `resources/views/livewire/auth/passwordless-login/email-form.blade.php` (merge divider/register-link)
- `resources/views/pages/auth/⚡passwordless-login.blade.php` (delete)
- `resources/views/components/auth/⚡passwordless-login-form.blade.php` (delete)
- `resources/views/components/auth/⚡one-time-password-form.blade.php` (delete)
- `tests/Feature/Livewire/Auth/PasswordlessLoginTest.php` (add tenancy-aware findUser test)
- new/updated central-route smoke test (same file or a new `tests/Feature/Auth/LoginRouteTest.php`)
- `.claude/rules/auth-login.md` (update to reflect unification)
