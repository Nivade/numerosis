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

`spatie/laravel-one-time-passwords`, `spatie/laravel-activitylog` and
`ryangjchandler/laravel-cloudflare-turnstile` are all `require`, not `suggest`
— every one is always present, and only `numerosis.features` (for OTP and
Turnstile) or `Tenant\User`'s trait use (for activity logging) decides whether
it does anything. Nothing in core is a `suggest` any more, so no
`class_exists()` seam is load-bearing today. PHP resolves
`extends`/`implements`/`use <Trait>` eagerly but type hints only at call time —
that asymmetry is why an eager clause on a package that is *not* a `require`
needs a `Support\Compat\*` shim. See `.ai/rules/optional-dependencies.md`
before adding a `suggest` back.

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
| `packageBooted()` | bootstraps every enabled `Feature`; routes fallback; `request()->isCentralDomain()` macro; event listeners; schedule; middleware groups and aliases; exception handling; explicit Livewire component registration; publish groups | Everything needing config, facades and other providers to exist |

Two self-healing fallbacks live in `packageBooted()`, both for a host whose
`bootstrap/app.php` skipped a framework hook that no provider can substitute
for:

- `registerRoutesFallback()` registers routes from an `app->booted()` callback
  if `withRouting()` never did.
- `seedMiddlewareBaselineIfMissing()` seeds Laravel's own `web`/`api` groups if
  `withMiddleware()` was never called — without them the package's `tenant`
  group, which begins with `web`, resolves as a class name and throws
  `Target class [web] does not exist`.

Adopting numerosis changes one line of an existing `bootstrap/app.php`: `web:`
becomes `using: Numerosis::routes(...)`, because per-central-domain route
groups are the one thing `web:` cannot express. `Numerosis::middleware()` and
`Numerosis::exceptions()` go inside the host's own closures, first, so
whatever the host configures afterwards wins. `Numerosis::configure()` is the
same three calls in one line, for a greenfield app; the expanded chain is what
reaches `health:` and `then:`.

## Request lifecycle

Routes are registered by `Numerosis::routes()` into exactly two groups:

```
foreach (config('tenancy.central_domains') as $domain)   ← one group per hostname
    Route::middleware('web')->domain($domain)
        routes/web.php  then  base_path('routes/web.php')

Route::middleware('tenant')
    routes/tenant.php   then  base_path('routes/tenant.php')

Route::middleware('api')->prefix($apiPrefix)             ← outside both groups
    base_path('routes/api.php')
```

The host's file loads last in each group deliberately: `RouteCollection` keys
on method + domain + URI, so a host route on `/` replaces the package's only
by being registered after it.

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
| Migrations | `database/migrations/central/` — 24 files, run by `php artisan migrate` | `database/migrations/tenant/` — 13 files, run per tenant at provision time |
| Models | `src/Models/Central/` — `Tenant`, `Domain`, `CentralUser`, `Subscription`, `PaymentPlan`, `TenantProvision`, `Invitation`, `SocialAccount` | `src/Models/Tenant/` — `User` only |

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
  Actions/        68 files — lorisleiva/laravel-actions; the verbs of the system
  Contracts/      27 — every swappable behaviour, bound in packageRegistered()
  Exceptions/     20
  Services/       19 — the concrete implementation of a contract, in a
                  folder mirroring Contracts/: Billing/ Exceptions/
                  Notifications/ Tenancy/
  Models/         16 — Central/ and Tenant/
  Boot/           8 — what the package reads from, writes to, or validates
                  in the host application. Numerosis.php's backstage
  Routing/        RouteNames and RouteLoader
  Cache/          CacheKeys and GlobalCache
  Http/           29 — controllers (auth, Socialite, billing webhook),
                  middleware and responses
  Features/       10 feature classes, grouped by domain (Auth/ Billing/
                  Invitations/ Tenancy/ Turnstile/) — see
                  docs/features.md
  Enums/          every enum under a domain namespace: Billing/ Tenancy/
                  Tenant/
  Policies/ Listeners/ Events/ Data/ Observers/ Concerns/ Livewire/
  Console/ Notifications/ Testing/ Rules/ Providers/
  Jobs/ Facades/
