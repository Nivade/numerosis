---
paths:
  - 'src/Support/**'
  - 'src/Http/Middleware/**'
  - 'packages/**'
---

# Package Boundaries

> **Rewritten 2026-09-03 (Phase 7 of `.claude/plans/archive/humming-nibbling-flame.md`),
> for the two-package shape Phase 3 left behind.** `packages/{auth-ui,onboarding,account}`
> folded into core; `packages/filament` and the module system were deleted
> outright in Phases 1–2. The seam table, boundary facts and history below
> describe **`nvade/numerosis` (core) and `nvade/numerosis-ui` only** — one
> host-facing seam list, not a satellite-registration protocol. Everything
> this file used to say about satellites contributing into core (panels
> config, `numerosis.panels.*`, `PurchasesModules`, the module system) is
> **void** and has been removed rather than kept as marked history; see
> `docs/extending.md` for the current, single seam list and
> `.claude/plans/archive/humming-nibbling-flame.md` for why each satellite went.

Layout: one repo, core at the root plus `packages/ui`, path-installed from a
single `{"type":"path","url":"packages/*"}` entry and published as read-only
splits on tag. `tests/Feature/PackageBoundariesTest.php` enforces the one
boundary left — `nvade/numerosis-ui` may not reference core, `Filament\`,
`tenancy()` or a named `route()` — in a monorepo the filesystem enforces
nothing else.

## The seams

All on `Nvade\Numerosis\Support\{Numerosis,Features}`, all additive. A host
calls these; core never names a host's classes. Full table, with notes, is in
`docs/extending.md` — this file only records the boundary facts that are easy
to get wrong.

`Numerosis::registerRoutesUsing()` / `registerMiddlewareUsing()` /
`registerBroadcastingUsing()` still exist and still replace the whole
mechanism. Reach for the `add*` seams first; the `registerXUsing()` ones are
for a host that genuinely wants none of the defaults.

## Boundary facts that still bite

- **A satellite that seeds its own permissions instead of calling
  `Numerosis::addPermissionContext()` 500s every page carrying a
  policy-guarded navigation item.** Navigation evaluates that resource's
  `viewAny` on every render to decide its own visibility, and Spatie throws
  `PermissionDoesNotExist` where a `false` return would degrade gracefully, so
  one missed context takes out every page rather than hiding one link. The
  seam creates a row per `Models\Permission::defaultActions()` action under
  guard `web` and grants them all to `admin`. Contribute the context from the
  satellite's own service provider, before `RoleAndPermissionSeeder` runs.

- **`#[UsePolicy]` alone does not survive the model-override seam, so every policy is also registered explicitly.** PHP attributes are not inherited, and `Gate::getPolicyFor()` reads them off the exact class it is handed — so a host whose `Numerosis::model()` override returns a subclass resolves no policy at all, and `Gate::allows()` against a model with no policy falls through to whatever the caller does with an unauthorized answer. `NumerosisServiceProvider::registerPolicies()` binds each pair, and binds the **package** class rather than the resolved subclass on purpose: `getPolicyFor()` tries an exact map entry, then the attribute, then the `App\Policies\*` name guess, then an `is_subclass_of` sweep. Registering the resolved subclass would take the first branch and silently beat a host's own `App\Policies\Central\TenantPolicy`; registering the base leaves the name guess ahead of us and still catches every subclass.

- **A feature class listed in config but not installed disappears silently,
  from two places, and neither raises anything you will see.**
  `Features::names()`'s `is_a($class, NamedFeature::class, true)`
  (`src/Support/Features.php:127`) autoloads and quietly returns `false` for a
  missing class, so the entry drops out of the *name map* and every
  `Features::enabled('that-name')` reads `false`. It used to at least crash
  afterwards: `NumerosisServiceProvider`'s boot loop called
  `$this->app->make($feature)->bootstrap()` straight over `Features::all()`.
  Since `a4167a4` that loop is `class_exists()`-guarded and does
  `Log::warning("… does not exist; skipping")` + `continue`
  (`src/NumerosisServiceProvider.php:243-251`), which was the right call for
  the widened `stancl/tenancy` constraint but removed the only loud symptom.
  **The failure mode is now a feature that is configured, reads as disabled,
  and logs one warning at boot.** Any packaging change that could leave a
  feature class unavailable needs the config change in the same commit, and
  grep the boot log before concluding a feature toggle is broken.

