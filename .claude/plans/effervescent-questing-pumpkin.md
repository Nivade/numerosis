# Numerosis as a layer, not a takeover

## Context

Started as "is `src/Support/Contributions.php` dead code?" It is not — thin-app
calls `Numerosis::addCentralRoutes()` today — but investigating *why* a host
would need it showed it exists to work around numerosis taking over
`bootstrap/app.php`.

The goal this plan serves: **numerosis adds tenancy, auth, billing,
subscriptions and onboarding to a host's existing application.** It supplies
defaults for each, every one freely swappable. It does not own the host's
routing, middleware, exception handling, view namespaces or seeders. Today it
takes over all five, mostly silently.

Adopting numerosis into an app that already works should not delete anything
that already works. Right now it deletes several.

Breaking changes are acceptable — no installs beyond thin-app.

### What "takeover" concretely means today

| Symptom | Cause |
|---|---|
| Host's `routes/web.php` never loads | `Numerosis::routes()` requires only the *package's* routes dir (`src/Support/Numerosis.php:157`); `base_path('routes/web.php')` is never read. thin-app's `Route::get('/')` has never resolved, and its marketing routes had to move into `addCentralRoutes()` inside a service provider |
| Host's `routes/api.php` and health endpoint silently vanish | `withRouting(using: ...)` skips `api`/`health`/`pages` entirely — `buildRoutingCallback()` only runs when `using` is null (`ApplicationBuilder.php:165`) |
| Host's trusted-proxy/host config is reverted | `registerMiddleware()` re-applies `TrustProxies::at('*')` and `prependMiddleware(TrustHosts::class)` at `packageBooted()`, after the host's `withMiddleware()` closure ran |
| Host's middleware swap is reverted | Same method re-runs `Route::aliasMiddleware()` over every alias, after the host's closure |
| Host's own Livewire `layouts`/`pages` namespaces are repointed at numerosis | `registerLivewireComponentNamespaces()` overwrites the key when it equals `resource_path("views/…")` — i.e. exactly when the host is using its own |
| Adding a route/seeder/permission/column requires a static registry | `Support\Contributions`, the workaround for all of the above |

Everything below either removes a takeover or replaces a registry with the
container.

---

## Before you start

**Rules first.** Read `.ai/rules/index.md`, then every rule file whose globs
cover what you are about to touch — this plan spans a lot of them:

| Working on | Read |
|---|---|
| §1–§3 routes, `configure()` | `package-host-bootstrap.md`, `middleware-registration.md`, `identification-modes.md` |
| §4 middleware, trust config | `middleware-registration.md`, `package-host-bootstrap.md` |
| §5 Livewire namespaces | `views.md`, `tenant-registration-wizard.md` |
| §6–§7 bindings, exception context | `architecture-conventions.md`, `exception-handling.md`, `package-boundaries.md` |
| §8 Facade | `package-host-bootstrap.md` (the facade-root incidents) |
| §9 migrations, seeders, columns | `package-boundaries.md`, `tenant-provisioning.md`, `central-rows-on-tenant-routes.md` |
| Any PHP file | `general.md` (comment style — default to zero comments; hard caps) |
| Tests | `testing.md` |
| PHPStan | `static-analysis.md` |

Also run `grep -rin '<keyword>' .ai/rules` for what a path match alone misses.

**Skills to invoke**, at the point you start that part rather than once you
are stuck:

| When | Skill |
|---|---|
| Starting any part of this plan | `laravel-packages` — package development and extraction seams |
| §4, before touching `TrustProxies`/`TrustHosts` | `laravel-security` — these are security boundaries, not ergonomics; changing when they apply needs a security lens |
| §5, the `layouts::`/`pages::` rename | `livewire-development` — component namespaces, and how Livewire resolves them |
| §6, designing the binding seams | `solid-php` and/or `laravel-patterns` — interface/implementation split, service-layer conventions |
| Writing or updating any test | `pest-testing` — this plan adds four new test groups |
| Recording what you learn at the end | `codebase-learnings` — the `.ai/rules` updates in the Files list |
| Before finishing any PHP change | `/pint` (or `vendor/bin/pint --dirty --format agent`) |

