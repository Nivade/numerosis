# Package Boundaries

> **Rewritten 2026-08-31 (section F of `.claude/plans/numerosis-consolidation.md`).**
> The original version of this file argued that the split could not happen
> because this package had exactly one extension point per concern, each of
> them "replace it wholesale" rather than "contribute to it". **Every seam it
> asked for now exists**, and four packages were extracted through them. What
> follows is the seam map — what to contribute through, and the boundary facts
> that are still load-bearing. The old "here is why you cannot" framing is
> gone; its conclusions are void, its mechanisms are preserved below and in
> `.claude/rules/package-split.md`.

Layout: one repo, core at the root plus `packages/{ui,auth-ui,filament,onboarding}`,
path-installed from a single `{"type":"path","url":"packages/*"}` entry and
published as read-only splits on tag. `tests/Feature/PackageBoundariesTest.php`
is what enforces the boundaries now — in a monorepo the filesystem enforces
nothing.

## The seams

All on `Nvade\Numerosis\Support\{Numerosis,Features}`, all additive. A
satellite or a host calls these; neither ever names the other's classes.

| Contribute | Call | Notes |
|---|---|---|
| central-domain routes | `Numerosis::addCentralRoutes(Closure)` | callback runs **once per configured central domain**, inside that domain's own `Route::middleware('web')->domain($domain)` group. A plain `Route::get()` instead would answer on every tenant subdomain, and nothing would fail — `SatelliteRouteContributionTest` is the guard. |
| tenant routes | `Numerosis::addTenantRoutes(Closure)` | same, inside the single `Route::middleware('tenant')` group. |
| a `Feature` | `Features::register(class-string<Feature>)` | merges with `config('numerosis.features')`; `Features::registered()` tells a contributed feature from a host-configured one. |
| tenant migrations | `Numerosis::addTenantMigrationPath(string)` | `HostConfig::tenancyMigrationParameters()` already treated `--path` as an array; this is the public way in. |
| seed data | `Numerosis::addTenantSeeder()` / `addCentralSeeder()` | ran by the package's own `TenantDatabaseSeeder` / `DatabaseSeeder`. |
| permissions | `Numerosis::addPermissionContext(string)` | a missing permission row is a **500, not a 403** (`.claude/rules/auth-guards.md`), and Filament evaluates every resource's `viewAny` on every page render, so one missing context breaks the whole panel. |
| views | `->hasViews('numerosis')` from the satellite's own provider | `FileViewFinder::addNamespace()` *appends*, so several packages serve one namespace. Paths are searched in registration order — files must be **moved, never copied**. |
| the tenant panel's login page | `numerosis.panels.tenant.login` | a Livewire component class. `null` = Filament's own login page. |
| the registration wizard | `numerosis.panels.admin.tenant_registration_component` | a Livewire **alias**, not a class — that is what keeps core and `packages/filament` from naming `packages/onboarding`'s classes. |
| a panel wholesale | `numerosis.panels.{admin,tenant}.provider` | core registers what you name and `numerosis-filament` stands down for that panel. |

`Numerosis::registerRoutesUsing()` / `registerMiddlewareUsing()` /
`registerBroadcastingUsing()` still exist and still replace the whole
mechanism. Reach for the `add*` seams first; the `registerXUsing()` ones are
for a host that genuinely wants none of the defaults.

## Boundary facts that still bite

- **A satellite must be able to register into a world where core's config is
  not there, and do nothing.** Larastan boots an application that discovers
  every *vendor* package but not the root one, so every satellite registers
  with core absent — the same shape as a host that installs a satellite and
  doesn't register core. `Features::all()` reads
  `Config::array('numerosis.features', [])` for exactly this reason. Sentinel
  on a key **only core writes**; `Arr::set()` auto-vivifies, so "the namespace
  exists" is not evidence core registered.

- **A satellite config write belongs in the register phase only where the
  parent namespace is deep-filled.** `numerosis.panels` survives a
  `packageRegistered()` write because `HostConfig::numerosisConfig()`
  deep-fills it; `numerosis.tenancy` does not, and the identical write
  destroyed `implementations`/`provisioning`/`identification`. Full mechanism
  and both symptoms in `.claude/rules/package-split.md`.

- **A feature class listed in config but not installed is a hard container
  failure at boot, and the first symptom is the wrong one.**
  `NumerosisServiceProvider` does `$this->app->make($feature)->bootstrap()`
  over `Features::all()` (`src/NumerosisServiceProvider.php:238-245`), while
  `Features::names()`'s `is_a($class, NamedFeature::class, true)` autoloads
  and quietly returns `false` for a missing class — so the *name map* silently
  loses the entry first and the crash arrives from the `make()` loop. Any
  packaging change that could leave a feature class unavailable needs the
  config change in the same commit.

