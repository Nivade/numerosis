# Architecture

How the package boots, what happens on a request, and where things live. For
what a *host* must supply, see [`host-requirements.md`](host-requirements.md);
for what to switch off, [`features.md`](features.md); for how to add your own
code, [`extending.md`](extending.md).

## The five packages

One repository, five Composer packages. Core is the root; the four satellites
are path-installed from `packages/*` and published as read-only splits on tag.

```
nvade/numerosis  (src/)                core — tenancy, billing, auth mechanics,
                                       modules, all migrations and seeders
 ├── nvade/numerosis-ui                shared Blade + design tokens (required)
 ├── nvade/numerosis-filament          admin + tenant panels          (optional)
 ├── nvade/numerosis-auth-ui           auth screens + OAuth           (optional)
 └── nvade/numerosis-onboarding        registration wizard            (optional)
```

**Direction of dependency is one-way and enforced.** Satellites know core;
core never names a satellite's classes. Where core needs to reach into one, it
does so through a config key or a constant core itself owns — the tenant
panel's login component (`numerosis.panels.tenant.login`, a class name) and the
registration wizard (`numerosis.panels.admin.tenant_registration_component`, a
Livewire **alias**, deliberately not a class). `tests/Feature/PackageBoundariesTest.php`
is what keeps this true; in a monorepo the filesystem enforces nothing.

Core does reference `Filament\` in a handful of files. Every one is lazy — a
method type hint, or a `class_exists()`-guarded call — because PHP resolves
`extends`/`implements`/`use <Trait>` eagerly but type hints only at call time.
`Support\Compat\*` exists for the cases that needed an eager clause. See
`.claude/rules/optional-dependencies.md` before adding a reference.

## Boot sequence

`NumerosisServiceProvider` extends `spatie/laravel-package-tools`'
`PackageServiceProvider`, so the phases are its four, not Laravel's two. Phase
matters more here than in most packages — three separate production bugs came
from work done one phase too early or too late (`.claude/rules/package-host-bootstrap.md`).

| Phase | What runs | Why here |
|---|---|---|
| `configurePackage()` | name, config file, views, translations, migrations, commands | Runs **before** this package's own `mergeConfigFrom()`, so `config('numerosis.*')` is unreadable. The `tenants:*-module` commands are therefore gated on `class_exists()`, an install-time fact, not on the feature switch |
| `packageRegistered()` | model-cache reset; registers `TenancyServiceProvider` + `BillingServiceProvider`; container bindings for every `Contracts\*` interface; the `DatabaseSeeder` fallback binding; Livewire namespace + upload-disk defaults | Container wiring only. The Livewire namespaces must be set here because Livewire bakes them into view-finder hints during *its* `boot()` — a host overriding them must also do so from `register()` |
| `booting()` callbacks (queued from `packageRegistered()`) | `HostConfig::apply()`, then any host-named panel provider | Deferred on purpose: `booting()` fires after *every* provider has registered, so config that `stancl/tenancy` merges in is normalized after it lands rather than before. Doing this inline in `packageRegistered()` silently truncated `tenancy.database` |
| `packageBooted()` | bootstraps every enabled `Feature`; routes fallback; `request()->isCentralDomain()` macro; event listeners; schedule; middleware groups and aliases; Filament theme; broadcasting; exception handling; explicit Livewire component registration; publish groups | Everything needing config, facades and other providers to exist |

Two self-healing fallbacks live in `packageBooted()`, both for a host whose
`bootstrap/app.php` skipped a framework hook that no provider can substitute
for:

- `registerRoutesFallback()` registers routes from an `app->booted()` callback
  if `withRouting()` never did.
- `seedMiddlewareBaselineIfMissing()` seeds Laravel's own `web`/`api` groups if
  `withMiddleware()` was never called — without them the package's `tenant`
  group, which begins with `web`, resolves as a class name and throws
  `Target class [web] does not exist`.

`Numerosis::configure()` wires all three hooks in one line and is what a host
should use.

## Request lifecycle

Routes are registered by `Numerosis::routes()` into exactly two groups:

```
foreach (config('tenancy.central_domains') as $domain)   ← one group per hostname
    Route::middleware('web')->domain($domain)
        routes/web.php  +  addCentralRoutes() contributions

Route::middleware('tenant')
    routes/tenant.php   +  addTenantRoutes() contributions