- **Core names no `Filament\` symbol anywhere** (`packages/filament` deleted
  Phase 1) — enforced by `PackageBoundariesTest`'s
  `test_core_names_no_filament_symbol()` for `src/`, `config/`, `routes/`,
  `resources/`, `database/` and `workbench/`. The asymmetry that used to
  matter here (`extends`/`implements`/`use <Trait>` resolve eagerly, type
  hints do not) is still the live rule for
  `ryangjchandler/laravel-cloudflare-turnstile`, the one package core still
  `suggest`s — see `.ai/rules/optional-dependencies.md`.

- **One seam per optional package, not one `class_exists()` per call site.**
  The module system was the worked example — seven consumers, all asking
  `ModuleSystemFeature::available()` — and it is deleted (Phase 2). The rule
  is what survives: give an optional dependency exactly one `available()`/
  `isEnabled()` predicate. `ryangjchandler/laravel-cloudflare-turnstile`
  (since Phase 6) is the only remaining case — `spatie/laravel-one-time-passwords`
  and `spatie/laravel-activitylog` moved to `require` 2026-09-05.

- **80 migrations live in core** (63 central, 17 tenant), including
  9 for `activity_log` (4 central, 5 tenant — **not** symmetrical;
  the tenant side carries an `upgrade_activitylog` migration with no central
  counterpart) and 2 for `one_time_passwords`. They stay in core even where
  the code that reads them moved, because the tables have to exist wherever
  core does — `Models\User` composes the OTP trait, `Tenant\User` composes
  `LogsActivity`, both `require` since 2026-09-05, no compat shim.

- **Core's config is split by *key*, not by package.** One publishable file,
  `config/numerosis.php`, eleven top-level keys (collapsed from thirteen
  partials 2026-09-05 — publishing them was impossible, since their
  `require __DIR__` paths would have resolved against the host's own config
  directory the moment the file was copied there). Splitting per package
  would be wrong even in the six-package world, and there is now only one
  package to split along anyway — a host's override file only needs to name
  the keys it changes.

  `NumerosisServiceProvider::packageRegistered()` deep-merges the package's
  defaults under whatever a host already published, since Laravel's own
  config merge is one level deep only.

## Contribution readers

Every `add*()` writer has a reader next to it:
`Numerosis::tenantMigrationPaths()`, `::tenantSeeders()`,
`::centralSeeders()`, `::permissionContexts()`
(`src/Support/Numerosis.php:512,536,558,590`), and `Features::registered()`.

Route contributions carry an optional `?string $source` too —
`addCentralRoutes()` / `addTenantRoutes()` (both on `Numerosis` and on
`Contributions`, the latter doing the actual storing as
`array{callback, source}` pairs), read back by
`Contributions::centralRouteSources()` / `::tenantRouteSources()`, in the
same order as `::centralRouteCallbacks()` / `::tenantRouteCallbacks()` (which
still return bare closures — `Numerosis::routes()` invokes them positionally
and doesn't need the pairing). With no satellite left to pass its own package
name, `source` is `null` unless the host calling these seams supplies one
itself — the mechanism from the six-package era is intact, just unused by
anything in this repo now. `tests/Feature/Support/PackageContributionSeamsTest.php`
is what exercises it (renamed from `SatelliteRouteContributionTest`).

The general rule stands: add the reader alongside the writer rather than
after — a boundary test that can enumerate contributions is strictly better
than one that scans files for forbidden strings.

## `Contributions`' lists are static, and only some of them deduplicate

Every list on `Support\Contributions` is `static`, so it lives as long as the
process. A provider that registers more than once — Octane's per-worker boot, a
host provider re-registered by a test harness — would grow them without bound
and hand `tenancy.migration_parameters` the same path several times.

- Migration paths, seeders and permission contexts go through `appendOnce()`,
  which appends what is not already present and preserves registration order.
- The two route-callback lists cannot. Two `Closure`s built from the same
  `function () { … }` on two boots are distinct objects with nothing comparable
  about them, and collapsing by `source` would drop a package's second,
  legitimately different contribution. They stay bounded only by there being
  one registration site per package.
- `$tenantColumns` has no reset at all, deliberately: its only writer is
  `Models\Central\Tenant`'s own declaration, so there is no per-test
  registration to undo. `flushRouteContributions()` and
  `flushMigrationAndSeederContributions()` stay two methods rather than one
  `flush()` so each caller clears only what it meant to; `PackageContributionSeamsTest`
  calls them separately.

## HostConfig's preference/correction split, and its one deliberate asymmetry
HostConfig is organized on one axis: what a host may legitimately choose, not which vendor config file a key lands in.

- **Preference** (host might want another value): projects from `numerosis.*` onto its vendor key unconditionally, no destination sniffing. Examples: `auth.providers.users.model` (from `numerosis.models.<CentralUser>`), `tenancy.database.central_connection` (from `numerosis.tenancy.central_connection`), `tenancy.seeder_parameters.--class` (from `numerosis.tenancy.seeder`).
- **Correction** (the package does not work otherwise): written only while the vendor key is unset or still holding a stock value, collected in `HostConfig::corrections()`/`applyCorrections()`.

A correction's guard is not a plain `=== null` check, because two vendor keys never resolve to null: `tenancy.filesystem.root_override.local` (stancl ships `'%storage_path%/app/'`) and `queue.failed.database` (Laravel ships `env('DB_CONNECTION', 'sqlite')`, so it silently rides `database.default` until the guard fires). `corrections()` stores `[stock values, corrected value]` per key and the loop checks `null || in_array($current, $stockValues, true)`.

**Deliberate asymmetry, do not "fix" into consistency:** `tenancy.{tenant,domain,central_user,tenant_user}_model` keep their original null-or-stancl-stock guard (`tenancyModels()`) — a host may set one of these four vendor keys directly and have it survive. `auth.providers.users.model` does not: it always projects from `numerosis.models.<CentralUser>` (`centralAuthProviderModelPreference()`) and overwrites a directly-set vendor value. Both read from the same `numerosis.models.*` config section; the difference is which vendor key each one owns.

When adding a new normalization: decide preference vs correction first. If correction, check whether the vendor default can be null before writing a plain null check — grep the vendor package's own shipped config for the key's default.
