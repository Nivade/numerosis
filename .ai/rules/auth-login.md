---
paths:
  - 'src/Actions/Auth/**'
  - 'src/Livewire/**'
  - 'resources/views/auth/**'
---
> **Rewritten 2026-09-03 (Phase 7 of `.claude/plans/archive/humming-nibbling-flame.md`),
> for Fortify.** Everything this file used to describe —
> `Livewire\Auth\PasswordlessLogin`, `packages/auth-ui`, `ThrottlesLoginAttempts`
> — is deleted. Auth is `laravel/fortify`'s now (Phase 4): Fortify owns route
> registration, the login controller, session handling and password hashing.
> Guest auth screens (`resources/views/auth/*`) are plain Blade `<form>`s
> posting Fortify's routes, not Livewire — see `docs/extending.md` for why
> that split is structural, not a style choice. What follows is current
> behaviour, plus the two lessons the old file earned the hard way, carried
> forward because they are *why* the current design is shaped the way it is.

# Login surfaces, current shape

> **Audit pass 2026-09-04** added the four entries marked *(audit)* below.
> Each is a defect the Phase 4 design produced and the suite did not see.

- **(audit) `route:cache` is impossible while Fortify's routes load twice.**
  `Numerosis::routes()` requires `vendor/laravel/fortify/routes/routes.php`
  once per central domain and once in the tenant group, because
  `'guest:'.config('fortify.guard')` is baked into route middleware *at
  registration time*. Both copies therefore carry the same route names, and
  `php artisan route:cache` throws `Unable to prepare route [login] for
  serialization`. Serving them uncached is fine (the domain-scoped copy
  matches first on a central host; `route('login')` resolves the domain-less
  tenant copy, which generates a host-relative URL correct on both), so the
  cost is exactly the cache step — documented in
  `docs/host-requirements.md`. **The package's own suite cannot see this**:
  Testbench registers Fortify's routes once, so the duplicate only appears
  in a real host (`../numerosis-thin-app`). Fixing it properly means one
  registration whose `guest:`/`auth:` middleware resolve the guard at
  request time — note that `Auth::guard('')` falls through to the *default*
  driver, which `AuthGuardBootstrapper` already switches per context, so
  `config('fortify.guard') === ''` is the thread to pull.

- **What `Support\Numerosis::loadFortifyRoutes()` swaps, and why each one.**
  It runs once per central domain and once for the tenant group, with
  `Fortify::ignoreRoutes()` in `packageRegistered()` having disabled Fortify's
  own single-group `configureRoutes()`. Two keys are set for the duration:
  - `fortify.guard`, because `'guest:'.config('fortify.guard')` is baked into
    route middleware at registration time. Everything downstream (Fortify's
    `StatefulGuard` binding, `AuthGuardBootstrapper`) instead reads the guard
    at request time off `Auth::getDefaultDriver()`. The `finally` restores the
    whole `fortify` array, because a leftover `guard` silently changes the
    process-wide default until the next `loadFortifyRoutes()` overwrites it.
  - `fortify.middleware`, emptied. The outer group has already applied
    `web`/`tenant`, and Fortify's default `['web']` would double it inside the
    tenant group.

  `fortify.passwords` is deliberately *not* swapped here — see the next
  entry for why swapping it at this phase is a no-op.

- **`loadOneTimePasswordRoutes()` registers in the open group for the same
  registration-time reason**, and its paths go through Fortify's own
  `RoutePath::for()`, so `config('fortify.paths')` overrides them like every
  neighbouring auth URL. Its verify leg carries `OneTimePasswordFeature::LIMITER`
  because that request has no `email` field: `fortify.limiters.login`'s
  `tenant|email|ip` key would collapse to `tenant||ip` and bucket every OTP
  verification from one IP together. Fortify draws the same distinction for
  its own 2FA challenge (`fortify.limiters.two-factor`).

- **(audit) A config key Fortify reads at request time cannot be swapped at
  route-registration time.** `fortify.passwords` was swapped inside
  `loadFortifyRoutes()`, by symmetry with `fortify.guard`, and restored in a
  `finally` — a no-op, because `PasswordResetLinkController::broker()`,
  `NewPasswordController::broker()` and `PasswordController::broker()` all
  read the key when the request arrives. Tenant password resets silently
  resolved the *central* `users` provider — and note that a broker reads its
  user provider from `auth.passwords.*`, a key `auth.guards.*` has no bearing
  on, so switching the guard alone never moves a reset off the central
  provider. The fix is
  `Services\Tenancy\Bootstrappers\PasswordBrokerBootstrapper`, which swaps
  the key on tenancy initialization; the broker it swaps to,
  `auth.passwords.tenant`, is defaulted by
  `Support\HostConfig::tenantPasswordBroker()`, and its tokens live in the
  tenant database's own `password_reset_tokens` table, created by
  `database/migrations/tenant/0001_01_01_000000_create_users_table.php`.
  **Ask which phase reads a key before deciding where to set it:** baked into
  a route = registration time, read by a controller = request time.

