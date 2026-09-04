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

- **A feature class listed in config but not installed is a hard container
  failure at boot, and the first symptom is the wrong one.**
  `NumerosisServiceProvider` does `$this->app->make($feature)->bootstrap()`
  over `Features::all()` (`src/NumerosisServiceProvider.php:238-245`), while
  `Features::names()`'s `is_a($class, NamedFeature::class, true)` autoloads
  and quietly returns `false` for a missing class — so the *name map* silently
  loses the entry first and the crash arrives from the `make()` loop. Any
  packaging change that could leave a feature class unavailable needs the
  config change in the same commit.

- **Core names no `Filament\` symbol anywhere** (`packages/filament` deleted
  Phase 1) — enforced by `PackageBoundariesTest`'s
  `test_core_names_no_filament_symbol()` for `src/`, `config/`, `routes/`,
  `resources/`, `database/` and `workbench/`. The asymmetry that used to
  matter here (`extends`/`implements`/`use <Trait>` resolve eagerly, type
  hints do not) is still the live rule for the packages core *does* still
  `suggest` — see `.ai/rules/optional-dependencies.md`.

- **One seam per optional package, not one `class_exists()` per call site.**
  The module system was the worked example — seven consumers, all asking
  `ModuleSystemFeature::available()` — and it is deleted (Phase 2). The rule
  is what survives: give an optional dependency exactly one `available()`/
  `isEnabled()` predicate. `spatie/laravel-one-time-passwords`,
  `spatie/laravel-activitylog` and, since Phase 6,
  `ryangjchandler/laravel-cloudflare-turnstile` are the remaining cases.

- **80 migrations live in core** (63 central, 17 tenant), including
  9 for `activity_log` (4 central, 5 tenant — **not** symmetrical;
  the tenant side carries an `upgrade_activitylog` migration with no central
  counterpart) and 2 for `one_time_passwords`. They stay in core even where
  the code that reads them moved, because the tables have to exist wherever
  core does — `Models\User` composes the OTP trait, `Tenant\User` and
  `Invitation` compose `LogsActivity`, both through
  `Support\Compat\*IfInstalled` shims that no-op without the package.

- **Core's config is split by *key*, not by package** (2026-09-01):
  `config/numerosis/<key>.php`, thirteen partials that `config/numerosis.php`
  `array_merge`s. Splitting per package would be wrong even in the six-package
  world, and there is now only one package to split along anyway — a host's
  override file only needs to name the keys it changes.

  Two traps the split introduced, both still live. **The root file can never
  be published** — its `require __DIR__` paths would resolve against the
  *host's* config directory — which is why `NumerosisServiceProvider` no
  longer calls `hasConfigFile('numerosis')` (that registers the real file for
  publishing) and instead does `mergeConfigFrom()` in `packageRegistered()`
  plus a `publishGroup()` of `config/stubs/numerosis.php`. And **a new
  partial is invisible until it is listed in that root `array_merge`**, with
  nothing failing: the key just defaults away through `HostConfig`'s
  deep-fill.

## Contribution readers

Every `add*()` writer has a reader next to it:
`Numerosis::tenantMigrationPaths()`, `::tenantSeeders()`,
`::centralSeeders()`, `::permissionContexts()`
(`src/Support/Numerosis.php:385,413,439,474`), and `Features::registered()`.

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