**Do not** invoke `code-review` or `research`: both spawn sub-agents, which
`CLAUDE.md` and `.ai/rules/subagents.md` forbid outright in this repo. Review
inline in the single session.

---

## Tier 0 — adopting numerosis changes one line, and deletes nothing

An existing app's `bootstrap/app.php` today:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) { /* theirs */ })
    ->withExceptions(function (Exceptions $exceptions) { /* theirs */ })
    ->create();
```

After adopting numerosis, the only required change is `web:` becoming
`using:`, because numerosis needs per-central-domain route groups and that is
the one thing a host cannot express through `web:`:

```php
    ->withRouting(
        using: Numerosis::routes(...),
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        Numerosis::middleware($middleware);
        /* theirs, unchanged — and it wins */
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Numerosis::exceptions($exceptions);
        /* theirs, unchanged */
    })
```

For that to delete nothing, `Numerosis::routes()` must load the host's own
route files (§1) and `api:`/`health:` must not disappear (§2).

`Numerosis::configure()` stays, but is documented as the **greenfield
convenience**, not the adoption path. The expanded chain above is equally
supported and is what `docs/` should lead with.

## 1. Host route files load into the package's groups

`Numerosis::routes()` keeps owning the *group structure* — per-central-domain
`web` + `domain()` groups, the single `tenant` group with its path-mode prefix
— which is the thing a host cannot reproduce and the whole reason `using:` is
required. It stops owning the *contents*.

Inside the central-domain group, before the package's own `web.php`:

```php
if (is_file($hostRoutes = base_path('routes/web.php'))) {
    require $hostRoutes;
}
```

Same for `base_path('routes/tenant.php')` inside the tenant group.

**Host file loads first, deliberately.** Core's `/` route is declared first
today precisely so a second route on `/` never matches (see `routes/web.php`'s
own comment, and `numerosis.routes.home_view`, the workaround). Appending the
host's file would leave that trap intact. Loading it first means a host's
`Route::get('/')` wins the path match — the point of the exercise. Core's
`home` route still registers on the same `/` URI, so `route('home')` and
`CompleteRedirectCheckout`'s fallback keep resolving, now rendering the
host's page. `numerosis.routes.home_view` stays for hosts that would rather
set a view than declare a route.

`require`, not `require_once`: the central file is required once per central
domain, matching how the package's own `web.php` is already loaded in that
loop. `is_file()` guards a host — or Testbench's `workbench/` — without one.

**Deletes:** `Numerosis::addCentralRoutes()`, `addTenantRoutes()`,
`resetRouteContributionsForTesting()`, and `Contributions`' two closure lists
plus the `$source` attribution nothing but its own test ever read.

`numerosis:install` publishes an empty `routes/tenant.php` stub under a new
`numerosis-routes` tag. `routes/web.php` already exists in every skeleton.

## 2. `api:` and `health:` must survive adoption

With `using:` set, `ApplicationBuilder` never builds the `api`, `health` or
`pages` routes — an app with an API silently loses it on adoption, which is
the single worst thing in this list because nothing errors.

`Numerosis::routes()` loads the host's API routes by the same convention
Laravel would, outside the domain groups so behaviour is identical to before
adoption:

```php
if (is_file($api = base_path('routes/api.php'))) {
    Route::middleware('api')->prefix('api')->group($api);
}
```

`Numerosis::configure()` gains `apiPrefix` alongside `commands`/`channels` for
a host that uses a non-default prefix. `health:` and `then:` are documented as
requiring the expanded chain — a health route is three lines the host can put
in `routes/web.php`, and **`then:` must never be forwarded**: `withRouting()`'s
guard is `is_null($using) && (...) || is_callable($then)`, so a callable
`$then` overwrites `$using` and discards numerosis's routing entirely.

## 3. `configure()` forwards `commands` and `channels`

`configure()` (`src/Support/Numerosis.php:123`) accepts only `$basePath`, so a
host needing `commands: __DIR__.'/../routes/console.php'` — standard skeleton
content — must abandon the one-liner. Add `?string $commands = null,
?string $channels = null, string $apiPrefix = 'api'`, forwarded into
`withRouting()`. Both `commands` and `channels` are applied unconditionally
and independently of `using` (`ApplicationBuilder.php:179-185`), so there is
no interaction to manage.

## 4. Stop reverting the host's middleware

`Numerosis::middleware(Middleware $m)` is already additive — it mutates the
host's own object and discards nothing. The problem is entirely in
`NumerosisServiceProvider::registerMiddleware()` (`packageBooted()`), which
re-applies the package's aliases, `TrustProxies::at('*')` and
`prependMiddleware(TrustHosts::class)` **unconditionally**, after the host's
`withMiddleware()` closure has run. Anything the host changed is reverted
before the first request.

That method exists as a self-heal for a host that never calls
`Numerosis::middleware()` at all. So gate it on exactly that: have
`Numerosis::middleware()` set a static `$middlewareRegistered` flag, exposed
as `Numerosis::middlewareRegistered()`, mirroring the existing
`$routesRegistered`/`routesRegistered()` pair — and have `registerMiddleware()`
return early when it is set. A wired host keeps what it wrote; an unwired one
keeps today's self-heal.

Document the ordering rule that follows: call `Numerosis::middleware($m)`
**first** in the closure, then your own configuration, so yours wins.

## 5. Stop claiming the `layouts` and `pages` Livewire namespaces

`registerLivewireComponentNamespaces()` sets `livewire.component_namespaces.layouts`
and `.pages` to the package's directories when the key is null *or equals
`resource_path("views/{$namespace}")`* — that second branch fires precisely
when an existing app is using its own layouts. A Livewire namespace maps one
prefix to exactly one directory (`docs/extending.md` documents this), so this
is not a merge; the host's layouts become unreachable.

Core should not claim a generic name it cannot share. Register the package's
own under `numerosis-layouts` and `numerosis-pages`, and update core's 32
`layouts::`/`pages::` references across 19 files (`resources/views/**`,
`routes/{web,tenant}.php`, `src/Livewire/Tenant/Registration.php`) — a
mechanical rename. Leave the host's `layouts`/`pages` keys untouched.

## 6. One swap mechanism: the container

Every default numerosis supplies should be replaceable by a container binding,
which is the pattern the package already uses for its Fortify actions and
already documents (`docs/extending.md`: they "are bound as plain `singleton`s
in `packageRegistered()`, not forced. A host's `AppServiceProvider` runs
afterwards and its own ... call overwrites the binding — override is free, no
opt-in seam to build"). No new config keys, and no clobber — `registerMiddleware()`
rewrites only the alias→class-string map, while a binding swaps the concrete
the pipeline resolves, so the two never contend.

**Works today, documentation only:**

- **Middleware.** `Pipeline.php:208` resolves every pipe with
  `$this->getContainer()->make($name)`, and `InitializeTenancy` delegates via
  `app(TenancyServiceProvider::identificationMiddleware())` — so
  `bind(InitializeTenancy::class, MyResolver::class)` swaps either the aliased
  delegator or the class it delegates to. The replacement need not extend the
  package class; the pipeline only calls `handle()`.
- **Seeders.** `Seeder::resolve()` does `$this->container->make($class)`
  (`Seeder.php:129`), so binding swaps any seeder the package's own
  `DatabaseSeeder`/`TenantDatabaseSeeder` calls.
- **Features.** `bootstrapFeatures()` does `$this->app->make($feature)->bootstrap()`,
  so binding swaps a feature's implementation while `numerosis.features` keeps
  toggling whether it runs at all.
- **Policies.** `registerPolicies()` maps model => policy class-string and
  `Gate` resolves through the container.

**Needs extracting first:** the exception context. `Numerosis::exceptions()`
contributes one `context()` callback (tenant id, guard, user global id) plus
`dontReportDuplicates()` and a 30/min throttle. Move the callback body behind
`Contracts\Exceptions\ProvidesExceptionContext` with
`Services\Exceptions\TenantAwareExceptionContext` as the default (keeping its
`try`/`catch (Throwable)`, which stops a bootstrap-time exception being
masked), and register
`$exceptions->context(fn (): array => app(ProvidesExceptionContext::class)->handle())`.
Resolving inside the closure is safe despite `exceptions()` running
pre-container — `context()` only stores the closure, invoked at report time,
which is why the current body can already call `tenancy()` and `Auth::user()`.
A host binds its own to add domain context, or decorates by depending on the
default in its constructor.

## 7. `registerExceptionsUsing()`

`routes()`/`middleware()`/`broadcasting()` each have a `registerXUsing(Closure)`
that replaces the package's wiring outright (`src/Support/Numerosis.php:705-728`).
`exceptions()` has none. Add `$registerExceptionsCallback` +
`registerExceptionsUsing()`, checked at the top of `exceptions()` as the other
three do, completing the set.

## 8. A real `Numerosis` Facade — post-boot methods only

Add `Nvade\Numerosis\Facades\Numerosis` extending `Illuminate\Support\Facades\Facade`,
`getFacadeAccessor()` returning `Support\Numerosis::class`, bound as a
parameterless singleton. Gives a host `swap()`/`spy()`/`shouldReceive()`.

`Facade::__callStatic()` does `$instance->$method(...$args)` and PHP permits
calling a `static` method through an instance, so **`Support\Numerosis` needs
no changes** — every method stays static and every internal call site keeps
calling it directly.

**`routes()`, `middleware()`, `exceptions()` and `configure()` must never go
through the Facade.** They run while `ApplicationBuilder` is being built,
before the container exists and before `RegisterFacades`, where
`Facade::getFacadeRoot()` is null and the call throws `RuntimeException: A
facade root has not been set` — the documented 2026-08-31 production
crash-loop in `.ai/rules/package-host-bootstrap.md`. `bootstrap/app.php` keeps
importing `Support\Numerosis` directly. Say so in the Facade's docblock.

**Unverified:** whether Mockery intercepts originally-`static` methods invoked
via `$instance->method()`. Test `Numerosis::shouldReceive('isCentralDomain')`
early; if it does not intercept, document `swap()` only — which already
satisfies the requirement.

## 9. Delete `Contributions`

With host route files working and the container as the swap mechanism, the
rest fall to conventions already present.

**Tenant migrations.** `HostConfig::tenantMigrationParameters()`
(`src/Support/HostConfig.php:163`) seeds `$paths` from
`$parameters['--path'] ?? []`, so once it writes `--path` — which it always
does, to inject the package's migrations — stancl's implicit
`database/migrations/tenant` default is gone and a host's conventionally
placed tenant migrations silently never run. Seed it instead with
`[database_path('migrations/tenant')]`. `Numerosis::tenantMigrationPaths()`
drops to `[self::tenantMigrationPath()]`; delete `addTenantMigrationPath()`. A
non-conventional directory is still reachable by setting
`tenancy.migration_parameters['--path']` in the host's own `config/tenancy.php`,
which this method leaves alone.

**Seeders.** Delete the `...Numerosis::tenantSeeders()` /
`...Numerosis::centralSeeders()` spreads and the four methods; binding (§6) is
the replacement, and it is better than having the host reimplement the call
list because the host keeps inheriting seeders core adds later. The
`class_exists()` bind at `src/NumerosisServiceProvider.php:169` still steps
aside for a host defining `Database\Seeders\DatabaseSeeder`, and
`numerosis.tenancy.seeder` still repoints the tenant entry point.

**Permission contexts.** Extract the inlined `$contexts` arrays in
`RoleAndPermissionSeeder::run()` and `Tenant\PermissionAndRoleSeeder::run()`
into `protected function contexts(): array`, matching the late-binding shape
`Permission::actionsFor()` already uses. Adding a context is then a three-line
subclass plus the seeder bind — one mechanism, not a second registry. Delete
`addPermissionContext()`/`permissionContexts()`.

**Tenant columns.** Two redundant registries do this today —
`Tenant::addCustomColumns()` (`src/Models/Central/Tenant.php:92`) and
`Numerosis::addTenantColumns()`. Delete both. `Tenant::getCustomColumns()`
becomes the hardcoded list plus lazily-memoized schema introspection:
`array_diff(Schema::connection(...)->getColumnListing('tenants'), ['data', ...HARDCODED])`,
returning `[]` **without memoizing** while the table does not exist yet so it
retries after migration. Add `Tenant::flushColumnCache()`, called from
`Numerosis::resetModelCache()` — the same per-boot flush every other static
memoization here gets. A column added by migration is then recognized with no
registration at all, and `docs/extending.md`'s "only half the job" caveat goes
away.

**`src/Support/Contributions.php` is deleted.**

---

## Files

- `src/Support/Numerosis.php` — host `web.php`/`tenant.php`/`api.php` loading
  in `routes()`; `$middlewareRegistered` flag + `middlewareRegistered()`;
  `configure()` gains `commands`/`channels`/`apiPrefix`;
  `registerExceptionsUsing()`; exception context moved out; delete the ten
  `Contributions`-delegating methods.
- `src/Support/Contributions.php` — deleted.
- `src/Support/HostConfig.php` — `tenantMigrationParameters()` default seed.
- `src/NumerosisServiceProvider.php` — early return in `registerMiddleware()`;
  `numerosis-layouts`/`numerosis-pages` namespaces; Facade and
  `ProvidesExceptionContext` bindings; `numerosis-routes` publish group;
  publish tag for `TenantDatabaseSeeder`.
- `src/Facades/Numerosis.php`, `src/Contracts/Exceptions/ProvidesExceptionContext.php`,
  `src/Services/Exceptions/TenantAwareExceptionContext.php` — new.
- `src/Models/Central/Tenant.php` — schema introspection, `flushColumnCache()`,
  delete `addCustomColumns()`.
- `resources/views/**`, `routes/{web,tenant}.php`,
  `src/Livewire/Tenant/Registration.php` — mechanical `layouts::`/`pages::` →
  `numerosis-layouts::`/`numerosis-pages::` rename, 32 references / 19 files.
- `database/seeders/{DatabaseSeeder,TenantDatabaseSeeder,RoleAndPermissionSeeder}.php`,
  `database/seeders/Tenant/PermissionAndRoleSeeder.php` — drop spreads,
  `contexts()` extraction on both permission seeders.
- `stubs/routes/tenant.php` — new.
- `tests/Feature/Support/PackageContributionSeamsTest.php` — rewrite against
  the new conventions. Keep the `Features::register()` cases — different seam.
- `tests/Feature/Models/Central/TenantColumnsTest.php` — rewrite against
  schema introspection.
- New tests: **an adoption test** asserting a host's `routes/web.php`,
  `routes/tenant.php` and `routes/api.php` all register and the host's `/`
  wins over core's; **a no-revert test** asserting host trust config and a
  host middleware alias survive `packageBooted()`; **binding tests**, one per
  swappable kind (middleware, seeder, exception context); **a Facade test**
  (`swap()`, the `shouldReceive()` check, `registerExceptionsUsing()` bypass).
- `docs/extending.md`, `docs/host-requirements.md`, `README.md` — lead with
  the adoption path (tier 0) rather than `configure()`; rewrite the seam table
  for routes/migrations/seeders/permissions/columns; document the three tiers
  (closure form → container binding → `registerXUsing()`) with a table of
  bindable classes; the Facade's scope and bootstrap-time exclusion.
- `.ai/rules/package-boundaries.md` — its "Contribution readers" section
  describes exactly what is deleted; update via `record-rule` after landing.
- **Outside this repo:** thin-app's `AppServiceProvider::registerMarketingRoutes()`
  moves its four `Route::view()` calls into its own `routes/web.php`, and its
  dead `Route::get('/')` becomes live. Call it out in the PR.

## Verification

- `vendor/bin/pest --filter=PackageContributionSeamsTest`
- `vendor/bin/pest --filter=TenantColumnsTest`
- The new adoption / no-revert / binding / Facade tests — report back if
  `shouldReceive()` does not intercept (§8)
- `composer test` — full suite; the Livewire namespace rename and route
  loading are exercised broadly, and this is where a missed `pages::`
  reference surfaces
- `composer analyse` — deleting `Contributions` must not orphan a
  `phpstan-baseline.neon` entry (`.ai/rules/static-analysis.md`: compare
  cold-vs-cold)
- `composer format`