- **(audit) A `TenancyBootstrapper` must be a container singleton if it
  remembers anything.** `Tenancy::getBootstrappers()` is
  `array_map('app', config('tenancy.bootstrappers'))`, resolved afresh on
  *both* initialize and end — so an unbound class hands `revert()` a
  different instance than `bootstrap()` wrote to, and whatever it captured is
  gone. `AuthGuardBootstrapper` has never escaped this; it only looks
  correct because `revert()` falls back to `?? Context::Central->guard()`.
  `PasswordBrokerBootstrapper` is registered with `$this->app->singleton()`
  in `packageRegistered()` for this reason.

- **(audit) The login limiter is tenant-keyed, and the test for it needs
  `tenancy()->end()`.** `authThrottleKey()` mixes `tenancy()->tenant` into
  the bucket so tenant A's failed logins cannot lock the same address out on
  tenant B. In a feature test every request runs in one process against one
  container and *nothing ends tenancy between them*, so a central request
  issued after a tenant one still reads the previous tenant and the
  assertion fails against correct code. `tests/Feature/Auth/LoginRateLimitTest.php`
  calls `tenancy()->end()` for exactly this. Worth knowing beyond tests:
  under Octane the same leak is a live concern.


Guard *selection* is `.ai/rules/auth-guards.md`. This file is about who runs
what during login/logout, and the two traps worth re-reading before touching
any of it.

- **Dual-guard login is `Actions\Auth\LoginUser::handle(User, remember, ?guard)`,
  run as the last step of Fortify's pipeline, not a controller.**
  `NumerosisServiceProvider::registerFortify()` appends
  `Actions\Auth\LogInToCentralGuard` after Fortify's own
  `AttemptToAuthenticate`/`PrepareAuthenticatedSession` — by the time it
  runs, the request's own guard is already logged in; `LogInToCentralGuard`
  fetches the now-authenticated user and calls `LoginUser::run()`, which logs
  the *central* guard in too if it isn't already the one just used.
  `LoginUser` still does the guard→model resolution
  (`resolveUserForGuard()`/`userResolver()`) that predates Fortify; only the
  entry point moved.

- **Passwordless OTP is a pipeline step that replaces the password check, not
  a second factor after it.** `Actions\Auth\RedirectIfOneTimePasswordAuthenticatable`
  runs before `AttemptToAuthenticate`, only when `OneTimePasswordFeature::available()`.
  It identifies the candidate by email, sends the code, stashes
  `login.email`/`login.remember` in the session and redirects to
  `one-time-password.login` — it never calls `$next()`, so
  `AttemptToAuthenticate` does not run for a request this step already
  handled. `Http\Requests\Auth\NumerosisLoginRequest` (bound over Fortify's
  own `LoginRequest`, which `AuthenticatedSessionController::store()`
  type-hints as a concrete class, so a subclass binding still resolves) is
  what makes `password` optional when OTP is on —
  Fortify's stock `rules()` makes it unconditionally required, and that
  `FormRequest` validates *before* the pipeline even starts, so a password-less
  submission would 422 before this step got a chance to redirect it.

- **`CanonicalizeUsername` is carried over from Fortify's default pipeline, and has to stay ahead of the OTP step.** `Fortify::authenticateThrough()` replaces the pipeline wholesale, so a step left out is simply gone: dropping this one turns `fortify.lowercase_usernames` into a dead config key with nothing to notice. Its position matters too, because `RedirectIfOneTimePasswordAuthenticatable`'s candidate lookup is an exact-match `firstWhere('email', …)` — canonicalize after it and the address the limiter keys on is not the address the lookup uses.

- **`OneTimePasswordChallengeController::store()` and
  `RedirectIfOneTimePasswordAuthenticatable::handle()` both answer identically
  whether or not the address belongs to a user.** No password exists to check
  here, so a per-outcome response would make the OTP send/verify endpoints an
  unauthenticated account-existence oracle — a password login leaks nothing
  comparable, because a wrong password and an unknown user fail the same way
  there already. A session pointing at an address with no account fails code
  validation with the same generic message a wrong code gets.

  What it does *not* hide: an unauthenticated caller can make the package send
  mail to any address it holds an account for, and
  `one-time-passwords.only_one_active_one_time_password_per_user` means each
  send invalidates the previous code, so a third party can keep a victim's
  pending code from working. Both are inherent to passwordless email login;
  the `login` rate limiter (`NumerosisServiceProvider::registerAuthRateLimiters()`,
  keyed tenant + address + IP) is what bounds them.

