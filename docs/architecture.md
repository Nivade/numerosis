# Architecture

How the package boots, what happens on a request, and where things live. For
what a *host* must supply, see [`host-requirements.md`](host-requirements.md);
for what to switch off, [`features.md`](features.md); for how to add your own
code, [`extending.md`](extending.md).

## The two packages

One repository, two Composer packages — collapsed from six by
`.claude/plans/archive/humming-nibbling-flame.md`. Core is the root; the second is
path-installed from `packages/ui` and published as a read-only split on tag.

```
nvade/numerosis     (src/)   core — tenancy, Fortify-backed auth, billing,
                              onboarding, views, all migrations and seeders
 └── nvade/numerosis-ui      shared Blade + design tokens (required)
```

`nvade/numerosis-auth-ui`, `nvade/numerosis-onboarding` and
`nvade/numerosis-account` folded into core (Phase 3); `nvade/numerosis-filament`
(admin + tenant panels) was deleted outright (Phase 1), along with
`filament/filament` — nothing in this repo names a `Filament\` symbol. The
tenant domain's `/` is a core route in `routes/tenant.php`; it was the
panel's before. Auth itself moved onto `laravel/fortify` (Phase 4): core
supplies the tenancy wiring, the views and the actions Fortify's contracts
call; Fortify owns route registration, session handling and password
hashing. See [`extending.md`](extending.md) for the customization seams this
buys.

Core is a framework, not a product: it ships no marketing site. The pages a
*specific* SaaS wants live in the host app. Core keeps `home` alone, and only
as a placeholder view behind `numerosis.routes.home_view`.

**Direction of dependency is one-way and enforced.** `nvade/numerosis-ui`
knows nothing of core, tenancy, or a named route — it is a Blade + design-token
library, installable on its own. `tests/Feature/PackageBoundariesTest.php`
is what keeps this true; in a monorepo the filesystem enforces nothing.

`Support\Compat\*` holds the conditional-definition shims for what is still
optional (`spatie/laravel-one-time-passwords`, `spatie/laravel-activitylog`):
PHP resolves `extends`/`implements`/`use <Trait>` eagerly but type hints only
at call time, so an eager clause on an optional package's symbol needs a shim
that always exists. See `.ai/rules/optional-dependencies.md` before adding a
reference.

## Boot sequence

`NumerosisServiceProvider` extends `spatie/laravel-package-tools`'
`PackageServiceProvider`, so the phases are its four, not Laravel's two. Phase
matters more here than in most packages — three separate production bugs came
from work done one phase too early or too late (`.ai/rules/package-host-bootstrap.md`).

| Phase | What runs | Why here |
|---|---|---|
| `configurePackage()` | name, config file, views, translations, migrations, commands | Runs **before** this package's own `mergeConfigFrom()`, so `config('numerosis.*')` is unreadable. A command registered conditionally here must therefore gate on `class_exists()`, an install-time fact, never on a feature switch |
| `packageRegistered()` | model-cache reset; registers `TenancyServiceProvider` + `BillingServiceProvider`; container bindings for every `Contracts\*` interface; the `DatabaseSeeder` fallback binding; Livewire namespace + upload-disk defaults | Container wiring only. The Livewire namespaces must be set here because Livewire bakes them into view-finder hints during *its* `boot()` — a host overriding them must also do so from `register()` |
| `booting()` callbacks (queued from `packageRegistered()`) | `HostConfig::apply()` | Deferred on purpose: `booting()` fires after *every* provider has registered, so config that `stancl/tenancy` merges in is normalized after it lands rather than before. Doing this inline in `packageRegistered()` silently truncated `tenancy.database` |
| `packageBooted()` | bootstraps every enabled `Feature`; routes fallback; `request()->isCentralDomain()` macro; event listeners; schedule; middleware groups and aliases; broadcasting; exception handling; explicit Livewire component registration; publish groups | Everything needing config, facades and other providers to exist |

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
tenants already provisioned. See `.ai/rules/identification-modes.md`.

## Data layout

Two connections, two migration sets, never mixed:

| | Central | Tenant |
|---|---|---|
| Connection | `central` (cloned from `database.default` if absent) | the default connection, repointed per request by stancl's bootstrappers |
| Migrations | `database/migrations/central/` — 63 files, run by `php artisan migrate` | `database/migrations/tenant/` — 17 files, run per tenant at provision time |
| Models | `src/Models/Central/` — `Tenant`, `Domain`, `CentralUser`, `Subscription`, `PaymentPlan`, `PendingTenantProvision` | `src/Models/Tenant/` — `User`, `Invitation` |

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
  Actions/        62 files — lorisleiva/laravel-actions; the verbs of the system
  Contracts/      26 — every swappable behaviour, bound in packageRegistered()
  Exceptions/     22
  Services/       24 — default implementations, grouped by domain:
                  Auth/ Billing/ Invitations/ Notifications/ Tenancy/
  Models/         16 — Central/ and Tenant/
  Support/        7 top-level classes — Numerosis, ModelResolver, Contributions,
                  Assets, HostConfig, Features, Domains, + Billing/ Cache/
                  Compat/ Routes/ Social/ Tenancy/
  Http/           19 — controllers (auth, Socialite, billing webhook) and
                  middleware
  Features/        9 feature classes, grouped by domain (Auth/ Billing/
                  Invitations/ Tenancy/ Turnstile/) — see
                  docs/features.md
  Enums/          every enum under a domain namespace: Billing/ Tenancy/
                  Tenant/
  Policies/ Listeners/ Events/ Data/ Observers/ Concerns/ Livewire/
  Console/ Notifications/ Testing/ Rules/ Providers/ Resolvers/
  Jobs/ Facades/ Commands/
```

