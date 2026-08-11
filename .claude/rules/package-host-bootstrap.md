---
topic: package-host-bootstrap
updated: 2026-08-11
---

# Package/Host Bootstrap Wiring

Three independent bugs stacked on top of each other and took thin-app fully
down on 2026-08-07 (facade-root fatal crash-looping every worker → 404 on
every URL → `Target class [web] does not exist` 500 → `/admin` 404 with no
admin routes registered). Each one masked the next until fixed in order.
Recorded together because they're easy to mistake for one bug while
debugging — fixing #1 just exposes #2, fixing #2 just exposes #3. The
fourth item below isn't part of that chain — it's a mistake made *while
debugging* the chain, kept here because it's the same "host bootstrap
staleness" failure mode and worth the same caution.

- **Nothing in `src/` may call a facade at config-load time — `Domains::appUrl()` did, via a rector rewrite.** `config/numerosis.php` calls
  `Domains::apexFromAppUrl()` → `hostFromAppUrl()` → `appUrl()` directly in
  the config file body, which runs inside `LoadConfiguration`, one of the
  first bootstrappers — before `RegisterFacades` has run, so
  `Facade::$app` is still null. `Domains`'s own class docblock already
  documents this ("these methods are called from `config/numerosis.php`
  while the config repository is still being built... nothing here
  throws"), and originally read raw superglobals
  (`$_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL')`) for
  exactly that reason. A rector run (2026-08-06) rewrote that line to
  `Env::get('APP_URL', Request::server('APP_URL') ?? getenv('APP_URL'))` —
  `Request::server()` is the `Illuminate\Support\Facades\Request` facade,
  which broke the invariant the class comment was written to protect.
  Every artisan invocation fataled with `RuntimeException: A facade root
  has not been set`, thrown from inside config loading, before any
  exception handler exists to catch it — this is what crash-looped `queue`,
  `queue-provisioning`, and `reverb` under supervisor. Reverted to raw
  superglobal access. **Any future rector/rewrite pass touching `Domains.php`
  needs to be checked against this docblock by hand — a facade-safety rule
  this specific can't be enforced by rector's own rule set, since the rule
  that broke it (superglobal → facade) is usually a correct, encouraged
  rewrite everywhere else in the codebase.** See
  `.claude/rules/exception-handling.md`'s new bullet for how this same
  incident's *second* bug (masked reporting) compounded the first.

- **`App\Providers\Filament\AdminPanelProvider` / `TenantAdminPanelProvider` were host-owned at the time of this incident (2026-08-07) — they no longer exist at all, in either repo, as of Phase 2 of `.claude/plans/better-dx.md` (2026-08-10/11).** At the time, package extraction (numerosis@1d82f8f, thin-app@572a501) had shrunk these two files from 220/113 lines to 47/44 — everything package-owned (discovery paths, middleware stack, guards, widgets) had moved into `Nvade\Numerosis\Filament\NumerosisAdminPlugin`/`NumerosisTenantPlugin`, leaving only what a host still decided: the default panel and its colours, registered by instantiating the provider class from `bootstrap/providers.php`. Misreading `AdminPanelFeature`'s docblock as "the package registers this automatically" and deleting those `bootstrap/providers.php` entries (rather than investigating why the files were staged-deleted) turned a working `/admin` 302 into a 404 with no admin routes registered at all — diagnosed via `git status` showing `D  app/Providers/Filament/AdminPanelProvider.php` staged but never committed, i.e. fully recoverable with `git restore --staged --worktree <path>`. **Before deleting anything referenced by a host's `bootstrap/`, check `git status`/`git log` on it first** — "this class doesn't exist" can mean "staged for deletion, uncommitted" rather than "genuinely gone," and those need opposite fixes. That specific lesson outlives what it was diagnosing: **the panel providers themselves are gone now, on purpose, not by the same accident.** `src/Providers/Filament/NumerosisAdminPanelProvider.php` / `NumerosisTenantPanelProvider.php` register both panels directly (`$this->app->booting(fn () => $this->app->register(...))` in `NumerosisServiceProvider::registerFilamentPanels()`), gated by the same `shouldRegisterPanel()` check and now also overridable via `config('numerosis.panels.{admin,tenant}.provider')` for a host that wants its own class instead. A host's `bootstrap/providers.php` needs neither provider listed any more — the failure mode this bullet exists to warn about (a host deleting a class the package still needs) has moved to a different pair of files if it recurs: check `src/Providers/Filament/` before assuming either panel provider is safe to delete or replace wholesale.

- **A host's `bootstrap/app.php` needs both `->withRouting(...)` and `->withMiddleware(...)` — this bit thin-app on 2026-08-07 with no error at boot, and was fixed twice: once by adding the missing calls, once (Phase 2 of `better-dx.md`, 2026-08-10/11) by giving the package a self-healing fallback for exactly this omission.** thin-app's `bootstrap/app.php` had only `->withExceptions(...)`; both calls were simply missing. Two distinct failures stacked from this one omission:
  - No `->withRouting()` at all means `Numerosis::routes()` never runs —
    not the host's own routes, not even the package's `routes/web.php` +
    `routes/tenant.php`. Every URL 404s; `route:list` shows only
    Livewire/Flux asset routes, nothing from either `web.php`.
  - No `->withMiddleware()` call at all means
    `ApplicationBuilder::withMiddleware()`'s `afterResolving(HttpKernel::class,
    ...)` hook — the *only* place Laravel's baseline `web`/`api`
    middleware groups get pushed onto the `Router` — never runs.
    `NumerosisServiceProvider::registerMiddleware()` (which does run
    automatically, from `packageBooted()`) only defines the package's own
    aliases and `tenant`/`universal` groups; it assumes `web`/`api`
    already exist. Without them, every `Route::middleware('web')` (used by
    the package's own central-domain route group) dispatches into
    `BindingResolutionException: Target class [web] does not exist` —
    Laravel treats an unrecognised middleware group name as a class name
    to resolve. Reads like a totally unrelated new bug; it is the same
    root cause as the 404 above (`->withMiddleware()` missing), just
    surfaced one layer later once routing itself started working.

  Fixed at the time by adding both calls by hand:
  ```php
  ->withRouting(using: Numerosis::routes(...))
  ->withMiddleware(fn (Middleware $middleware) => Numerosis::middleware($middleware))
  ```
  **The ambiguity that caused this**: `NumerosisServiceProvider::registerMiddleware()`'s
  and `registerBroadcasting()`'s docblocks describe the corresponding
  *static* `Numerosis::middleware()` / `Numerosis::broadcasting()` /
  `Numerosis::broadcastChannelsPath()` helpers as "now optional" — true,
  since `packageBooted()` redoes that registration automatically. But the
  underlying **framework** calls, `->withMiddleware()` and
  `->withBroadcasting()` themselves, are a different thing — `->withRouting()`
  and `->withMiddleware()` still have no provider-level equivalent
  (`->withRouting()`'s registration must happen at `ApplicationBuilder`
  build time, before any provider boots, full stop) or their only purpose
  is to trigger a framework-owned side effect the package can't replicate
  from inside `packageBooted()` (`->withMiddleware()`'s job of seeding
  Laravel's own baseline groups). Reading "the static helper is optional"
  as "the framework hook is optional" is the exact misreading that
  produced this bug.

  **Fixed structurally in Phase 2 of `better-dx.md`, so the hand-written
  fix above is now the fallback path, not the only one.**
  `Numerosis::configure(?string $basePath = null): ApplicationBuilder` wraps
  `Application::configure()` and applies `withRouting()`/`withMiddleware()`/
  `withExceptions()` in one call — `bootstrap/app.php` becomes one line for
  a host that uses it. For a host that doesn't (writes its own
  `Application::configure()` chain and forgets one of the two calls),
  `NumerosisServiceProvider` now self-heals both gaps from inside
  `packageBooted()`:
  - `registerRoutesFallback()` queues an `$this->app->booted(...)` callback
    that calls `Numerosis::routes()` itself if `Numerosis::routesRegistered()`
    is still `false` by boot time — a no-op for a correctly-wired host,
    since a real `withRouting(using: Numerosis::routes(...))` call already
    registered them earlier in the boot sequence (`AppRouteServiceProvider`'s
    own `booting()`-phase callback runs before this `booted()`-phase one).
  - `seedMiddlewareBaselineIfMissing()` checks
    `$kernel->getMiddlewareGroups() !== []` — a host that called
    `withMiddleware()` at all (even with an empty closure) already has
    Laravel's baseline groups seeded by provider-boot time, since
    `HttpKernel::class` resolution (and its `afterResolving` hook) happens
    earlier, in the normal `public/index.php` flow. Only a host that never
    called `withMiddleware()` reaches the fallback, which seeds the same
    groups `withMiddleware()` itself would have via
    `Kernel::setMiddlewareGroups()`/`setMiddlewareAliases()`/`setGlobalMiddleware()`.

  This does not make `->withRouting()`/`->withMiddleware()` unnecessary —
  `docs/host-requirements.md`'s obligation #4 still lists a one-line
  `bootstrap/app.php` as one of the six things a host must provide, and
  `Numerosis::configure()` is the easy way to satisfy it — it makes omitting
  either call a silent no-op instead of a 404/500 crash-loop, which is the
  actual failure this whole bullet exists to prevent recurring.
