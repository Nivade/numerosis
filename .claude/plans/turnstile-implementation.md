# Cloudflare Turnstile Implementation (via ryangjchandler/laravel-cloudflare-turnstile)

**Status: ✅ Executed.** Commit `999db04`. `app/Features/Turnstile/RegistersTurnstileDirective.php`
and `config/services.php`'s `turnstile.features` array confirmed in code.

## Scope

Add Turnstile to all unauthenticated/token-gated entry points:

- `App\Livewire\Auth\Register` (`app/Livewire/Auth/Register.php`)
- `App\Livewire\Auth\ForgotPassword` (`app/Livewire/Auth/ForgotPassword.php`)
- `App\Livewire\Auth\PasswordlessLogin` (`app/Livewire/Auth/PasswordlessLogin.php`)
- `App\Livewire\Auth\ResetPassword` (`app/Livewire/Auth/ResetPassword.php`)
- `App\Livewire\Invitations\Accept` (`app/Livewire/Invitations/Accept.php`)

`App\Livewire\Auth\Login` is dead code (commented out at `routes/auth.php:20`,
Filament panel's own login page used instead) — excluded, flagged for
separate cleanup, not touched here. Tenant registration wizard
(`/get-started`) sits behind `auth` middleware already (`routes/web.php:39`)
— not a bot-protection target, excluded.

## Current state (researched)

- No captcha/honeypot package installed today, no Cloudflare config in
  `config/services.php` or `.env.example`.
- Register/forgot-password/reset-password/invitation-accept routes are
  **unthrottled** at the route level (only Login/PasswordlessLogin do manual
  `RateLimiter` checks in-component). Adjacent gap, not fixed by this plan —
  flagged at the end as a separate follow-up.
- No CSP middleware exists (`config/livewire.php:269` has `csp_safe: false`
  too) — Turnstile's script tag needs no allowlisting today. If a CSP is
  added later, `https://challenges.cloudflare.com` must be allowed for
  `script-src` and `frame-src`.
- Cloudflare-fronting is **not confirmed in code** (`trustProxies('*')` is
  generic, not Cloudflare-specific) — irrelevant to Turnstile itself (it's a
  client-widget + server-verify API, not IP-based).

## Package choice: ryangjchandler/laravel-cloudflare-turnstile

Switched from a hand-rolled implementation to this package — it already
covers config, widget rendering, server-side verification, and test fakes,
so building a parallel `App\Turnstile\*` namespace (own config file, own
provider, own feature-array bootstrap, own `ValidTurnstileToken` rule) would
just be reimplementing what's on the shelf. What the package provides:

- **Config**: lives in `config/services.php` under a `'turnstile'` key
  (`'key'` = site key, `'secret'` = secret key), env vars
  `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`. No separate config file to
  publish.
- **Blade components**: `<x-turnstile.scripts />` (goes in `<head>`, loads
  Cloudflare's JS once) and `<x-turnstile />` (the widget div, accepts
  `data-theme`, `data-action`, `data-callback`, etc. as passthrough
  attributes).
- **Validation rule**: `RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile`,
  applied to the field name the package expects: `cf-turnstile-response`.
- **Facade**: `RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile`,
  with `Turnstile::fake()` / `->fail()` / `->expired()` / `Turnstile::dummy()`
  for tests — replaces the plan's original `Http::fake()`-against-siteverify
  approach entirely.
- **Service provider**: auto-registered via package discovery — nothing to
  add to `bootstrap/providers.php`.

This removes the following from the original plan wholesale: `config/turnstile.php`,
`App\Turnstile\Contracts\Feature`, `App\Turnstile\Features\RegistersBladeComponent`,
`App\Turnstile\ValidTurnstileToken`, `App\Providers\TurnstileServiceProvider`,
and the whole "package-readiness" self-contained-namespace rationale — none of
it applies once the widget/rule/config are someone else's package to
maintain, not ours to extract later.

## Opt-in wrapper (still needed — the package itself has no on/off switch)

The package's `<x-turnstile />` always renders if used, and its `Turnstile`
rule always calls out to Cloudflare — there's no `config('services.turnstile.enabled')`
equivalent. To keep local/dev working with zero Cloudflare setup:

- `config('services.turnstile.enabled')` (bool, `TURNSTILE_ENABLED` env,
  default `false`) — added alongside the package's own `turnstile.key`/`turnstile.secret`
  in `config/services.php`, not a new config file.
- **One Feature class**, per this app's own established convention
  (`.claude/plans/opt-in-feature-classes.md` — `App\Contracts\Feature`,
  `bootstrap(): void`, resolved from a `features` class-string array, same
  shape as `Stancl\Tenancy`'s `config('tenancy.features')`). Explicitly asked
  for over a plain `@if(config(...))` scattered across five views, even
  though the underlying flag is a single boolean — see below for why this
  isn't the scope-discipline violation that document warns against.
  - `App\Contracts\Feature` (new, shared — first consumer; the social-login
    and chat plans reuse the same interface when they're implemented).
  - `App\Features\Turnstile\RegistersTurnstileDirective implements Feature` —
    `bootstrap()` registers a `Blade::if('turnstileEnabled', fn (): bool =>
    Config::boolean('services.turnstile.enabled'))` directive. This is genuine
    boot-time registration (mirrors `Blade::componentNamespace` /
    `Event::listen` in the other Feature examples), not a bare passthrough —
    it gives every Blade view one call site (`@turnstileEnabled ... @endturnstileEnabled`)
    instead of five copies of `@if(config('services.turnstile.enabled')))`.
  - `config('services.turnstile.features')` = `[App\Features\Turnstile\RegistersTurnstileDirective::class]`
    — comment out to drop the directive registration entirely (falls back to
    Blade throwing on an unknown directive, which is the honest failure mode
    for "someone disabled the Feature but a view still uses it").
  - `AppServiceProvider::boot()` gets a small `bootstrapFeatures(array
    $features)` loop, called with `Config::array('services.turnstile.features')`
    — same helper the social/chat plans say they'll reuse.
  - PHP-side validation rule arrays (inside each component's `validate()`
    call) read `config('services.turnstile.enabled')` directly — there's no
    Blade directive equivalent server-side, and it's the same config key the
    directive itself reads, so it stays one source of truth either way.
- `.env.example`: add `TURNSTILE_ENABLED=false`, `TURNSTILE_SITE_KEY`,
  `TURNSTILE_SECRET_KEY`, commented with Cloudflare's published always-pass
  test keypair (`1x00000000000000000000AA` / `1x0000000000000000000000000000000AA`)
  for local dev.

## Livewire wiring

Each of the five Livewire components:
- Public property `public ?string $turnstileResponse = null;` — bind it in
  the Blade view via `wire:model` on `<x-turnstile />` per the package's
  documented Livewire pattern (field posts back as `cf-turnstile-response`
  when submitted as a plain form, but under Livewire the package's own JS
  writes to whatever `wire:model` target the component attribute names —
  confirm exact binding attribute name against installed package version
  before wiring, it's `wire:model` on the component tag itself per the
  package's Livewire docs).
- If more than one widget could ever render on the same page (not the case
  here — each of these five is a standalone page), the package requires a
  unique `id` per instance (`/^[a-zA-Z_][a-zA-Z0-9_-]*$/`). Not needed here
  since each Livewire component owns its own page, noted only as a trap for
  later.
- Validated at the same point the rest of the form is (`register()` /
  `sendResetLink()` / `resetPassword()` / the invitation-accept action /
  PasswordlessLogin's OTP-request method) — not a separate step, so a failed
  Turnstile check surfaces as an ordinary form validation error next to the
  field.

## Files touched (implementation, not yet done)

- `composer.json` / `composer.lock` — add `ryangjchandler/laravel-cloudflare-turnstile`.
- `config/services.php` — add `turnstile` array (`key`, `secret`, `enabled`,
  `features`).
- `app/Contracts/Feature.php` — new, shared contract.
- `app/Features/Turnstile/RegistersTurnstileDirective.php` — new.
- `app/Providers/AppServiceProvider.php` — `bootstrapFeatures()` loop added
  to `boot()`.
- `.env.example` — `TURNSTILE_ENABLED` / `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`
  + test-keypair comment.
- Five Livewire components — add `turnstileResponse` property + conditional
  rule (`PasswordlessLogin` overrides the inherited `submitEmail()` since
  that's the OTP-request step, not `submitOneTimePassword()`).
- `resources/views/partials/head.blade.php` — `@turnstileEnabled` wraps
  `<x-turnstile.scripts />` once, shared by all five (all five Livewire
  components use `#[Layout('layouts.auth')]` → `layouts.auth.blade.php` →
  `x-layouts::auth.card` → `partials.head`, confirmed, so one shared location
  is correct, not an assumption).
- Five Blade views — `@turnstileEnabled ... @endturnstileEnabled` wraps
  `<x-turnstile wire:model="turnstileResponse" />` in each form. Note
  `PasswordlessLogin`'s email-request view lives at
  `resources/views/vendor/one-time-passwords/livewire/email-form.blade.php`
  (published vendor view, sole consumer), not under `resources/views/livewire/`.

## Testing

- Package's `Turnstile::fake()` / `->fail()` / `->expired()` replace the
  original plan's `Http::fake()`-against-`siteverify` approach — no need to
  fake the HTTP call manually, use the facade.
- Per-component feature test, run with `TURNSTILE_ENABLED=true` in test
  config: `Turnstile::fake()` + valid `cf-turnstile-response` → passes;
  `Turnstile::fake()->fail()` → rejected with validation error — for all
  five components.
- A second pass (or shared dataset) confirms disabled-by-default: with
  `TURNSTILE_ENABLED=false` (or unset), existing form tests continue to pass
  with zero Turnstile/Cloudflare config, proving opt-in doesn't break
  anything already relying on these forms.
- No browser/Dusk test needed for the widget itself (Cloudflare's iframe
  can't meaningfully be driven in a headless test).

## Discovered during implementation

- **A third passwordless-login implementation exists**, not covered by this
  plan's scope: the central `login` route (`routes/auth.php:19`,
  `Route::livewire('login', 'pages::auth.passwordless-login')`) resolves to a
  Volt single-file component at
  `resources/views/pages/auth/⚡passwordless-login.blade.php` — its own
  inline `extends OneTimePasswordComponent` class, separate from
  `App\Livewire\Auth\PasswordlessLogin`. The latter (this plan's actual
  target) turned out to be live too, just reached differently than assumed:
  it's the tenant Filament panel's `->login()` page
  (`TenantAdminPanelProvider.php:69`), not dead code. So there are now two
  live, independent passwordless-login entry points — central-domain login
  (Volt, unprotected) and tenant-panel login (`App\Livewire\Auth\PasswordlessLogin`,
  now Turnstile-gated). Protecting the central one is a real, separate gap —
  flagged here, not fixed, since it wasn't in the original scope list and
  touching a Volt single-file component's inline class is a different shape
  of change than the four class-based components this plan already covers.

## Explicitly out of scope

- Route-level `throttle:` middleware on the currently-unthrottled auth routes
  — a real gap, adjacent to bot protection, but a separate change.
- `App\Livewire\Auth\Login` (dead code) and the authenticated tenant
  registration wizard — both excluded per scope above.
- Any CSP introduction — noted only so whoever adds a CSP later doesn't
  forget to allowlist Cloudflare's script/frame origins.

## Open items for whoever picks this up

- Confirm real Turnstile site/secret keys are provisioned in Cloudflare
  dashboard and land in each environment's real `.env` (not committed).

Resolved during implementation: `wire:model="turnstileResponse"` on
`<x-turnstile />` confirmed directly against the installed package's README
and `View/Components/Turnstile.php`/`Rules/Turnstile.php` source (v3, see
`composer show`). Shared guest layout confirmed as `partials/head.blade.php`
(all five components route through it). Composer dependency addition was
confirmed with the user before running `composer require`.