```

The per-domain loop is not cosmetic: tenant identification never runs for a
central hostname, so a central route registered with a plain `Route::get()`
would also answer on every tenant subdomain, silently.

The `tenant` group is `['web', 'tenancy.identification', 'tenancy.route',
'tenancy.session']`:

| Middleware | Job |
|---|---|
| `tenancy.identification` | resolves the tenant, per `numerosis.tenancy.identification.mode` — `subdomain` (default), `custom_domain`, or `path`. The class differs per mode |
| `tenancy.route` | stancl's route-level tenancy guard |
| `tenancy.session` | `EnsureSessionMatchesTenant` — **must stay after `StartSession`**. One session spans every subdomain and `SessionGuard` stores only a primary key, so without it a user who is id 2 on tenant A authenticates as whoever id 2 is on tenant B |

`universal` is registered as an empty group (a marker for routes that answer on
both sides). `TrustProxies::at('*')` and a prepended `TrustHosts` are applied
unconditionally.

Identification mode is a **deploy-time** choice — switching it does not migrate
tenants already provisioned. See `.claude/rules/identification-modes.md`.

## Data layout

Two connections, two migration sets, never mixed:

| | Central | Tenant |
|---|---|---|
| Connection | `central` (cloned from `database.default` if absent) | the default connection, repointed per request by stancl's bootstrappers |
| Migrations | `database/migrations/central/` — 64 files, run by `php artisan migrate` | `database/migrations/tenant/` — 21 files, run per tenant at provision time |
| Models | `src/Models/Central/` — `Tenant`, `Domain`, `CentralUser`, `Subscription`, `PaymentPlan`, `PendingTenantProvision` | `src/Models/Tenant/` — `User`, `Invitation`, `Module` |

Every package model is concrete and usable as-is. `Numerosis::model()` resolves
each in three steps: an explicit `numerosis.models.<FQCN>` entry, then a
convention subclass at `App\Models\<suffix>`, then the package's own class.
Publish `--tag numerosis-models` to get the stubs.

Tenant provisioning is queued on the **`provisioning`** queue
(`Actions\Tenancy\ProvisionTenant`), under a per-domain lock — a host needs a
worker there, not just on `default`.

## Where code lives

```
src/
  Actions/        71 files — lorisleiva/laravel-actions; the verbs of the system
  Contracts/      32 — every swappable behaviour, bound in packageRegistered()
  Exceptions/     28
  Services/       21
  Models/         20 — Central/ and Tenant/
  Support/        19 — Numerosis, HostConfig, Features, Domains, + subsystems
  Http/           controllers (billing webhook) and middleware
  Features/       10 feature classes — see docs/features.md
  Policies/ Listeners/ Events/ Data/ Observers/ Concerns/ Livewire/
  Console/ Enums/ Notifications/ Testing/ Rules/ Providers/ Resolvers/
  Jobs/ Facades/ Commands/
```

Four `Support` classes carry most of the surface area:

| Class | Owns |
|---|---|
| `Support\Numerosis` | the host-facing static API: `configure()`, `routes()`, `middleware()`, `exceptions()`, the `add*()` contribution seams, model/factory name resolution, asset tags |
| `Support\HostConfig` | every config value normalized for a host at boot. One row per key in `host-requirements.md` |
| `Support\Features` | the feature registry — merges `config('numerosis.features')` with satellite `Features::register()` calls |
| `Support\Domains` | apex / central / tenant hostname derivation from `APP_URL`. **Nothing in it may call a facade** — it is invoked from `config/numerosis.php`, during `LoadConfiguration`, before `RegisterFacades` |

## Configuration

`config/numerosis.php` is one file, ~920 lines, 15 top-level keys. It is not
split per package on purpose: a satellite fills its own keys at register time,
and a host-published value wins over both.

| Key | What it controls |
|---|---|
| `schema_version` | bumped when the file's *shape* changes; `numerosis:install` fails a published copy that is behind |
| `features` | the feature class list — see [`features.md`](features.md) |
| `schedule` | booleans, not features: whether this deployment's cron runs each prune command |
| `routes.names` | indirection for the four route names called from outside their own route file, so a gated route can be renamed without breaking ~20 call sites |
| `domains` | `apex`, `central`, `tenant_pattern` — all derived from `APP_URL` |
| `broadcasting` | channel authorization wiring |
| `auth` | guard indirection (`auth.guards.central` defaults to `'web'`) |
| `social` | OAuth provider metadata and route names |
| `panels` | `default`, and per panel: `provider`, `login`, `tenant_registration_component` |
| `modules` | the module catalogue and Filament plugin map — host data |
| `views` | view path, set at boot |
| `cache` | the prefix for every key in `Support\Cache\CacheKeys`. Does **not** decide which keys are tenant-scoped — see `.claude/rules/tenant-caching.md` |
| `models` | explicit model overrides (step one of `Numerosis::model()`) |
| `billing` | Stripe, plans, payment-method ordering, checkout regions |
| `tenancy` | identification mode, provisioning, implementations |

The deep-fill in `HostConfig` backfills every missing key at every depth, so a
host's override file only names what it changes. Its one blind spot is a key
you still name in an *outdated shape* — that is what `schema_version` guards.

## Testing

Pest through Orchestra Testbench; there is no application here. `tests/Feature`,
`tests/Unit`, `tests/Browser` (Pest Browser + Playwright). Anything touching
tenancy composes `Nvade\Numerosis\Testing\CleansUpTenancyDatabases` — `RefreshDatabase`
transacts the default connection only, so central rows survive rollback and
`CREATE DATABASE` is not transactional. See `.claude/rules/testing.md`.
