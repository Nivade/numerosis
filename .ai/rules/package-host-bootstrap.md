---
paths:
  - 'src/NumerosisServiceProvider.php'
  - 'src/Numerosis.php'
  - 'src/Boot/HostConfig.php'
  - 'src/Boot/Domains.php'
  - 'src/Routing/RouteLoader.php'
  - 'src/Enums/Tenancy/IdentificationMode.php'
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
  `.ai/rules/exception-handling.md`'s new bullet for how this same
  incident's *second* bug (masked reporting) compounded the first.

- **`App\Providers\Filament\AdminPanelProvider` / `TenantAdminPanelProvider` were host-owned at the time of this incident (2026-08-07) — they no longer exist at all, in either repo, as of Phase 2 of `.claude/plans/archive/better-dx.md` (2026-08-10/11).** At the time, package extraction (numerosis@1d82f8f, thin-app@572a501) had shrunk these two files from 220/113 lines to 47/44 — everything package-owned (discovery paths, middleware stack, guards, widgets) had moved into `Nvade\Numerosis\Filament\NumerosisAdminPlugin`/`NumerosisTenantPlugin`, leaving only what a host still decided: the default panel and its colours, registered by instantiating the provider class from `bootstrap/providers.php`. Misreading `AdminPanelFeature`'s docblock as "the package registers this automatically" and deleting those `bootstrap/providers.php` entries (rather than investigating why the files were staged-deleted) turned a working `/admin` 302 into a 404 with no admin routes registered at all — diagnosed via `git status` showing `D  app/Providers/Filament/AdminPanelProvider.php` staged but never committed, i.e. fully recoverable with `git restore --staged --worktree <path>`. **Before deleting anything referenced by a host's `bootstrap/`, check `git status`/`git log` on it first** — "this class doesn't exist" can mean "staged for deletion, uncommitted" rather than "genuinely gone," and those need opposite fixes. That specific lesson outlives what it was diagnosing: **the panel providers themselves are gone now, on purpose, not by the same accident.** `packages/filament/src/Providers/NumerosisAdminPanelProvider.php` / `NumerosisTenantPanelProvider.php` register both panels, and **the satellite package registers its own — core does not** (corrected 2026-09-01; this bullet previously named `src/Providers/Filament/` and a `NumerosisServiceProvider::registerFilamentPanels()` method, neither of which exists). Core's only involvement is `registerHostPanelProviders()`, a `booting()` callback that registers whatever class `config('numerosis.panels.{admin,tenant}.provider')` names, unguarded — a host naming a provider there is trusted to have Filament, and `numerosis-filament` stands down for that panel. Whether a package panel registers at all stays each plugin's own `shouldRegisterPanel()` check. A host's `bootstrap/providers.php` needs neither provider listed any more — the failure mode this bullet exists to warn about (a host deleting a class the package still needs) has moved to a different pair of files if it recurs: check `packages/filament/src/Providers/` before assuming either panel provider is safe to delete or replace wholesale.

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
  docblock describes the corresponding *static* `Numerosis::middleware()`
  helper as "now optional" — true, since `packageBooted()` redoes that
  registration automatically. But the underlying **framework** call,
  `->withMiddleware()` itself, is a different thing — `->withRouting()`
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
  subdomain by hand (`.claude/plans/archive/better-dx.md`'s Verification step 5)
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
  race one phase later.

  **Corrected 2026-09-01. This section used to propose read-modify-write of
  the whole parent array via `Config::array($parent, [])` plus one write, and
  called it "structurally sound … avoids the hazard entirely regardless of
  phase". It does not, and a session acting on it would ship a fix that
  changes nothing.** The incident above is not "the parent was a scalar", it
  is "the parent did not exist yet" — stancl's `mergeConfigFrom('tenancy')`
  had not run, so `tenancy.database` was absent. Reading an absent parent
  yields `[]`, merging into `[]` yields exactly the truncated array
  `Arr::set()` produced, and writing it back loses `prefix`/`suffix`/
  `managers` identically. Read-modify-write only protects the narrower case
  where the parent exists as a *non-array* value.

  What actually converts this failure from silent to loud is an **assertion,
  not a different write**: have `HostConfig::set()` walk the key's
  intermediate segments and throw naming the key when one is missing or not
  an array, for any key outside `numerosis.*` (which this package's own
  `mergeConfigFrom` populates before anything here runs). Nine of the twenty
  `self::set()` calls in `HostConfig` are 3+ segments under a foreign
  namespace — `tenancy.filesystem.root_override.local`,
  `tenancy.database.central_connection`, `auth.providers.users.model`,
  `queue.failed.database` and friends — so the guard has real surface. On a
  correctly-ordered boot every one of them passes and behaviour is unchanged;
  a fourth package reopening the race one phase later becomes an exception
  naming the exact key instead of a truncated array that surfaces months
  later on one route. Not built yet — recorded here so the next attempt
  starts from the right diagnosis.

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
  `TenancyRouting::identificationMiddleware()` /
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
  browser suite (`.ai/rules/testing.md`) serves in-process, already
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

## Two self-heals for an app that never called the builder method

`ApplicationBuilder::withMiddleware()` always registers
`Authenticate::redirectUsing(fn () => route('login'))` before it runs a host's
own callback, unconditionally, as plain skeleton behaviour. Until Fortify
registers a `login` route that default throws `RouteNotFoundException` on every
guest request to a protected route. `registerGuestRedirect()` overrides it by
calling `Authenticate::redirectUsing()` directly rather than through
`Numerosis::middleware()`'s `$middleware->redirectGuestsTo()`, because that
object only reaches `Authenticate` when a host passes it to `withMiddleware()`
— which Testbench, and any host that never calls `Numerosis::middleware()`,
does not. It falls back to `home` until the route exists, and is inert once
Fortify registers `login`.

`registerExceptionHandling()` covers the matching gap: an app that never calls
`->withExceptions()` has no `ExceptionHandler::class` binding at all, since that
binding is normally made by `ApplicationBuilder::withExceptions()` itself. It
binds one, skips a host that replaced Laravel's handler with its own, and runs
unconditionally because `Numerosis::exceptions()` is idempotent per handler
instance.

## `Facades\Numerosis` exists, and must never appear in `bootstrap/app.php`

Added 2026-09-05. `Nvade\Numerosis\Facades\Numerosis` accessors `Numerosis::class`, bound as a parameterless singleton in `packageRegistered()`, so a host gets `swap()`/`spy()`/`shouldReceive()`. `Numerosis` needed no change: `Facade::__callStatic()` does `$instance->$method(...)` and PHP permits calling a `static` method through an instance, so every method stays static and every internal call site keeps calling it directly.

Measured 2026-09-05: **Mockery does intercept originally-`static` methods reached this way** — it generates an instance method on a subclass, which wins over the inherited static one. `tests/Feature/Facades/NumerosisFacadeTest.php` asserts it, so a Mockery upgrade changing that is loud rather than silent.

`routes()`, `middleware()`, `exceptions()` and `configure()` must not go through the facade. They run while `ApplicationBuilder` is being built, before `RegisterFacades`, where `Facade::getFacadeRoot()` is null and the call throws `RuntimeException: A facade root has not been set` — the crash-loop this file already documents twice, reached through a third door.

## Moving a file between namespaces here breaks two things silently

Both hit during the 2026-09-07 `src/` reorganization, and neither is a syntax
error — one is a runtime `DirectoryNotFoundException`, the other only appears
under PHPStan or at the moment the call executes.

**`dirname(__DIR__, N)` counts directory levels, so any move retargets it.**
`Numerosis::routes()` and `::tenantMigrationPaths()` reach `routes/` and
`database/migrations/tenant`, and `InstallNumerosisCommand` reaches
`database/migrations/central`, by walking up from `__DIR__`. Moving
`InstallNumerosisCommand` one level deeper made it look for
`src/database/migrations/central`; 39 tests failed at once, all with the
same `DirectoryNotFoundException`, none naming the move. Grep
`dirname(__DIR__` across `src/` before and after any file move.

**Same-namespace resolution means a working call site can have no import.**
`HostConfig` called `FeatureRegistry::enabled()` with no `use` line, because
both were then in `Nvade\Numerosis\Support`. Moving `HostConfig` to `Boot\`
on 2026-09-11 left it resolving a class that no longer existed, and a grep
for files *importing* `FeatureRegistry` did not list it. Search for the bare
`ClassName::` as well as the FQCN, and run `composer analyse` cold —
PHPStan's `class.notFound` is what catches this, not the test suite.

**A path string naming a class's file is invisible to every FQCN grep.**
`tests/Feature/Docs/HostRequirementsTest` read
`$root.'/src/Support/HostConfig.php'` with `file_get_contents`, so the same
move broke it in a way nothing in the move's own checklist could find. It
resolves the path by reflection now. Grep moved files by *path* as well as by
namespace, and prefer `(new ReflectionClass(X::class))->getFileName()` in any
test that reads source.

## The `workbench/` harness is a host, and was never actually running the package (2026-09-12)

Until `NumerosisServiceProvider` was named in `testbench.yaml`, `composer serve`
and `composer build` booted an app with Numerosis absent — a root package is
not discovered by `PackageManifest`. Every green `composer build` before that
proved nothing, and `php artisan` had none of the package's commands.

Making it load surfaces three traps, all of which read as unrelated failures:

- **A missing sqlite file silently demotes `database.default` to `:memory:`.**
  `Orchestra\Testbench\Bootstrap\LoadConfiguration:162` swaps `sqlite` for
  `testing` when the file does not exist. `workbench:build` creates the file
  *after* the app has booted, so the run right after a wipe boots in-memory
  while `HostConfig` clones `central` from whatever default was — migrations
  then land on two different connections and fail as
  `no such table: cache (Connection: testing, Database: :memory:)`. `touch`
  the file before building. Nothing warns.
- **`testbench.yaml`'s `env:` block is too late for
  `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS`.** `LoadMigrationsFromArray` has
  already called `Env::get()` by then, so the key sits there reading as
  configuration while doing nothing. It belongs in the composer script, as
  `@putenv`. Tell: Testbench's `0001_01_01_000000_testbench_create_users_table`
  still runs.
- **The default migrations must be off, for the reason
  `docs/host-requirements.md:125` already gives a host:** the package ships
  extended `users` and `jobs` migrations under `database/migrations/central`,
  and the migrator collides on the *table*, not the filename. Turning them off
  takes the stock cache table with it, so the workbench keeps its own under
  `workbench/database/migrations`.

**The harness runs on MySQL, set in `testbench.yaml`'s `env:` block.** That
block *does* apply to database config, unlike the migrations switch above:
config is read late enough, and it reaches a bare `php artisan` rather than
only the composer scripts. It is there because the package documents MySQL as
a requirement while the skeleton ships `DB_CONNECTION=sqlite`, so the harness
was pointed at a database the package refuses. Create the schema once:

```bash
docker exec numerosis-mysql-1 mysql -uroot -proot -e "create database numerosis_workbench"
composer build
php artisan tenancy:provision demo --owner=<global_id>
php artisan queue:work --queue=provisioning --stop-when-empty
```

That run is worth doing: `QUEUE_CONNECTION=sync` in the suite means no test
exercises a worker boundary, and the first one ever run found a live bug (see
`exception-handling.md` on re-thrown exception codes).

**What actually blocks SQLite is smaller than "MySQL-only" suggests, and
`CREATE DATABASE` is not part of it.** stancl ships
`TenantDatabaseManagers\SQLiteDatabaseManager`, which writes one file per
tenant, and it is wired in its own config: on a SQLite run `CreateTenant`,
`CreateTenantDatabase` and `MigrateTenantDatabase` all complete. Seeding is
where it dies, on `unknown function: SUBSTRING_INDEX()` from
`2025_12_17_035929_add_ability_and_context_virtual_columns_to_permissions`.
Measured 2026-09-12; do not repeat the guess that the tenancy model itself
rules SQLite out.

The rest of the MySQL surface is two places, both small, plus one gap:
`PruneOrphanedTenantDatabases` querying `INFORMATION_SCHEMA.SCHEMATA`, the
generated `permissions.ability`/`context` columns (which are `#[Guarded]`,
derived from `name`, and queried by nothing in this repo), and
`CleansUpTenancyDatabases` returning early for a non-MySQL driver, so SQLite
tenant files would leak rather than fail. The cost of supporting SQLite is
the test matrix, not the code.