- **Core still names `Filament\`, in 11 files, and that is fine.** Every one
  is lazy — a method type-hint, a `use` import reached only when a panel
  exists, or a `class_exists()`-guarded call. `Support\Compat\Filament*` stay
  in **core**: they are what lets core's own models load without Filament, so
  a satellite owning them would invert the dependency they exist to prevent.
  The asymmetry (`extends`/`implements`/`use <Trait>` resolve eagerly, type
  hints do not) is in `.claude/rules/optional-dependencies.md`.

  The one real cycle this file used to name is **resolved**:
  `Concerns\Modules\PurchasesModules` returned `Filament\Actions\Action`
  objects from core, and its only two consumers were Filament pages. It lives
  at `packages/filament/src/Concerns/Modules/PurchasesModules.php` now.
  `Http\Middleware\CheckInvitationStatus` was the other — a core route
  reachable with no panel anywhere, calling `Notification::make()`; it is
  `class_exists()`-guarded with a session-flash fallback.

- **The module system stays in core, deliberately** (decision D-C). It threads
  ~30 files through 13 top-level `src/` directories, owns two Eloquent models
  whose migrations are core's, and its Filament UI already sits in
  `packages/filament` — the "temporarily, until a modules package exists" note
  there is **permanent and correct**, not debt. `internachi/modular` is
  `suggest`, behind one seam: `ModuleSystemFeature::available()` (feature
  enabled ∧ registry installed). One seam per optional package, not one
  `class_exists()` per call site.

- **Module Filament UI lives in two places on purpose.**
  `packages/filament/src/Admin/Resources/Central/Modules/` is the staff-facing
  catalogue; `packages/filament/src/TenantAdmin/{Resources,Pages}/Modules/` is
  the customer-facing marketplace. Any rule of the form "all module UI goes
  together" claims the same files twice.

- **85 migrations live in core** (64 central, 21 tenant), including 4 for
  modules, 9 for `activity_log` (4 central, 5 tenant — **not** symmetrical;
  the tenant side carries an `upgrade_activitylog` migration with no central
  counterpart) and 2 for `one_time_passwords`. They stay in core even where
  the code that reads them moved, because the tables have to exist wherever
  core does — `Models\User` composes the OTP trait, `Tenant\User` and
  `Invitation` compose `LogsActivity`, both through
  `Support\Compat\*IfInstalled` shims that no-op without the package.

- **Core's config is split by *key*, not by package** (2026-09-01):
  `config/numerosis/<key>.php`, fifteen partials that `config/numerosis.php`
  `array_merge`s. Splitting per package would be wrong, not merely
  unnecessary — a satellite fills its own keys at register time and a host's
  override wins over both, so there is no package line to cut along. Core's
  config deliberately does **not** name the onboarding wizard's step classes:
  that would put a package core does not depend on into core's own config.

  Two traps the split introduced. **The root file can never be published** —
  its `require __DIR__` paths would resolve against the *host's* config
  directory — which is why `NumerosisServiceProvider` no longer calls
  `hasConfigFile('numerosis')` (that registers the real file for publishing)
  and instead does `mergeConfigFrom()` in `packageRegistered()` plus a
  `publishGroup()` of `config/stubs/numerosis.php`. And **a new partial is
  invisible until it is listed in that root `array_merge`**, with nothing
  failing: the key just defaults away through `HostConfig`'s deep-fill.

## Suggested better approach

The seams are narrow on purpose — contribute-a-callback, not
override-the-mechanism — and that is worth keeping as more get added. The one
thing they lack is a way to *inspect* what has been contributed.

**Corrected 2026-09-01 — this section used to claim "there is no equivalent
for routes, migration paths or seeders". Three of those four already have
readers**, and a session acting on the old text would have rebuilt what
exists: `Numerosis::tenantMigrationPaths()`, `::tenantSeeders()`,
`::centralSeeders()` and `::permissionContexts()` all sit next to their
`add*()` writers (`src/Support/Numerosis.php:385,413,439,474`), as does
`Features::registered()`.

**Routes are the one real gap, and it is not just a missing getter.**
`self::$extraCentralRouteCallbacks` / `$extraTenantRouteCallbacks` hold bare
`Closure`s, so a reader over them answers "how many" and can never answer
"which package" — `resetRouteContributionsForTesting()` is their only public
consumer today. Closing it means changing the *writer*
(`addCentralRoutes(Closure $callback, ?string $source = null)`), not adding a
sibling method. Worth doing when a route contribution first needs attributing,
not speculatively; the other four readers show the shape.

The general rule stands: add the reader alongside the writer rather than
after — a boundary test that can enumerate contributions is strictly better
than one that scans files for forbidden strings.
