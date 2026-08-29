---
topic: package-boundaries
updated: 2026-08-29
---

# Package Boundaries

Facts about what this package can and cannot be cut apart along, found while
auditing the split plan (`.claude/plans/memoized-tinkering-meadow.md`). Every
one is a property of the code as it stands today, not of the plan — they hold
whether or not the split ever happens, and each one silently invalidates an
"obvious" boundary.

- **`Numerosis::routes()` has no append hook, and its own docblock says so:
  "there is no hook to append to the defaults."** (`src/Support/Numerosis.php:146`.)
  `registerRoutesUsing()` replaces the whole thing; there is no
  `addCentralRoutes()`/`addTenantRoutes()`. That matters because the central
  route group is not something a second package can reconstruct from outside:
  `routes()` loops `Config::array('tenancy.central_domains')` and wraps
  `routes/web.php` in `Route::middleware('web')->domain($domain)` **once per
  central domain**, then registers `routes/tenant.php` under the `tenant`
  group. Any package wanting to add a central-domain route (the registration
  wizard's `/get-started`, everything in `routes/auth.php`) has to reproduce
  that loop and stay in sync with it, or register outside the domain scoping
  and answer on tenant subdomains too. `.claude/rules/host-integration-quickstart.md`
  already records what this cost tabellio — it hand-rolled a `routes/central.php`
  and duplicated the wiring — and `Numerosis::routes(withAuth: false)` was the
  narrow fix for that one case. **A route-contribution seam has to exist before
  any route-owning code moves to a second package**; it is not a Phase-3
  mechanical detail.

- **Core → Filament edges exist and one of them is a cycle waiting to
  happen.** `src/Concerns/Modules/PurchasesModules.php` (core, `Concerns/`)
  does `use Nvade\Numerosis\Filament\Concerns\NotifiesUser;` and returns
  `Filament\Actions\Action` objects — and its only two consumers are
  `Filament\TenantAdmin\Pages\Modules\{Marketplace,ModuleDetail}`. It reads
  like core because of where it lives; it is Filament UI glue. Move Filament
  out and leave this behind and core depends on the package that depends on
  core. Six more core files name `Filament\*` without a `class_exists()`
  guard — `Http/Middleware/{Authenticate,CheckInvitationStatus,ApplyDefaultBranding}`,
  `Models/{User,Central/CentralUser}`, `Support/Numerosis`,
  `Testing/InteractsWithTenantPanel`, `Contracts/Tenancy/ModulePlugin` — all
  currently safe only because a bare `use` statement is lazy and the call
  sites are reached only when Filament is installed. That is the same
  eager-vs-lazy asymmetry `.claude/rules/optional-dependencies.md` documents;
  it holds for `use` imports and method type-hints, and does **not** hold the
  moment one of them becomes an `extends`/`implements`/`use <Trait>`.

- **`Actions\Modules\PurchaseModule` hard-uses `InterNACHI\Modular\Support\Facades\Modules`**
  (`src/Actions/Modules/PurchaseModule.php:9`). Buying a module is a billing
  operation on core's own tables *and* a module-registry lookup, so
  "billing stays, module system moves" does not cut cleanly here — whichever
  package holds `PurchaseModule` requires `internachi/modular`. Same for
  `Actions/Modules/{MigrateModules,RollbackModules,SynchronizeModules}` and
  the three `Console/Commands/*TenantModule` commands.

- **The central admin panel's module UI is a third location, distinct from
  both the tenant marketplace and core.** `src/Filament/Admin/Resources/Central/Modules/`
  (6 files — `ModuleOfferingResource`, 3 pages, `ModuleForm`, `ModulesTable`)
  is the staff-facing module *catalogue*, while
  `src/Filament/TenantAdmin/{Resources/Modules,Pages/Modules}` is the
  customer-facing marketplace. Any rule of the form "all module-related
  Filament UI goes together" and "all `Admin/` resources go together" claims
  these six files twice. Related: `ModuleOfferingPolicy`'s `modules`
  permission context is seeded by `RoleAndPermissionSeeder` under guard `web`
  — see `.claude/rules/auth-guards.md` for why a missing permission there
  500s *every* page in the panel, not just its own.

- **`Filament\NumerosisTenantPlugin` names components from two other feature
  areas.** `->login(PasswordlessLogin::class)` (`:110`) makes the tenant
  panel's login page an auth-UI component, and `Filament\Admin\Pages\RegisterTenant`
  exists solely to host the registration wizard. So "Filament" is not a leaf:
  it depends on auth UI and on onboarding, not only on core.

- **`config/numerosis.php` is one 847-line file that names classes from every
  feature area at once** — `Features\*` entries, `implementations` bindings,
  panel providers, model overrides. `Support\Features::all()` reads that array
  and `NumerosisServiceProvider` does `$this->app->make($feature)` on each
  entry, so **a feature class listed in config but not installed is a hard
  container failure at boot**, not a skipped feature. `is_a($class, NamedFeature::class, true)`
  in `Features::names()` autoloads and quietly returns `false` for a missing
  class, so the name map silently loses the entry first and the crash arrives
  from the `make()` loop instead — two different symptoms, one cause. Any
  packaging change that could leave a feature class unavailable needs the
  config split with it, in the same change.

- **Satellite-owned tenant migrations are possible but have no public seam.**
  `HostConfig::tenancyMigrationParameters()` (`src/Support/HostConfig.php:154-177`)
  treats `tenancy.migration_parameters['--path']` as an array and *appends*
  `Numerosis::tenantMigrationPath()` if absent, so multiple paths already
  work — but `tenantMigrationPath()` is singular and there is no
  `addTenantMigrationPath()`. Same shape for tenant seed data: everything
  funnels through the single `Database\Seeders\TenantDatabaseSeeder`.
  84 migrations currently sit here (63 central, 21 tenant), including 4 for
  modules, 9 for `activity_log` (4 central, 5 tenant — not symmetrical; the
  tenant side carries an `upgrade_activitylog` migration with no central
  counterpart), and 2 for `one_time_passwords` — none of
  which belong to the tenancy/billing core by the same reasoning that moves
  their code.

- **The `Features` list is plain config with no registration API.** A second
  package cannot add its own `NamedFeature` without the host editing
  `config('numerosis.features')` by hand. If features are meant to be the
  plug-in seam, `Features::register()` has to exist first.

## Suggested better approach

Every bullet above is the same shape: **this package has exactly one
extension point per concern (one routes callback, one feature array, one
migration path, one seeder, one config file), and each is "replace it
wholesale" rather than "contribute to it".** That is the right design for a
single package with one host, and it is precisely what stops a second package
from participating. Before moving any code, add the three contribution seams
— routes, features, migration paths — as additive, independently testable
changes against the current single-package layout. They are useful on their
own (a host gains the same seams), they are verifiable by this repo's
existing suite, and they convert the split from "rewrite the extension model
while also moving 400 files" into "move files through a model that already
works". The trade-off is that each seam is new public API that has to be
supported afterwards, so keep them narrow: contribute-a-callback, not
override-the-mechanism.
