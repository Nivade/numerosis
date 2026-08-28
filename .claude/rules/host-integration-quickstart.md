---
topic: host-integration-quickstart
updated: 2026-08-12
---
# Integrating Numerosis Into an Existing Host

`docs/host-requirements.md` documents what a host must own and what
`HostConfig` normalizes automatically — it does not cover the failure modes
below, because none of them are config-shape problems `verify*()` can catch.
Found integrating Numerosis into `tabellio`, a pre-existing Laravel Livewire
starter-kit app (Fortify auth, its own `App\Models\User`, its own test
suite) rather than a fresh install. Every one of these produced a passing
`numerosis:install --verify-only` and a broken app or test suite anyway.

- **`packageRegistered()` — not `packageBooted()` — repoints
  `livewire.component_namespaces.{pages,layouts}`, and that's the wrong
  phase for a host to fight from `boot()`.** `LivewireServiceProvider::boot()`
  reads `config('livewire.component_namespaces')` once and bakes the result
  into the view finder's hints (`app('view')->addNamespace(...)`) — by the
  time ANY provider's `boot()` runs, changing the config value again has no
  effect on what's already registered. A host that reclaims these
  namespaces from its own `AppServiceProvider::boot()` (the natural-seeming
  place) will see the *config* value correctly overridden and the *actual*
  view resolution still hijacked — confirmed by reflecting
  `app('view')->getFinder()`'s `hints` property, not by reading `config()`
  back. Laravel calls `register()` on every provider before `boot()` on any
  of them, so the fix is trivial once you know the phase: set the config
  from the host's own `register()`, not `boot()`. Consider exposing this as
  a documented row in `docs/host-requirements.md`'s config table, or
  registering the namespaces later (`packageBooted()` instead of
  `packageRegistered()`) so a host's own `boot()`-phase override — the
  intuitive place to put one — actually wins.

- **`routes/auth.php` registers `login`/`register`/`logout`/
  `verification.verify` unconditionally, regardless of feature flags** —
  only the OAuth routes (`SocialLoginFeature`) and password-reset routes
  (`PasswordResetFeature`) are gated. A host keeping its own auth system
  (Fortify, in this case) and disabling every auth-related feature it can
  still gets a silent route-name collision on those four, because they
  aren't behind any flag at all. Nothing errors — Laravel's router just
  keeps the last-registered route under that name, so which auth system
  actually handles `/login` becomes an artifact of provider boot order
  rather than a decision anyone made. The only way out found: skip
  `Numerosis::routes()`'s default entirely via `registerRoutesUsing()`, and
  hand-roll a `routes/central.php` that requires the *non-auth* pieces of
  the package's `routes/web.php` (billing webhook, checkout, tenant
  creation) directly against the package's own Action/Livewire classes,
  never touching its `routes/auth.php`. This is real duplicated wiring —
  a host wanting even one of Numerosis's own auth routes has no way to opt
  into "the rest of `routes/web.php`, minus `routes/auth.php`" short of
  copying it. Worth a documented split (e.g. a
  `routes/web-without-auth.php` or a `Numerosis::routes(withAuth: false)`
  flag) if this pattern repeats.

- **`0001_01_01_000000_create_users_table`,
  `0001_01_01_000001_create_cache_table`, and
  `0001_01_01_000002_create_jobs_table` collide by filename with the stock
  Laravel migrations every fresh app ships** — because they *are* those
  stock migrations, copied into `database/migrations/central` unmodified
  apart from the `users` one. Laravel's migrator discovers migrations from
  every path and dedupes by filename; a host that hasn't deleted its own
  three same-named files gets *one* copy silently skipped, not an error, not
  a merge. Which copy wins is a path-registration-order accident, not
  something to rely on. Migrating a host with both present is the first
  thing that should be checked, and isn't caught by
  `verifyDatabaseConnections()` or any other `verify*()` — it just means
  the eventual schema is missing whichever columns the *skipped* file would
  have added (host's own `two_factor_*` migration still runs fine
  regardless, since it only `Schema::table()`s an existing `users` table —
  the collision is specifically about table *creation*, not the columns
  layered on after). Consider having `numerosis:install` warn when a host
  migration shares a basename with a package central migration — it already
  walks both directories for `verifyTenantMigrationPath()`'s narrowed check.

