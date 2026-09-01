---
topic: package-host-bootstrap
updated: 2026-08-31
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

- **`App\Providers\Filament\AdminPanelProvider` / `TenantAdminPanelProvider` were host-owned at the time of this incident (2026-08-07) — they no longer exist at all, in either repo, as of Phase 2 of `.claude/plans/better-dx.md` (2026-08-10/11).** At the time, package extraction (numerosis@1d82f8f, thin-app@572a501) had shrunk these two files from 220/113 lines to 47/44 — everything package-owned (discovery paths, middleware stack, guards, widgets) had moved into `Nvade\Numerosis\Filament\NumerosisAdminPlugin`/`NumerosisTenantPlugin`, leaving only what a host still decided: the default panel and its colours, registered by instantiating the provider class from `bootstrap/providers.php`. Misreading `AdminPanelFeature`'s docblock as "the package registers this automatically" and deleting those `bootstrap/providers.php` entries (rather than investigating why the files were staged-deleted) turned a working `/admin` 302 into a 404 with no admin routes registered at all — diagnosed via `git status` showing `D  app/Providers/Filament/AdminPanelProvider.php` staged but never committed, i.e. fully recoverable with `git restore --staged --worktree <path>`. **Before deleting anything referenced by a host's `bootstrap/`, check `git status`/`git log` on it first** — "this class doesn't exist" can mean "staged for deletion, uncommitted" rather than "genuinely gone," and those need opposite fixes. That specific lesson outlives what it was diagnosing: **the panel providers themselves are gone now, on purpose, not by the same accident.** `packages/filament/src/Providers/NumerosisAdminPanelProvider.php` / `NumerosisTenantPanelProvider.php` register both panels, and **the satellite package registers its own — core does not** (corrected 2026-09-01; this bullet previously named `src/Providers/Filament/` and a `NumerosisServiceProvider::registerFilamentPanels()` method, neither of which exists). Core's only involvement is `registerHostPanelProviders()`, a `booting()` callback that registers whatever class `config('numerosis.panels.{admin,tenant}.provider')` names, unguarded — a host naming a provider there is trusted to have Filament, and `numerosis-filament` stands down for that panel. Whether a package panel registers at all stays each plugin's own `shouldRegisterPanel()` check. A host's `bootstrap/providers.php` needs neither provider listed any more — the failure mode this bullet exists to warn about (a host deleting a class the package still needs) has moved to a different pair of files if it recurs: check `packages/filament/src/Providers/` before assuming either panel provider is safe to delete or replace wholesale.

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

