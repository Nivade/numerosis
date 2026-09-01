# Extending Numerosis

Every seam is additive and lives on `Nvade\Numerosis\Support\{Numerosis,Features}`.
A host or a satellite package calls these; neither ever names the other's
classes. Reach for an `add*()` seam first — the `registerXUsing()` methods at
the bottom replace a whole mechanism and exist for a host that genuinely wants
none of the defaults.

`tests/Feature/PackageBoundariesTest.php` enforces the boundaries; in a
monorepo the filesystem enforces nothing.

## The seams

| To contribute | Call | Notes |
|---|---|---|
| central-domain routes | `Numerosis::addCentralRoutes(Closure)` | The callback runs **once per configured central domain**, inside that domain's own `Route::middleware('web')->domain($domain)` group. A plain `Route::get()` instead would answer on every tenant subdomain, and nothing would fail |
| tenant routes | `Numerosis::addTenantRoutes(Closure)` | Same, inside the single `Route::middleware('tenant')` group |
| a feature | `Features::register(class-string<Feature>)` | Merges with `config('numerosis.features')`. `Features::registered()` tells a contributed feature from a host-configured one |
| tenant migrations | `Numerosis::addTenantMigrationPath(string)` | `tenancy.migration_parameters['--path']` is an array; this is the supported way in |
| seed data | `Numerosis::addTenantSeeder()` / `addCentralSeeder()` | Run by the package's own `TenantDatabaseSeeder` / `DatabaseSeeder` |
| permissions | `Numerosis::addPermissionContext(string)` | **A missing permission row is a 500, not a 403**, and Filament evaluates every resource's `viewAny` on every page render — one missing context breaks the whole panel |
| views | `->hasViews('numerosis')` from your own provider | `FileViewFinder::addNamespace()` *appends*, so several packages can serve one namespace. Paths are searched in registration order, so a view must be **moved, never copied** |
| tenant model columns | `Numerosis::addTenantColumns(array)` | |
| the tenant panel's login page | `numerosis.panels.tenant.login` | A Livewire component **class**. `null` means Filament's own login page |
| the registration wizard | `numerosis.panels.admin.tenant_registration_component` | A Livewire **alias**, not a class — that is what keeps core and `packages/filament` from naming `packages/onboarding`'s classes |
| a panel wholesale | `numerosis.panels.{admin,tenant}.provider` | Core registers what you name and `numerosis-filament` stands down for that panel |
| a model | publish `--tag numerosis-models`, or set `numerosis.models.<FQCN>` | Convention (`App\Models\<suffix>`) is found automatically; the config key is for a non-conventional location |

### Replacing rather than adding

| Call | Replaces |
|---|---|
| `Numerosis::registerRoutesUsing(Closure)` | all of `Numerosis::routes()`, including both `add*Routes()` sets |
| `Numerosis::registerMiddlewareUsing(Closure)` | all alias and group registration |
| `Numerosis::registerBroadcastingUsing(Closure)` | channel registration |
| `Numerosis::routes(withAuth: false)` | narrower: keeps everything in `routes/web.php` except `routes/auth.php`. Use this if you keep your own auth system — `login`, `register`, `logout` and `verification.verify` are otherwise registered behind no feature flag, so a Fortify/Breeze host gets a silent route-name collision resolved by provider order |

## Writing a satellite package

Four things that are easy to get wrong, each learned the hard way:

- **Register into a world where core's config is absent, and do nothing.**
  Larastan boots an application that discovers every *vendor* package but not
  the root one, so every satellite registers with core absent — the same shape
  as a host that installs a satellite without registering core.
  `Features::all()` reads `Config::array('numerosis.features', [])` for exactly
  this reason. Sentinel on a key **only core writes**: `Arr::set()`
  auto-vivifies, so "the namespace exists" is not evidence core registered.

- **Only write config in the register phase where the parent namespace is
  deep-filled.** `numerosis.panels` survives a `packageRegistered()` write
  because `HostConfig::numerosisConfig()` deep-fills it. `numerosis.tenancy`
  does not, and the identical write destroyed `implementations`,
  `provisioning` and `identification`. Mechanism and both symptoms in
  `.claude/rules/package-split.md`.

- **Never name a core-optional symbol eagerly.** `extends`, `implements` and
  `use <Trait>` resolve at class-declaration time; a method type hint does not.
  This is the whole reason `Support\Compat\*` exists, and why those four files
  stay in **core** — a satellite owning them would invert the dependency they
  exist to prevent. See `.claude/rules/optional-dependencies.md`.

- **One `class_exists()` seam per optional package, not one per call site.**
  `internachi/modular` has seven consumers; all of them ask
  `ModuleSystemFeature::available()`. One unguarded call site is a fatal, not a
  disabled feature.

## Where a package's own pieces already live

`packages/filament` splits module UI in two on purpose:
`Admin/Resources/Central/Modules/` is the staff-facing catalogue,
`TenantAdmin/{Resources,Pages}/Modules/` the customer-facing marketplace. Any
rule of the form "all module UI goes together" claims the same files twice.

The module system itself stays in core deliberately — it threads ~30 files
through 13 top-level `src/` directories and owns two Eloquent models whose
migrations are core's. All 85 migrations stay in core for the same reason: the
tables have to exist wherever core does.

## Known gap

There are writers for every seam but readers for only one. `Features::registered()`
exists; nothing equivalent exists for routes, migration paths or seeders, so
"which package added this route" is answerable only by grep. If a sixth package
lands, add the readers alongside the writers.