```

Two naming decisions worth knowing before grepping: `Feature` in PHP means a
capability toggle and nothing else — a plan's selling point is
`Models\Central\PlanFeature` (its table is still `features`, deliberately) —
and there is no `Support\Defaults` — nor a `Support\` at all, since
2026-09-11. Every default implementation lives in `Services\`.

`src/Numerosis.php` is the only class a host imports, and every one of its
methods delegates. The owners are below: **every method still exists on
`Numerosis`**, because `Numerosis::` is the idiom `docs/extending.md`, every
host's `config/numerosis.php` and ~200 call sites already use. Read
`Numerosis` for the seam, the owner for the mechanism.

| Class | Owns |
|---|---|
| `Numerosis` | application bootstrap and the front door to everything below: `configure()`, `routes()`, `middleware()`, `exceptions()`, and the three `registerXUsing()` wholesale overrides. `Facades\Numerosis` is the post-boot facade over it, never usable from `bootstrap/app.php` |
| `Boot\ModelResolver` | model resolution, the model↔factory name mapping and its memoization cache. Behind `Numerosis::{model,factoryNameFor,modelNameFor,resetModelCache}()` |
| `Boot\Assets` | the `numerosis-assets` publish map, the published `public/vendor/numerosis` paths, and the `<link>`/`<script>` tags for the package's CSS/JS. Behind `Numerosis::{assetSourcePaths,assetTags}()`. The only one of these that reaches for `Vite` and the filesystem |
| `Boot\HostConfig` | every config value normalized for a host at boot. One row per key in `host-requirements.md` |
| `Features\FeatureRegistry` | the feature registry — merges `config('numerosis.features')` with any `FeatureRegistry::register()` call a host makes from its own provider |
| `Boot\Domains` | apex / central / tenant hostname derivation from `APP_URL`. **Nothing in it may call a facade** — it is invoked from `config/numerosis.php`, during `LoadConfiguration`, before `RegisterFacades` |
| `Routing\RouteLoader` | the central-domain and `tenant` route groups, Fortify's route file and the one-time-password routes. Behind `Numerosis::{routes,routesRegistered,authRoutesEnabled}()` |
| `Boot\MiddlewareRegistrar` | the one definition of the package's aliases and groups, read by both `Numerosis::middleware()` and the service provider. **Pure class-string literals** — it runs before `RegisterFacades` |
| `Boot\ExceptionRegistrar` | report context, duplicate suppression and throttling. Behind `Numerosis::exceptions()` |
| `Boot\UserModels` | which user model answers for the current guard, from the two `tenancy.*_user_model` keys `HostConfig` writes |
| `Boot\ConfiguredSteps` | boot-time validation of the two host-editable step lists in `numerosis.tenancy` |

## Configuration

One config namespace, one publishable file: `config/numerosis.php`, ten
top-level keys.

Split by *key*, not by package, on purpose: a host's own override file only
needs to name the keys it changes, so there is nothing to divide along
package lines.

`NumerosisServiceProvider::packageRegistered()` deep-merges the package's
defaults under whatever a host already published, at every depth — Laravel's
own config merge is one level deep only, so a host file naming `billing` at
all would otherwise shadow every sibling key the package later adds under it.
`fillMissingKeys()` does the merge; only keyed arrays are filled, so a list
such as `features` is left exactly as the host set it, including empty.

| Key | What it controls |
|---|---|
| `features` | the feature class list — see [`features.md`](features.md) |
| `schedule` | booleans, not features: whether this deployment's cron runs each prune command |
| `routes.names` | indirection for the four route names called from outside their own route file, so a gated route can be renamed without breaking ~20 call sites |
| `domains` | `apex`, `central`, `tenant_pattern` — all derived from `APP_URL` |
| `auth` | guard indirection (`auth.guards.central` defaults to `'web'`) |
| `social` | OAuth provider metadata and route names |
| `cache` | the prefix for every key in `Cache\CacheKeys`. Does **not** decide which keys are tenant-scoped — see `.ai/rules/tenant-caching.md` |
| `models` | explicit model overrides (step one of `Numerosis::model()`) |
| `billing` | Stripe, payment-method ordering, checkout regions |
| `tenancy` | identification mode, provisioning, implementations |

The one blind spot the deep-fill cannot see: a key you still name in an
*outdated shape*. It only ever backfills a key that is entirely missing;
compare your file against the package's own `config/numerosis.php` after an
upgrade.

## Testing

Pest through Orchestra Testbench; there is no application here. `tests/Feature`,
`tests/Unit`, `tests/Browser` (Pest Browser + Playwright). Anything touching
tenancy composes `Nvade\Numerosis\Testing\CleansUpTenancyDatabases` — `RefreshDatabase`
transacts the default connection only, so central rows survive rollback and
`CREATE DATABASE` is not transactional. See `.ai/rules/testing.md`.