- **`Numerosis::factoryNameFor()` overrides Laravel's *global*
  `Factory::guessFactoryNamesUsing()` for every `\Models\`-namespaced class,
  which includes a host's own models that happen to sit under
  `App\Models\`.** `App\Models\User::factory()` on a fresh integration
  silently resolves to `Nvade\Numerosis\Database\Factories\UserFactory`
  (package's own field shape) rather than the host's
  `Database\Factories\UserFactory` — `modelNameFor()`'s reverse lookup then
  *does* correctly instantiate the host's model class, so the bug doesn't
  surface as a wrong class, it surfaces as missing/wrong attributes (a
  `withTwoFactor()` state method that doesn't exist, a factory definition
  that never sets a column the host's migrations added). The fix that
  works: `#[UseFactory(HostFactory::class)]` on the model — Laravel's own
  `HasFactory::newFactory()` checks that attribute *before* falling back to
  the package's global guesser, so it's a full escape hatch, just an
  undocumented one relative to this package's own factory-name convention.
  Worth a line in `docs/host-requirements.md`'s `numerosis.models.*` row,
  or in `Numerosis::factoryNameFor()`'s own docblock, since the failure
  mode (wrong fields, not wrong class) doesn't point back at the cause.

- **A host using plain `Illuminate\Foundation\Testing\TestCase` (not this
  package's own Testbench harness) needs its own version of
  `tests/TestCase.php`'s central-connection cleanup, and the ordering note
  in this package's own `.claude/rules/testing.md` is Testbench-specific in
  the wrong direction for that host.** `RefreshDatabase` only transacts the
  default connection; every model using stancl's `CentralConnection` trait
  (`CentralUser` and any host subclass of it, `Tenant`, `Domain`, …) writes
  through the separately-named `central` connection and survives rollback,
  so a second test creating a user with the same email/global_id fails
  looking like a validation bug. This package's own suite fixes it with
  `beforeApplicationDestroyed()` hooks registered *before* `parent::setUp()`
  — correct **only** because Orchestra Testbench's
  `beforeApplicationDestroyed()` is `array_unshift` (last-registered runs
  first). A host on plain Laravel has the opposite ordering
  (`Illuminate\Foundation\Testing\TestCase`'s own version is `[] =`,
  last-registered runs last) and has to register *after*
  `parent::setUp()` to land after `RefreshDatabase`'s own rollback — the
  same fix, inverted registration order, easy to get backwards by copying
  this package's own `tests/TestCase.php` verbatim into a host. Worth
  either: shipping a trait (`Nvade\Numerosis\Testing\CleansUpCentralWrites`
  or similar) that does the right thing on whichever base class a host
  actually extends, or a callout in `docs/host-requirements.md` pointing at
  this exact ordering trap for hosts not built on Testbench.

- **`TenancyServiceProvider` turns on `DomainTenantResolver::$shouldCache`
  unconditionally, and a fresh Laravel app's own default config makes that
  cache silently useless.** `config/cache.php`'s stock `'serializable_classes'
  => false` — Laravel's own gadget-chain hardening default, present in
  every new app since it was introduced — passes `['allowed_classes' =>
  false]` to every `unserialize()` call `Illuminate\Cache\RedisStore`
  (and every other cache store) makes. That flag doesn't reject the call;
  it silently converts *any* cached object, of *any* class, into a useless
  `__PHP_Incomplete_Class` — no exception, no log line, at the exact
  moment of the read. `DomainTenantResolver::resolve()` caches a full
  `Tenant` model (`TenancyServiceProvider.php:182`), which on `tabellio`
  was the very first thing in the whole app to cache an Eloquent object at
  all — so this had presumably never fired against that host's cache
  store before. First request after cache-clear resolves the tenant fine
  (cache miss, resolves fresh, caches the result); every request after
  that gets `Stancl\Tenancy\Resolvers\DomainTenantResolver::resolved():
  Argument #1 ($tenant) must be of type ... Tenant, __PHP_Incomplete_Class
  given` — reads exactly like a tenancy or serialization bug, and the
  actual cause is two file's defaults disagreeing, neither of them wrong
  in isolation. Confirmed empirically: plain `stdClass` (a core, always-
  loaded PHP class, zero relation to this package) reproduces the same
  failure when round-tripped through `Cache::put()`/`Cache::get()` on a
  host with the stock `serializable_classes => false` — this is not
  Tenant-specific, not Redis-specific (reproduces on the database cache
  driver too, since both go through the same `Store::unserialize()`), and
  not a bug in this package's own code. `numerosis:install`'s
  `verify*()` sweep has nothing to say about it, because
  `serializable_classes` isn't a key this package normalizes or reads —
  it's purely a Laravel cache-config default that this package's own
  opt-in behavior (tenant-resolver caching) happens to be the first
  consumer to expose.

- **The `deleteCentralWrites()` → `deleteTenantDatabases()` call order this
  package's own `tests/TestCase.php` uses is only safe *because* of a
  mechanism a host copying the pattern is unlikely to have.**
  `deleteTenantDatabases()`'s primary lookup there is
  `CloneTenantSchema::takeCreatedDatabases()` — a static list populated by
  this package's own template-cloning test speedup — with a `tenants`-table
  query as a documented fallback "for tenants created outside the clone
  path." A host without that speedup (`tabellio`, M2) has *only* the
  fallback, as its primary and sole mechanism — and calling
  `deleteCentralWrites()` first (which empties the `tenants` table, along
  with every other table recorded as written on the `central` connection)
  before `deleteTenantDatabases()` runs means the second step's query
  finds nothing, silently, every time. No error, no failed assertion — the
  physical tenant database this test just created simply never gets
  dropped. On `tabellio` this passed unnoticed for an entire milestone
  (each test's tenant domain name was unique) until two separate test runs
  happened to reuse the same domain, and the second run's "fresh" tenant
  database was actually the first run's leaked one — surfacing as a
  `Duplicate entry` unique-constraint violation on a completely unrelated
  table, reading like a fresh application bug. ~30 orphaned `tenant*`
  databases had accumulated in that host's dev MySQL before the mechanism
  was traced. The fix for a host without clone-tracking: read the
  `tenants` table (`deleteTenantDatabases()`) *before* emptying it
  (`deleteCentralWrites()`) — the reverse of this package's own order.
  Worth flagging explicitly wherever `tests/TestCase.php` is held up as
  the pattern to copy, since the two cleanup steps read as
  order-independent from their names alone and the failure mode is silent
  rather than loud.

## All seven follow-ups shipped 2026-08-13 — what to reach for now

Each trap above still describes what a host *sees*; these are the mechanisms
that now exist so it doesn't have to be rediscovered. Numbering matches the
original follow-up list, which this replaces.

1. **`Nvade\Numerosis\Testing\CleansUpTenancyDatabases`** (also closes 7).
   Ships the central-write and tenant-database teardown as one trait, and
   **does not depend on winning the `beforeApplicationDestroyed()` ordering
   race** — the thing a host copying `tests/TestCase.php` gets backwards half
   the time, since that method appends on `Illuminate\Foundation\Testing\
   TestCase` and `array_unshift`es on Testbench's. Instead of guessing which
   side of `RefreshDatabase`'s rollback it lands on, `cleanUpTenancyDatabases()`
   does the two things being after the rollback would have bought: ends
   tenancy (so the default connection isn't pointed at a database about to be
   dropped) and rolls back every open transaction itself (so the central
   deletes don't block on the test's own row locks). Tenant database *names*
   are read before the central deletes, not after — the 7 half. Hooks for a
   suite with a clone-based speedup: `additionalTenantDatabases()` and
   `preservedTenantDatabases()`; `keepDatabaseSchema()` (call after
   `parent::tearDown()`) pins `RefreshDatabaseState::$migrated`.
   `tests/TestCase.php` now composes it rather than carrying its own copy,
   so the package's whole suite is the regression test; the ordering half has
   its own, `tests/Feature/Testing/CleansUpTenancyDatabasesTest::
   test_it_drops_a_tenant_database_named_only_by_a_row_the_central_deletes_remove`,
   verified to fail when the two steps are swapped.
2. **`InstallNumerosisCommand::verifyCentralMigrationCollisions()`** — warns
   (doesn't fail: a host may have merged the schema into its own file) when
   a file in `database/migrations` shares a basename with one in the
   package's `database/migrations/central`. Reports it as "yours runs, the
   package's copy is silently skipped", which is the direction the migrator
   actually resolves it in: `MigrateCommand::getMigrationPaths()` appends
   `database/migrations` last and `Migrator::getMigrationFiles()` keys by
   migration name, so the last path wins.
3. **`#[UseFactory]`** is now documented as the per-model escape hatch, in
   `docs/host-requirements.md`'s notes and on `Numerosis::factoryNameFor()`'s
   own docblock — including the part that makes it hard to trace, that
   `modelNameFor()` still builds *your* model, so the symptom is wrong
   fields rather than a wrong class.
4. **Livewire namespaces stayed in `packageRegistered()`, deliberately** —
   moving them to `packageBooted()` was the other option and is worse: the
   package's own default would then race `LivewireServiceProvider::boot()`
   (provider boot order between two auto-discovered packages isn't ours to
   control), so the package could lose its own hint registration. What
   shipped instead is the documentation half: a note in
   `docs/host-requirements.md` and a comment on the loop itself saying a
   host must override from its own `register()`, since app providers
   register after package providers and Livewire bakes the config into view
   finder hints during its own `boot()`.
5. **`Numerosis::routes(withAuth: false)`** skips `routes/auth.php` and keeps
   everything else in `routes/web.php`. Reached from `bootstrap/app.php` as
   `->withRouting(using: fn () => Numerosis::routes(withAuth: false))`; the
   first-class-callable form (`Numerosis::routes(...)`) still includes them,
   because `RouteServiceProvider::loadRoutes()` invokes it through
   `$this->app->call()`, which fills an unbound primitive from its default
   rather than injecting the router. `tests/Feature/Support/AuthRoutesOptOutTest`
   pins both halves.
6. **`TenancyServiceProvider::shouldCacheResolvedTenants()`** decides
   `DomainTenantResolver::$shouldCache` from what the host's cache config can
   actually round-trip, rather than setting it `true` unconditionally: `false`
   in `cache.serializable_classes` (a fresh Laravel app's own hardening
   default) turns the cache off, an allowlist has to name
   `tenancy.tenant_model`, and `numerosis.tenancy.cache_resolved_tenants`
   (`true`/`false`, default `null` = decide automatically) overrides either
   way. The decision runs from a `booting()` callback, after
   `HostConfig::apply()`'s own, since it reads `tenancy.tenant_model`.
   `verifyTenantResolverCache()` warns when the cache ended up off, because
   the cost (a central lookup per tenant request) is otherwise invisible.
   `tests/Feature/Providers/TenantResolverCacheTest` also pins the *mechanism*
   — that a cached object comes back as `__PHP_Incomplete_Class` from a
   `Cache::get()` reporting success — so the reasoning isn't taken on trust
   if Laravel ever changes it.
7. Folded into 1.

**Still not fixed, deliberately:** the three colliding migration basenames
(`0001_01_01_00000{0,1,2}_*`) are still shipped under those names. Renaming
them would be the structural fix and would break every existing install —
the `migrations` table records the old name, so a renamed file re-runs and
fails on an existing table. The warning in 2 is the trade.
