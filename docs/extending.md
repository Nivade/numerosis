# Extending Numerosis

Every seam is additive and lives on `Nvade\Numerosis\Support\{Numerosis,Features}`.
A **host** application calls these — as of the scope-reduction plan
(`.claude/plans/humming-nibbling-flame.md`), `nvade/numerosis` is one package
(tenancy, Fortify-backed auth, billing, onboarding, views); the only other
split, `nvade/numerosis-ui`, is a reusable Flux component library with no
features or routes of its own, so these seams have exactly one caller: your
app. Reach for an `add*()` seam first — the `registerXUsing()` methods at the
bottom replace a whole mechanism and exist for a host that genuinely wants
none of the defaults.

`tests/Feature/PackageBoundariesTest.php` enforces the one boundary left:
`nvade/numerosis-ui` may not reference core, `Filament\`, `tenancy()` or a
named `route()` — it has to stay installable on its own.

## The seams

| To contribute | Call | Notes |
|---|---|---|
| central-domain routes | `Numerosis::addCentralRoutes(Closure)` | The callback runs **once per configured central domain**, inside that domain's own `Route::middleware('web')->domain($domain)` group. A plain `Route::get()` instead would answer on every tenant subdomain, and nothing would fail |
| tenant routes | `Numerosis::addTenantRoutes(Closure)` | Same, inside the single `Route::middleware('tenant')` group |
| a feature | `Features::register(class-string<Feature>)` | Merges with `config('numerosis.features')`. `Features::registered()` tells a contributed feature from a host-configured one |
| tenant migrations | `Numerosis::addTenantMigrationPath(string)` | `tenancy.migration_parameters['--path']` is an array; this is the supported way in |
| seed data | `Numerosis::addTenantSeeder()` / `addCentralSeeder()` | Run by the package's own `TenantDatabaseSeeder` / `DatabaseSeeder` |
| permissions | `Numerosis::addPermissionContext(string)` | **A missing permission row is a 500, not a 403** — Spatie throws `PermissionDoesNotExist` rather than returning false, so any navigation that gates its own visibility on a check breaks every page carrying it, not just its own screen |
| views | `->hasViews('numerosis')` from your own provider | `FileViewFinder::addNamespace()` *appends*, so several packages can serve one namespace. Paths are searched in registration order, so a view must be **moved, never copied** |
| single-file Livewire pages | your own key in `livewire.component_namespaces` | Unlike views, a prefix maps to exactly **one** directory — two packages cannot join the same key. Set the key from `register()`, and set *one key*, never the whole array: replacing it drops every other package's |
| the `home` page | `numerosis.routes.home_view` | Core always registers the `home` route and declares it first, so a second route on `/` never matches. Point this at your own view instead |
| tenant model columns | `Numerosis::addTenantColumns(array)` | |
| a model | publish `--tag numerosis-models`, or set `numerosis.models.<FQCN>` | Convention (`App\Models\<suffix>`) is found automatically; the config key is for a non-conventional location |

### Swapping an implementation

`numerosis.{billing,tenancy}.implementations` is a `contract => concrete` map;
`BillingServiceProvider` and `TenancyServiceProvider` each loop theirs and
`bind()` every pair. Name your own class against the contract to replace one —
no provider edit, no subclassing.

That map is why all 32 interfaces in `src/Contracts/` stay, even though each
ships exactly one implementation (**decided 2026-09-01**; deleting the
"redundant" ones was an open question from `.claude/plans/confusion-cleanup.md`
step 4). They are not speculative abstraction:

- **22 are the swap points themselves**, named in one of those two maps.
  Deleting one removes a documented host capability and leaves nothing to
  `bind()` against.
- **10 are role interfaces, not service bindings**, and are load-bearing as
  types: `CentralUserModel`/`TenantUserModel` are what `HostConfig` and
  `UserModelResolver` `is_a()`-check a host's own model against,
  `Feature`/`NamedFeature` are the feature registry's contract, and
  `Subscribable`/`Plan`/`HasTenants`/
  `ProvidesTenantIdentity` are the shapes core's own services accept so a host
  subclass satisfies them without extending a package class.

"One implementation" is the expected state for a framework whose whole point is
that the *host* supplies the second one.

## Customizing auth — through Fortify

Auth is `laravel/fortify`'s, wired in `NumerosisServiceProvider::registerFortify()`.
Customize it the way Fortify's own docs describe — there is no
numerosis-specific auth API to learn on top of it:

| To change | Call |
|---|---|
| a screen | `Fortify::loginView(...)` / `registerView(...)` / … — each accepts a closure receiving `$request`, so a tenant-aware screen is one branch |
| where login lands | bind `LoginResponse` |
| how users are created | `Fortify::createUsersUsing(...)` |
| the login pipeline | `Fortify::authenticateThrough(...)` |
| which screens exist at all | `config('fortify.features')` |
| URLs | `config('fortify.paths.*')` |
| routes entirely | `Fortify::ignoreRoutes()` (core already calls this — expose it through `Numerosis::routes(withAuth: false)`) |

`numerosis.features` and `fortify.features` stay separate on purpose:
numerosis's gates tenancy/billing surfaces (invitations, the registration
wizard, OTP login), Fortify's gates auth screens (registration, password
reset, email verification). `registerFortify()` derives `fortify.features`
from `numerosis.features` for the two that overlap
(`PasswordResetFeature::NAME` → `FortifyFeatures::resetPasswords()`), so
toggle those through the numerosis key, not the Fortify one.

Numerosis's own auth actions (`CreateRegisteredUser`, `UpdateUserProfile`,
`UpdateUserPassword`, `ResetUserPassword`) are bound as plain `singleton`s in
`packageRegistered()`, not forced. A host's `AppServiceProvider` runs
afterwards and its own `Fortify::createUsersUsing(...)` (etc.) call
overwrites the binding — override is free, no opt-in seam to build.

**Validation lives inside these actions, not a Form Request** — a deliberate
deviation from this repo's usual convention. Every Fortify action contract is
typed `array $input`, called from Fortify's controller *and* from a Livewire
settings component (`Settings/{Profile,Password}`); only one of those has a
Form Request, so the action validates on entry via `Data::validateAndCreate()`
into the **default** error bag. Fortify's own stock actions use
`validateWithBag('updateProfileInformation')` instead — if you swap one of
numerosis's actions back for Fortify's, the Livewire views' `@error('name')`
needs to become `@error('name', 'updateProfileInformation')` to match, or
errors render nowhere.

### Replacing rather than adding

| Call | Replaces |
|---|---|
| `Numerosis::registerRoutesUsing(Closure)` | all of `Numerosis::routes()`, including both `add*Routes()` sets |
| `Numerosis::registerMiddlewareUsing(Closure)` | all alias and group registration |
| `Numerosis::registerBroadcastingUsing(Closure)` | channel registration |
| `Numerosis::routes(withAuth: false)` | narrower: still registers `routes/web.php` and `routes/tenant.php`, but skips loading Fortify's own route file into either group. Use this if you keep your own auth system — `login`, `register`, `logout` and `verification.verify` are otherwise Fortify's, registered behind no feature flag, so a host running its own auth gets a silent route-name collision resolved by provider order |

## Optional dependencies core still leans on

Core keeps `class_exists()`/`trait_exists()` seams for the packages it
`suggest`s rather than `require`s: `ryangjchandler/laravel-cloudflare-turnstile`
(`TurnstileFeature::isEnabled()`), `spatie/laravel-one-time-passwords`
(`OneTimePasswordFeature::available()`), `spatie/laravel-activitylog`
(`Support\Compat\LogsActivityIfInstalled`). **One seam per optional package,
not one per call site** — give the optional dependency exactly one predicate
and have every consumer ask it. One unguarded call site is a fatal, not a
disabled feature. `extends`, `implements` and `use <Trait>` resolve at
class-declaration time, so never name one of these symbols eagerly in a class
head; a method type hint or a guarded `new` is fine. See
`.ai/rules/optional-dependencies.md`.

## Where a package's own pieces live

Every migration, seeder and permission context lives in core: the tables and
rows have to exist wherever core does. `nvade/numerosis-ui` ships views and
Blade components only — no migrations, no routes, no features.