- **`HostConfig::apply()` ran during `packageRegistered()` (this package's
  own `register()` phase) until 2026-08-11, and that was silently wrong for
  any key it writes into a namespace another package's `mergeConfigFrom()`
  also populates.** Provider `register()` order across auto-discovered
  packages isn't this package's to control — empirically,
  `Nvade\Numerosis\NumerosisServiceProvider::register()` runs *before*
  `Stancl\Tenancy\TenancyServiceProvider::register()` (alphabetical-ish
  discovery order: "Nvade" sorts before "Stancl"). `HostConfig::
  tenancyCentralConnection()`'s `Config::set('tenancy.database.
  central_connection', 'central')` therefore ran while `tenancy.database`
  wasn't an array yet (stancl's own `mergeConfigFrom('tenancy')` hadn't run)
  — Laravel's `Arr::set()` responds to a non-array intermediate segment by
  replacing it wholesale, so `tenancy.database` became just
  `{central_connection: 'central'}`. When stancl's provider registered
  *later* and ran its own `mergeConfigFrom`, that call's `array_merge(stock,
  existing)` saw an *existing* `database` key (however truncated) and kept
  it outright — `array_merge()` is one level deep, so a partial existing
  value beats a complete stock one, permanently discarding `prefix`/
  `suffix`/`managers`. Same trap hit `tenancy.filesystem.disks`/
  `tenancy.filesystem.root_override.local` — every `HostConfig` entry
  writing more than one segment below a key another package's
  `mergeConfigFrom()` still needs to touch. Surfaced as `Configuration value
  for key [tenancy.database.prefix] must be a string, NULL given` from
  `InteractsWithTenantModules::getEnabledModuleNames()`, and **only ever on
  a tenant-subdomain request** — the tenant Filament panel's plugin closure
  is what reads that key, and it's only evaluated when that specific panel
  resolves, never for a central request. Every central smoke check (`/`,
  `/login`, `/admin`) stayed green throughout; only hitting a real tenant
  subdomain by hand (`.claude/plans/better-dx.md`'s Verification step 5)
  caught it, and `tests/Feature/FreshHostTest.php` (step 1, same session)
  couldn't have either — that harness never provisions a real tenant
  through the actual pipeline against a config a *second* package still
  needs to merge into, since Testbench's own provider discovery order
  happens to differ from a real Composer install's. **Fixed by moving
  `HostConfig::apply()` into a `booting()` callback**, registered first
  thing in `packageRegistered()` (before `registerFilamentPanels()`'s own
  `booting()` callback, so it still runs first) — `booting()` callbacks fire
  once every provider's `register()` has completed, including stancl's, and
  before any provider's `boot()` starts, which turns out to be early enough
  for everything `HostConfig` normalizes. `HostConfigTest`'s `rebootPackage()`
  now calls `HostConfig::apply()` directly rather than `packageRegistered()`
  (which no longer touches it at all) — a reminder that **this exact
  register-vs-booting distinction is why `NumerosisServiceProviderDefaultsTest`
  and `HostConfigTest` can no longer share one `rebootPackage()` implementation**,
  since the former still tests things that *do* run inline in `packageRegistered()`
  (the three Livewire/filesystem defaults, the seeder binding).

  ## Suggested better approach

  Any *future* `HostConfig` entry that writes a key three or more segments
  deep, under a top-level namespace this package doesn't itself own
  (`tenancy.*`, `auth.*` — as opposed to `numerosis.*`, which this package's
  own `mergeConfigFrom` populates before `packageRegistered()` ever runs),
  should be treated as suspect by default and checked against this bullet —
  not assumed safe because `HostConfig::apply()` now runs from `booting()`.
  `booting()` is early enough for stancl specifically because stancl is a
  normal auto-discovered package with nothing unusual about its own
  registration; a *third* package with an even later or conditional
  `register()` (deferred providers, in particular) could reopen the same
  race one phase later. The structurally sound fix — read-modify-write the
  whole parent array via `Config::array($parent, [])` plus one write of the
  merged result, rather than a multi-segment dotted `Config::set()` — avoids
  the `Arr::set()` auto-vivification hazard entirely regardless of phase,
  and is worth doing the next time this file is touched, rather than relying
  on phase ordering to keep saving it.

- **`Numerosis::middleware()` fatally crashed every real (non-Testbench)
  request and every `artisan` invocation, and no test in this repo could
  have caught it — found 2026-08-31 scaffolding a genuinely fresh host
  (`numerosis-thin-app`) and hitting it on the very first `curl`.**
  `ApplicationBuilder::withMiddleware($callback)` registers `$callback`
  through **two** `afterResolving()` hooks, not one:
  `afterResolving(HttpKernel::class, ...)` for real requests, and
  `afterResolving(ConsoleKernel::class, ...)` (a separate call, a few lines
  later in the same method) so middleware aliases also resolve for Artisan.
  Both fire the instant the container first builds that kernel object —
  which happens *before* the kernel's own `bootstrap()` call, i.e. before
  `RegisterFacades` has run. `Numerosis::middleware()` calls
  `TenancyServiceProvider::identificationMiddleware()` /
  `::tenancyRouteMiddleware()`, both of which call
  `IdentificationMode::current()`, which reads `Config::string(...)` —
  and `Config::__callStatic()` throws `RuntimeException: A facade root has
  not been set` the instant it's asked to resolve with no app bound yet.
  Every `php artisan migrate`, every real HTTP request, crash-looped
  supervisor's `php` worker before the exception handler existed to report
  it — the exact `Domains::appUrl()` class of bug this file already
  documents, reached through a different door, and just as invisible to
  Testbench: that harness boots the whole app, including `RegisterFacades`,
  *before* running any command or dispatching any request, so neither
  kernel's premature resolution can ever happen there. **This package has no
  test that boots a real kernel from a cold, un-bootstrapped process** — the
  browser suite (`.claude/rules/testing.md`) serves in-process, already
  bootstrapped, same as every other test here.

  Fixed in `IdentificationMode::current()`: falls back to `self::Subdomain`
  when `Facade::getFacadeApplication() === null`, rather than reading
  `Config`. Safe specifically because `NumerosisServiceProvider::registerMiddleware()`
  **unconditionally** re-registers the real aliases later, from
  `packageBooted()` (after `RegisterFacades`, after `LoadConfiguration`) —
  the premature call's only job is to not crash the process before that
  correction runs; whatever it computes in between is thrown away.

  ## Suggested better approach

  The only reason a fallback-to-default is safe here is that a second,
  unconditional, correctly-timed registration already exists and this
  package happened to have already built it (for a different reason — a
  host that never calls `Numerosis::middleware()` at all). A future
  `Numerosis::*()` callback registered through `ApplicationBuilder`
  (`withRouting`, `withExceptions`, any future `with*()`) that reads config
  and has **no** such `packageBooted()` self-heal would need the same
  facade-root guard to avoid this exact crash, and would need the self-heal
  built alongside it, not assumed. Grep this package for `Facade::getFacadeApplication`
  before adding a fourth one by hand — worth turning into a shared helper
  (`Numerosis::whenBootstrapped(fn () => ..., fallback: ...)` or similar) once
  a third case shows up, rather than re-deriving the guard each time.