- **`OneTimePasswordChallengeController` re-resolves the candidate by the
  session's address, never a guard-scoped id or a request field.** Reading it
  from the request would let a caller name any victim and skip the send step;
  `OneTimePasswordRule` would still refuse, but a control that only holds
  because a second one sits behind it is what produced that bug the first time.
  `OneTimePasswordLoginTest` mutation-tests that line. Resolving by address
  rather than id is also what keeps the controller agnostic about which guard
  is being logged into, the same reasoning `ResolvesLoginCandidate` encodes.
  No `#[\SensitiveParameter]` appears there on purpose: the submitted code
  never becomes a named parameter on that side — it stays inside `$request`
  and the validator, which Sentry scrubs by key rather than by attribute.

- **Logout is a listener, not a controller, because Fortify's controller
  actively fights dual-guard logout.** `AuthenticatedSessionController::destroy()`
  logs out only `config('fortify.guard')` and then invalidates the session.
  `Listeners\Auth\EndOtherGuardSession` is registered with an explicit
  `Event::listen()` in `packageBooted()` — Laravel's listener auto-discovery
  only scans a *host application's* `app/Listeners`, never a package's `src/`,
  so a discovered-by-convention listener here would silently never fire.
  It fires on `Illuminate\Auth\Events\Logout`, which `SessionGuard::logout()`
  dispatches *before* clearing state, so it still sees a live session to end.
  Guards each branch on `check()`/`tenancy()->initialized` before calling
  `logout()` on the other guard — unguarded, `logout()` unconditionally
  re-dispatches `Logout` even when nothing was logged in, which would recurse
  into this same listener. `Actions\Auth\LogoutUser` still exists, trimmed to
  a plain `handle(): void` for callers outside `POST /logout` (`Livewire\Actions\Logout`,
  tests) — it is not what the HTTP route runs anymore. It does not call
  `handle()` from the listener path either, because `destroy()` already owns
  session invalidation and would invalidate twice.

- **Never call `logout()` on the tenant guard outside tenant context; drop its
  session state directly.** `POST /logout` is a central-domain route as well as
  a tenant one, and `SessionGuard::logout()` resolves the current user before
  clearing anything. The tenant provider's model carries no connection of its
  own, so with tenancy uninitialized that lookup runs against the *central*
  database and hydrates whichever central user holds the id the shared session
  carries — soft-deleted rows included, since the tenant user model does not
  soft-delete — then cycles a remember token onto that row. The write lands on
  a stranger's central record, and the resulting `SyncedResourceSaved` carries
  no tenant, so the queued sync listener dies with
  `ModelNotSyncMasterException` twenty times over. `LogoutUser::endTenantSession()`,
  `EndOtherGuardSession::endTenantSession()` and
  `Http\Middleware\EnsureSessionMatchesTenant` all instead `Session::forget()`
  the guard's name and forget its recaller cookie. The same applies to any
  `check()`/`user()` call on that guard. The central guard's model always
  carries an explicit connection, so logging *it* out from tenant context is
  safe by comparison.

- **Rate limiting is tenant-keyed on purpose, and the OTP challenge gets its
  own limiter.** `NumerosisServiceProvider::registerAuthRateLimiters()`
  registers `login` (`Limit::perMinute(5)->by($tenant.'|'.$email.'|'.$ip)`)
  and `OneTimePasswordFeature::LIMITER` the same way, keyed by the address
  the send leg stashed in session rather than a field the verify request
  carries — so a caller cannot choose whose bucket to spend. Fortify's
  *default* `login` limiter keys on `lower(username).'|'.$ip` alone, with no
  tenant in it: two tenants sharing a user at the same email address would
  share one lockout counter, letting tenant A's failed attempts lock out
  tenant B's user. Regression-test rate limiting **with two tenants** — a
  single-tenant test passes whether or not the limiter is tenant-keyed, so it
  proves nothing.

- **`spatie/laravel-one-time-passwords` has the tenant-collision bug the
  limiter above avoids, and this package does not fix it.**
  `ConsumeOneTimePasswordAction` runs its own per-user limiter keyed
  `consume-one-time-password-attempt:{$user->getKey()}` — a bare primary key,
  which collides across tenant databases and with the central `users` table,
  so five wrong attempts against tenant A's user 1 also lock tenant B's user
  1 out for the window. It is a denial of service, not a bypass: guessing
  stays bounded by `OneTimePasswordFeature::LIMITER`, which is tenant-keyed.
  Left alone because fixing it means overriding a vendor action in a package
  this one only `suggest`s. Recorded here rather than in
  `NumerosisServiceProvider`, whose docblock it outgrew.