Two naming decisions worth knowing before grepping: `Feature` in PHP means a
capability toggle and nothing else — a plan's selling point is
`Models\Central\PlanFeature` (its table is still `features`, deliberately) —
and there is no `Support\Defaults`; every default implementation lives in
`Services\`.

Seven `Support` classes carry most of the surface area. The first four were
one class until 2026-09-01, split by audience — **every moved method still
exists on `Numerosis` and delegates**, because `Numerosis::` is the idiom
`docs/extending.md`, every host's `config/numerosis.php` and ~200 call sites
already use. Read the delegate for the seam, the owner for the mechanism.

| Class | Owns |
|---|---|
| `Support\Numerosis` | application bootstrap and the front door to everything below: `configure()`, `routes()`, `middleware()`, `broadcasting()`, `exceptions()`, and the three `registerXUsing()` wholesale overrides |
| `Support\ModelResolver` | model resolution, the model↔factory name mapping and its memoization cache. Behind `Numerosis::{model,factoryNameFor,modelNameFor,resetModelCache}()` |
| `Support\Contributions` | what a host has added — tenant columns, central/tenant routes, tenant migration paths, seeders, permission contexts — plus the readers `routes()` and the seeders consume. Behind every `Numerosis::add*()`. Note `Contributions::tenantMigrationPaths()` is contributions only, while `Numerosis::tenantMigrationPaths()` includes the package's own; `HostConfig` wants the latter |
| `Support\Assets` | the `numerosis-assets` publish map, the published `public/vendor/numerosis` paths, and the `<link>`/`<script>` tags for the package's CSS/JS. Behind `Numerosis::{assetSourcePaths,assetTags}()`. The only one of these that reaches for `Vite` and the filesystem |
| `Support\HostConfig` | every config value normalized for a host at boot. One row per key in `host-requirements.md` |
| `Support\Features` | the feature registry — merges `config('numerosis.features')` with any `Features::register()` call a host makes from its own provider |
| `Support\Domains` | apex / central / tenant hostname derivation from `APP_URL`. **Nothing in it may call a facade** — it is invoked from `config/numerosis.php`, during `LoadConfiguration`, before `RegisterFacades` |

## Configuration

One config namespace, 15 top-level keys, one file per key in
`config/numerosis/`. `config/numerosis.php` only `array_merge`s the thirteen
partials, each of which returns its own `['key' => value]` pair and carries
that key's documentation.

Split by *key*, not by package, on purpose: a host's own override file only
needs to name the keys it changes, so there is nothing to divide along
package lines.

Three consequences:

- **The package's own config file is never published**, because its
  `require __DIR__` paths would resolve against a host's config directory.
  `vendor:publish --tag=numerosis-config` writes `config/stubs/numerosis.php`
  — a short override file — and `NumerosisServiceProvider::packageRegistered()`
  does the `mergeConfigFrom()` by hand rather than through
  `hasConfigFile('numerosis')`, which would have registered the real file for
  publishing.
- A host that published the old full-file copy keeps working: a complete file
  needs no backfill, and `schema_version` still guards its shape.
- `env()` in a partial is analysed as config, not application code —
  `configDirectories` in `phpstan.neon.dist` names `config/numerosis` as well
  as `config`.

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
| `views` | view path, set at boot |
| `cache` | the prefix for every key in `Support\Cache\CacheKeys`. Does **not** decide which keys are tenant-scoped — see `.ai/rules/tenant-caching.md` |
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
`CREATE DATABASE` is not transactional. See `.ai/rules/testing.md`.