## OAuth identity matching (`Actions\Auth\Social\**`)

- **Identity is `(provider, provider_id)` only — never email alone.**
  `LoginWithSocialAccount::handle()` looks up an existing `SocialAccount` by
  that pair before it ever reads `$data->email`. Matching by email alone lets
  anyone who can claim a victim's address on *some* provider — one that
  issues unverified addresses, or simply lies — log in as them.
- **Linking to an existing account by email is conditional on both sides
  being verified.** `findVerifiedMatch()` only runs when
  `SocialUserData::$emailVerified` is true *and* the local `CentralUser` has
  a non-null `email_verified_at`. When the email matches an existing account
  but either side is unverified, `handle()` returns `null` rather than
  linking, and `HandleProviderCallbackController` sends the visitor to
  `login` with a "sign in first, then connect this account" message instead
  of authenticating them. Regression test:
  `tests/Feature/Auth/Social/SocialLoginTest::test_an_unverified_provider_email_refuses_to_link_and_redirects_to_login`.
- **`$emailVerified` is per-provider, decided in `ResolveSocialUser`, and
  defaults to `false`.** Only Google and GitHub expose whether the address
  was verified in their raw payload (`isEmailVerified()`); every other
  provider — including Discord and Facebook — cannot prove it, so this stays
  `false` for them. Adding a provider does not get free "verified" status
  just because the provider *has* a verified-email field; wire the specific
  raw-payload key `isEmailVerified()` reads before trusting it.
- **`SocialUserData` is the only shape anything downstream of
  `ResolveSocialUser` sees**; Socialite's own
  `Laravel\Socialite\Contracts\User` must not leak past that action. Its
  `$token`/`$refreshToken` are the provider's live credentials, plaintext
  until `SocialAccount`'s `encrypted` cast, and carry `#[SensitiveParameter]`
  so they stay out of stack traces. Sentry is wired here
  (`Concerns\TagsSentryScopeWithTenant`), so an unmarked argument leaves the
  machine on the next throw.
- **Never `->stateless()` on the redirect/callback routes.** These are web
  routes; Socialite's `state` parameter is the CSRF defence for the callback,
  and stateless mode drops it.
- **`Policies\SocialAccountPolicy::delete()` refuses unlinking a user's last
  credential when they have no password**, so a user cannot lock themselves
  out of their own account by disconnecting their only way in.
- **`social.destroy` carries `password.confirm.if-set`, never plain
  `password.confirm`.** A user who registered through OAuth has
  `users.password` null, and Fortify's confirm-password screen ends in
  `Hash::check($password, null)`, which cannot return true. Plain
  `password.confirm` therefore locked those users out of disconnecting
  anything at all, while the policy above was busy allowing it.
  `Http\Middleware\RequirePasswordIfSet` passes a passwordless user through
  and behaves as Laravel's `RequirePassword` for everyone else. Its alias is
  registered in **both** `NumerosisServiceProvider::registerMiddleware()` and
  `Support\Numerosis::middleware()` (see `middleware-registration.md`). The
  same trap is why `routes/web.php` gates `settings/password` on
  `PasswordResetFeature`; reach for this middleware before adding
  `password.confirm` to any other route a passwordless account can hit.
  Tests: `SocialLoginTest::test_a_passwordless_user_can_unlink_a_spare_account_without_confirming`
  and `::test_a_user_with_a_password_must_still_confirm_before_unlinking`.

## Two lessons from the deleted `PasswordlessLogin`, still load-bearing

Both are why Phase 5's OTP challenge is a Fortify pipeline step + a plain
controller rather than a Livewire component, and both need their own
regression test verified to fail against a stubbed-out step before being
trusted:

- **A Livewire component cannot be trusted to gate a public method on prior
  state.** The deleted `PasswordlessLogin::submitOneTimePassword()` shipped
  with its `OneTimePasswordRule` validation commented out, authenticating on
  `email` alone — reachable directly because every public method on a
  Livewire component is client-invokable, so an attacker never had to call
  `submitEmail()` first. **On any component, reaching a method proves nothing
  about which method ran before it; any method granting something must
  re-verify its own preconditions.** `.ai/rules/billing-checkout.md` records
  the same class of bug for `Payment::$pendingDomain`. A Fortify pipeline
  step doesn't have this shape at all — each step runs in a fixed server-side
  order the client cannot reach into.

- **`throttle:login` is route middleware and does not cover `/livewire/update`.**
  A Livewire login form has *no* rate limiting, silently — Livewire posts
  every interaction to one shared endpoint, never to the route the middleware
  is attached to. This is the concrete reason login and the OTP challenge are
  both plain controllers behind Fortify's routing, not Livewire components:
  `throttle:login`/the OTP limiter above only bind because the request
  actually goes through routing.
