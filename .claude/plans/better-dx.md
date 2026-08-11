# Reduce host requirements: from ~30 documented obligations to 6

Status: ✅ Substantially complete, 2026-08-11 — Phases 0–6 all done except
one dropped metric (`tests/TestCase.php`'s line-count target, accepted as a
Testbench-harness limitation, see Phase 6's entry below), plus
post-extraction-review.md's Phase 4 fully closed as a side effect. Written
2026-08-10 against `numerosis@75f179d` / `thin-app@8713d74`. **Nothing
committed yet in either repo** — all working-tree changes, this whole plan's
execution. Remaining before this is truly "done": review the diff as a
whole and decide what to commit, in what shape (likely more than one
commit given the scope), in both repos.

## Progress — read this first before continuing

- **Phase 0** (config-key moves out of framework files) — done, numerosis
  only.
- **Phase 1** (`HostConfig` normalizer) — done, numerosis only.
- **Phase 2** (bootstrap helper, panel providers) — done in **both**
  repos, verified live (thin-app container restarted clean, `/`, `/login`,
  `/admin`→`/admin/login` all 200, theme asset linked correctly). Tenant
  subdomain path untested — this dev DB has zero provisioned tenants,
  unrelated to the change.
- **Phase 3** (prebuilt frontend assets) — done in **both** repos, thin-app
  side finished 2026-08-11. `vite.config.js` input dropped to
  `app.css`/`app.js`; `resources/js/{central,tenant,stripe-checkout,
  stripe-confirm,stripe-appearance}.js` deleted (`git rm`, staged, not
  committed) — all five were byte-identical to the package's own copies, no
  host customisation lost. `npm run build` clean (57 modules, only
  `app-*.css`/`app-*.js` chunks now, no central/tenant chunks). `php
  artisan filament:assets` published `public/js/nvade/numerosis/numerosis.js`
  (79965 bytes) and `public/css/nvade/numerosis/numerosis.css` (82111
  bytes) — sizes match numerosis's `dist/` exactly, confirmed via the
  path-repo symlink (`thin-app/vendor/nvade/numerosis` → the numerosis
  working tree, so no `composer update` was needed to pick up the new
  `dist/` files). Smoke test: `/` → 200, `/login` → 200, `/admin` → 302
  (matches Phase 2 baseline); `/login`'s HTML carries the correct
  `window.Numerosis` config block and both prebuilt `<link>`/`<script>`
  tags, both URLs fetch 200. **Not verified**: actual browser console
  (Echo connect, Stripe module init) — no local Chrome available in this
  environment (`chrome-devtools` and `browser-use` MCP tools both failed to
  attach), so "loads and executes with no JS errors" is inferred from a
  clean build + correct HTML, not observed directly. Do that check by hand
  before trusting Echo/Stripe end-to-end. Full verification commands run
  and matched documented baseline exactly (515 passed / 1 known failure / 7
  skipped; 11 PHPStan baseline errors, 0 new; Pint clean); thin-app
  `route:list` (160 lines) and `config:clear` both fine.
  `resources/css/filament/tenantAdmin/theme.css` in thin-app is now dead
  (grepped, zero references — leftover from the deleted
  `TenantAdminPanelProvider.php`) but was **not** touched — it's tracked
  from an earlier commit, not part of this session's diff, and wasn't named
  in this plan's file list. Flagging for a deliberate cleanup pass, not
  doing it as a drive-by.
- Phase 3's browser-console gap (Echo/Stripe init) — user confirmed
  browser-verified 2026-08-11. Trusted.
- **Phase 4** (models by convention) — done, numerosis only, 2026-08-11.
  **Deviation flagged and confirmed with the user before writing code**:
  this phase's design (`App\Models\<suffix>` convention fallback inside
  `Numerosis::model()`) is what D12 in `.claude/plans/package-extraction.md`
  explicitly removed from this exact method ("no more by-convention
  `app()->getNamespace()` guessing — config or nothing"), after the user
  had already reopened R1 once over it. Asked before proceeding; user chose
  "proceed as written" — the `is_subclass_of()` guard this version adds
  (absent from D12's rejected one) is real new safety, and D12's own
  rejection predates D8's abstract→concrete change that removed the
  instantiation-by-proxy crash class this guard's absence used to enable.
  `Numerosis::model()`'s docblock now records this reasoning inline so a
  future reader doesn't need to reconstruct it from two plan files.

  Implementation: `Numerosis::model()` — config (`numerosis.models.<FQCN>`)
  first, then `App\Models\<suffix>` when `class_exists() &&
  is_subclass_of()`, then the package class. Memoized in a static array,
  reset every boot from `NumerosisServiceProvider::packageRegistered()`
  (`Numerosis::resetModelCache()`) — the memoization is a bare static
  property, not container-bound, so it would otherwise survive across
  Testbench's per-test `Application` rebuilds and leak a stale resolution
  from one test into the next; confirmed this matters, not theoretical
  (two existing tests deliberately set a broken override on
  `numerosis.models.Tenant::class` to test `verifyModelOverrides()`'s
  validation).

  `config/numerosis.php`'s `models` array: the 9 `env('NUMEROSIS_MODEL_*')`
  reads are gone, plain `null` defaults — the array is now purely the
  *explicit* escape hatch, no `.env` wiring at all, so the phpdotenv
  single/double-quote trap the old docblock warned about no longer exists
  as a live failure mode (nothing left reads those keys). `docs/host-
  requirements.md`'s `models` section and `tenancy.php` section rewritten
  to match.

  `InstallNumerosisCommand`: `appendModelOverrides()` deleted (no more
  auto-writing `NUMEROSIS_MODEL_*` into `.env`), along with
  `$appendedModelOverrides`. `verifyModelOverrides()` rewritten: explicit
  config still validated the same way (class exists, is a subclass); the
  "stub published but not wired" check now asks `Numerosis::model(X) ===
  X` instead of checking for an unset config key, since an unset key is no
  longer a problem by itself. `modelStubMap()` lost its now-unused `'env'`
  field.

  **Real-suite proof, not just unit tests**: `tests/TestCase.php` used to
  hand-set all 9 `numerosis.models.*` keys to point at the Workbench stubs
  (`App\Models\Central\Tenant` etc, which already sit at the conventional
  path and extend the package models) — 19 lines, one of the ~290-line
  pile this whole plan targets. Deleted that block entirely and reran the
  full suite: **518 passed** (515 baseline + 3 new targeted tests in
  `NumerosisSeamTest.php` — convention match, explicit-override-wins,
  memoize+reset), same 1 known failure, same 7 skipped. Every one of the
  ~108 `Numerosis::model()` call sites across the whole suite is now
  resolving through the convention path with zero explicit config — this
  is the strongest evidence available that the mechanism works, not an
  inference from reading the code. PHPStan: baseline count adjusted 31→22
  in `phpstan-baseline.neon` (removed `env()` calls), one pre-existing
  baseline error fixed as a side effect (`model()`'s own return type was
  previously unchecked against `class-string<TModel>`), net **10 errors,
  down from 11, 0 new** — confirmed by diffing against `git stash`, not
  assumed. Pint clean.

  **Not touched, flagged for the user**: thin-app's `.env` still carries
  all 9 `NUMEROSIS_MODEL_*` lines (each pointing exactly at the conventional
  `App\Models\<suffix>` path already, so they're now redundant/inert, not
  wrong). Left alone — editing a live host's `.env` wasn't asked for and
  crosses the same repo boundary Phase 2/3 paused for; harmless to leave,
  cheap to delete by hand whenever convenient.
- **Phase 5** (seeding) — done, numerosis only, 2026-08-11.
  `NumerosisServiceProvider::packageRegistered()` now binds
  `'Database\Seeders\DatabaseSeeder'` (string, not `::class` — the literal
  class doesn't exist in this codebase to resolve against, and PHPStan
  correctly flagged `class.notFound` when it was written as a class-constant
  reference) to the package's own seeder, gated by `class_exists()` — a
  fresh host's plain `php artisan db:seed` now reaches central roles,
  permissions, example plans and the module catalogue with zero file
  written. A host that writes its own `database/seeders/DatabaseSeeder.php`
  still wins outright; the class already existing is what stops the bind
  from ever touching that case, not an explicit check for it.

  `numerosis:install` seeds by default now; `--seed` replaced with
  `--no-seed` (opt-out) — no back-compat shim, per this repo's own rule
  (package unpublished, thin-app the only consumer). Two failure messages
  in `verifyCentralDataSeeded()` and the docs' `database/seeders/
  DatabaseSeeder.php` section updated to stop telling the user to pass a
  flag that no longer does anything.

  Test: `NumerosisServiceProviderDefaultsTest` gained one case proving the
  bind resolves to the package seeder — no reboot/reset dance needed,
  because this Testbench harness is *already* living proof of the "host
  wrote nothing" case (`Database\Seeders\DatabaseSeeder` genuinely doesn't
  exist here; the workbench fixture lives at
  `Workbench\Database\Seeders\DatabaseSeeder`, wired through
  `testbench.yaml`'s own mechanism, unrelated to Laravel's `db:seed`
  convention). Did **not** add a test running the real
  (non-`--verify-only`) install command — the existing test file's own
  docblock already states why: that path writes to `.env` and publishes
  files, which doesn't belong in a test process; every existing test in
  that file honours the same boundary, so a new one wouldn't either.

  Verified: full suite **519 passed** (518 + 1 new), same known failure,
  same 7 skipped; PHPStan 10 errors, 0 new (confirmed the two `class.notFound`
  hits from `::class` were real, fixed by switching to a string literal, not
  suppressed); Pint clean.

  **Not touched**: thin-app's own `database/seeders/DatabaseSeeder.php`
  (already calls the package seeder by hand — still correct, now merely
  optional for a *new* host, not a regression for this one). Whether to
  delete it is Phase 6 territory (final thin-app trim), not this phase.
- **Phase 6** — **partially done**, numerosis only, 2026-08-11. Three of
  five bullets landed; one was attempted and reverted (see below); thin-app
  untouched (paused per discipline).

  **Install command split — done.** New `printConfiguredKeys()` prints
  `HostConfig::applied()` at the top of every `numerosis:install` run
  (regardless of `--verify-only`), before the verify sequence — "what we
  configured" is now visibly separate from "what's still wrong." Of the 21
  `verify*()` methods: `verifyTenancyBootstrappers()` and
  `verifyLivewireDiskExclusion()` deleted outright (HostConfig's
  `tenancyBootstrappers()`/`livewireDiskExclusion()` fix both
  unconditionally on every boot, so the failure branch was structurally
  unreachable — confirmed by checking, not assumed); `verifyTenantMigrationPath()`
  narrowed (the "vendor path missing" failure is unreachable the same way,
  since `HostConfig::tenantMigrationParameters()` always appends it first;
  what survives validates any *extra* path a host has added, which
  `HostConfig` never touches); 11 more got a docblock note naming which
  `HostConfig` method now covers the common case, without changing their
  logic — each still fires for a host that overrides the key directly and
  breaks it, narrower in practice but not narrower in code. 5 true
  survivors untouched: `verifyStripeKeys()`, `verifyFilamentThemeAsset()`,
  `verifyCentralDataSeeded()`, `verifyModelOverrides()`,
  `verifyPublishedAssetsMatchSource()`.

  **`docs/host-requirements.md` rewrite — done.** Two lists, as specified:
  §1 the 6 (+1 conditional) irreducible obligations from this plan's own
  table; §2 one table covering every `HostConfig`/`NumerosisServiceProvider`
  normalization, what it defaults to, how to override it, and which
  (narrowed) `verify*()` still guards it. `HostRequirementsTest`'s doc↔command
  parity assertions both pass against the rewrite — every surviving
  `verify*()` is named exactly once with a real backtick-wrapped reference
  (the test requires *exactly one* per cell; an early attempt citing 2–3
  methods in one "Checked by" cell failed this and had to be split into
  separate rows), every dash-reasoned row states why.

  **`.claude/rules/package-host-bootstrap.md` and `auth-guards.md` — done.**
  Rewrote rather than deleted: `package-host-bootstrap.md`'s panel-provider
  bullet now says plainly that the providers it describes as "host-owned,
  always required" no longer exist (Phase 2 moved them into the package),
  and its bootstrap bullet now describes `Numerosis::configure()` plus the
  `registerRoutesFallback()`/`seedMiddlewareBaselineIfMissing()` self-healing
  pair as the structural fix, with the original hand-fix kept as the
  now-secondary path. `auth-guards.md`'s three `auth.defaults.guards.context.*`
  references updated to `numerosis.auth.guards.*` (Phase 0's rename),
  pointing at `Context::guard()` as the one place that reads it now.

  **`tests/TestCase.php` trim — attempted, reverted, not done.** This was
  the plan's own stated acceptance metric (290→~40 lines) and it does not
  hold up: Testbench's `CreatesApplication::resolveApplicationBootstrappers()`
  runs `RegisterProviders` (which fires `HostConfig::apply()`) **before**
  `getEnvironmentSetUp()` — the inverse of a real host, where config files
  load before any provider registers. Deleting the `tenancy.*`/
  `database.connections.central`/`auth.guards.*`/`session.domain` block on
  the assumption `HostConfig` would backfill it (the same proof-by-deletion
  that worked safely for `numerosis.models.*` in Phase 4) produced a
  `central` database connection cloned from Testbench's own stock
  `sqlite`/`:memory:` default, an empty `tenancy.central_domains` (no
  central routes registered at all), and a bogus stock migration path
  validated as real — **319 of 526 tests failed.** Fully restored; the
  discovery is recorded in `.claude/rules/testing.md`'s new
  "`TestCase::getEnvironmentSetUp()` runs *after* providers register, not
  before" section, including why Phase 4's proof-by-deletion doesn't
  generalise (`numerosis.models.*` is read lazily, at the moment a test body
  calls `Numerosis::model()`, long after this method has already run — the
  `HostConfig` normalizations are computed once, synchronously, during
  registration, before this method runs at all). `HostConfigTest`'s existing
  `rebootPackage()` pattern already proves every one of the 17 `HostConfig`
  normalizations independently of this trap; that's why the install-command
  narrowing above could proceed with confidence even though this couldn't.
  **Net effect: `tests/TestCase.php` is unchanged this phase.** If this is
  worth another attempt, it needs a different approach — reordering what
  Testbench considers "environment setup," or accepting that this
  particular metric can't be met inside this harness — not a retry of the
  same deletion.

  **Not done, and not attempted this phase**: post-extraction-review.md's
  Phase 4.2 (failure-path test for each of the ~19 surviving `verify*()`
  methods — today only `verifyModelOverrides()`/`verifyCentralDataSeeded()`
  have direct tests) and 4.3 (config schema version). 4.1 (a `--check`-style
  flag) and 4.4 (fold asset-drift warning into that flag) turn out to
  already be satisfied by the existing `--verify-only` flag and the fact
  `verifyPublishedAssetsMatchSource()` already runs unconditionally in the
  verify sequence — so "closes Phase 4" from this plan's original Phase 6
  bullet is **half true**: 4.1/4.4 were already done, 4.2/4.3 remain open.

  Verified throughout: full suite **519 passed** (matching Phase 5's
  baseline exactly, unchanged since `TestCase.php` ended up unchanged),
  same known failure, same 7 skipped; PHPStan 10 errors, 0 new; Pint clean.

- **thin-app trim — done, 2026-08-11.** `bootstrap/app.php`,
  `bootstrap/providers.php`, both Filament panel providers, the
  `isCentralDomain` macro and `vite.config.js`'s inputs were already handled
  by Phases 2–3 (found already committed in thin-app's working tree at the
  start of this pass — no work needed there). What was actually left:

  - **`config/numerosis.php`: 665 → 126 lines.** Diffed against the
    package's own copy first — thin-app's published file predated Phase
    0/4's `auth`/`social`/`panels`/`broadcasting` sections and the `models`
    array's `env()` removal entirely; `HostConfig::numerosisConfig()`'s
    deep-fill had already been silently patching those missing sections in
    production the whole time (this thin-app has been running correctly
    despite the stale file — live evidence the deep-fill works, not just
    Phase 4's test). Replaced with a real override: the `features` list
    minus `TurnstileFeature` (a list, not deep-filled, so this can't be
    trimmed further) and the module catalogue + plugin map (host data, no
    package default exists for either). Nothing else — domains, auth
    guards, social metadata, panel providers all come from the package now.
  - **`config/tenancy.php`: 212 → 88 lines.** Confirmed first that stancl's
    own package supplies a full stock file via its own `mergeConfigFrom()`
    (`vendor/stancl/tenancy/assets/config.php`), so anything matching stock
    could be dropped — but this file, unlike `numerosis.php`, has **no
    package-level deep-fill**, so a partial top-level key replaces stancl's
    whole value for that key, not just the named sub-key. Nearly shipped a
    version with a bare `'filesystem' => ['asset_helper_tenancy' => false]`
    — caught immediately by running `numerosis:install`, not by inspection:
    `verifyLivewireUploadDisk()` threw `Configuration value for key
    [tenancy.filesystem.disks] must be an array, NULL given` because that
    partial array had silently discarded stancl's `disks`/`root_override`/
    `suffix_storage_path` along with it. Fixed by repeating stancl's stock
    `filesystem` array in full alongside the one real change
    (`asset_helper_tenancy: false`); comment left in the file explaining why
    partial nesting doesn't work here, so the same mistake isn't repeated.
    Three genuine host customisations survived the trim (found by diffing
    against stancl's stock file, not assumed): `RedisTenancyBootstrapper`
    enabled, `asset_helper_tenancy` off, and all four optional `features`
    enabled (stock ships them commented out). Everything else — the four
    numerosis-specific model/domain keys, `bootstrappers`' two package
    entries, `database.central_connection`, `filesystem.root_override.local`,
    `migration_parameters` — matched either stancl's stock value or exactly
    what `HostConfig` sets, confirmed via `numerosis:install`.
  - **`config/auth.php`: 186 → 73 lines.** Found a real, previously-unnoticed
    gap while trimming: `Nvade\Numerosis\Http\Controllers\Socialite\Login`
    read `auth.defaults.redirect-route` directly — a package-owned key
    living in a framework file with no package default possible, the exact
    anti-pattern Phase 0 fixed for every *other* `auth.*` key it moved, just
    missed for this one. Fixed at the source (numerosis, not thin-app):
    `Login` now calls `RouteNames::tenantsMine()` — which already reads
    `numerosis.routes.names.tenants_mine`, already defaulting to the same
    `'tenants.mine'` value — instead of re-reading a second, undefaulted
    copy of the same route name. Confirmed no test exercised the branch
    that needed the fallback (`LoginTest`/`RedirectTest` both still pass,
    7/7); the gap was real but silent. `defaults.guards.context.*` (Phase
    0's rename) and the whole `social` block (moved to `numerosis.social.*`)
    were dead weight, removed. `guards.tenant`/`providers.tenant`/
    `providers.users.model` all come from `HostConfig` now; kept
    `providers.users` itself (with `driver: eloquent`, no `model`) since
    Laravel's own `AuthManager` needs *some* array there regardless of what
    `HostConfig` later fills in.
  - **`config/permission.php`: removed the dead `filament` block** (17
    lines) — nothing has read `permission.filament.*` since it moved to
    `numerosis.panels.access_control.*` in Phase 0; confirmed the new key's
    default matches the old block's values exactly (`'Access Control'` /
    `'Roles'` / `'Permissions'`) before deleting.
  - **`config/session.php`, `config/queue.php`: left untouched.** Both
    already 100% Laravel's own unmodified skeleton — zero numerosis
    footprint to trim. `queue.php`'s `failed.database` rides
    `env('DB_CONNECTION', 'sqlite')`, exactly the "coincidental with
    `database.default`" pattern `HostConfig::failedJobsConnection()`
    targets; confirmed it fires correctly (`DB_CONNECTION=mysql` in this
    host's `.env`, `database.default` resolves the same way, so `HostConfig`
    detects the match and fixes it to `'central'`) rather than assumed.
  - **`resources/css/filament/tenantAdmin/theme.css` deleted** — the file
    Phase 3 had already found dead (leftover from the deleted
    `TenantAdminPanelProvider`) but left alone pending "a deliberate cleanup
    pass." Re-confirmed zero references, then removed.
  - **The 9 `NUMEROSIS_MODEL_*` `.env` lines flagged under Phase 4 were
    already gone** by the time this phase started — resolved outside this
    session (not investigated further; harmless either way).

  **One numerosis-side bug found and fixed while verifying the trim, not
  part of the trim itself:** `verifyTenantMigrationPath()`'s "entry does not
  exist" check (added when this method was narrowed earlier in Phase 6) was
  a false positive against stancl's own stock `migration_parameters['--path']`
  default (`database_path('migrations/tenant')`) — a conventional location
  for a host's own tenant migrations that legitimately doesn't exist for a
  host running on the package's migrations alone. thin-app was the first
  host to hit it (no `database/migrations/tenant` directory). Removed that
  specific check; kept validating type (string) and shape (absolute path).

  **Verified**, in order: `numerosis:install --verify-only` clean (only a
  benign warning about `resources/css/app.css` intentionally diverging from
  the vendor original); `/`, `/login` → 200, `/admin` → 302 (unchanged from
  Phase 2's baseline); `/login`'s HTML carries the three OAuth buttons with
  no exceptions in the response; `config:cache` survives and the same smoke
  URLs still pass under it (config-cache is the one place a normalization
  running too late would show up); `route:list` unchanged at 160 lines.
  Package-side: full numerosis suite still 519 passed / 1 known failure / 7
  skipped, PHPStan 10 errors / 0 new, Pint clean. thin-app diff:
  **138 insertions, 1025 deletions** across the 5 files this phase touched
  (on top of what Phases 2–3 already removed).

### post-extraction-review.md Phase 4.2 + 4.3 — done, 2026-08-11

User chose, in order: drop the `tests/TestCase.php` acceptance metric
(accepted, `testing.md` has the recorded limitation), thin-app trim (done,
above), then these two. Both landed in full, closing Phase 4 of
`post-extraction-review.md` completely (4.1/4.4 were already satisfied
before this session; see the earlier Phase 6 entry).

**4.2 — failure-path tests, one per `verify*()` method.** 17 new tests in
`InstallNumerosisCommandTest.php` (only `verifyModelOverrides()` and
`verifyCentralDataSeeded()` had any before this pass), each mutating exactly
the config key its target method reads and asserting the command fails
naming it — plus one non-failure test for `verifyPublishedAssetsMatchSource()`
(warns, never fails the install, so its test asserts the warning text
appears and the command still exits successfully). Every test carries an
`@verifies <methodName>` tag; `HostRequirementsTest` gained a third
assertion (`test_every_verify_method_has_a_failure_path_test()`) that greps
for these tags via `InstallNumerosisCommand`'s own reflection helper and
fails if any `verify*()` method has none — so a future `verify*()` with no
test fails CI, not just a manual read. Two tests needed real debugging, not
just writing: `verifyTenancyModels()`'s test crashed `TestCase`'s own
teardown (`deleteTenantDatabases()` resolves `tenancy.tenant_model` to clean
up tenant rows, so leaving the test's deliberately-broken class in place
past the assertion took teardown down with it — fixed with a
`try/finally` restoring the real value); `verifyFilamentThemeAsset()` and
`verifyPublishedAssetsMatchSource()` both gate on filesystem state
(`public_path('css/filament')`, `resource_path('css')`) nothing in the
Workbench harness creates on its own, so both tests create and clean up
real directories by hand to reach the branch being tested at all — confirmed
first that `resource_path()` in this harness resolves under
`vendor/orchestra/testbench-core/`, genuinely different from the package's
own `resources/`, so the asset-drift test is a real hash comparison, not
comparing a file to itself.

**4.3 — config schema version.** New `numerosis.schema_version` (currently
`1`) in the package's own `config/numerosis.php`, and
`InstallNumerosisCommand::verifyConfigSchemaVersion()`. The one thing worth
knowing if this is touched again: it cannot read `config('numerosis.schema_version')`
— `HostConfig::numerosisConfig()`'s deep-fill would already have backfilled
a missing key from the package's *current* value by the time any check runs,
which would make the check pass regardless of how stale the host's file
actually is. It `require`s the host's *published file* directly instead
(same read path a real `mergeConfigFrom()` would take), compares against a
fresh `require` of the package's own file, and fails only when a
*published* file exists and names an old (or absent) version — a host with
nothing published is unaffected, since `config()` already reads the
package's file directly in that case. Two tests (old version fails, current
version passes), both writing and cleaning up a real file at
`config_path('numerosis.php')`.

**Applied to thin-app immediately**, not left for a future session: its new
minimal `config/numerosis.php` (written under Phase 6's thin-app trim,
same session, before this check existed) failed
`verifyConfigSchemaVersion()` the first time it was checked — confirmed the
check fires against a real host, not just tests — fixed by adding
`'schema_version' => 1` now that the file's contents are confirmed current
against the package. Re-verified clean: `numerosis:install --verify-only`
passes with only the expected `app.css`-diverged warning; `/`, `/login`,
`/admin` smoke test unchanged.

**Verified**: full suite **543 passed** (519 + 24 new tests), same known
failure, same 7 skipped; PHPStan 10 errors, 0 new (two real ones surfaced
and fixed along the way — `config('tenancy.migration_parameters')['--path'][]
= ...` on a `mixed`-typed nested offset, fixed by extracting and
re-assigning the `--path` array explicitly rather than mutating through the
untyped access); Pint clean.

better-dx.md's Phase 6 and post-extraction-review.md's Phase 4 are both now
complete except the one dropped metric (`tests/TestCase.php`'s line count,
accepted as a Testbench-only limitation, not a numerosis defect).

### Verification commands, next session

```
# numerosis
php -d memory_limit=1G vendor/bin/pest --compact   # expect 543 passed, 1 known-baseline failure (RegisterTenantTest, livewire.js), 7 skipped
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress   # expect 10 baseline errors, 0 new
vendor/bin/pint --dirty --format agent

# thin-app (after the vite.config.js fix above)
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan config:clear
```

Baseline any surprising failure against `git stash` before assuming it's a
regression — established convention this session, see
`.claude/rules/testing.md` / `static-analysis.md`.

## Context

`docs/host-requirements.md` documents **~30 rows across 10 config files** a
host must own, each paired with a `verify*()` method in
`InstallNumerosisCommand`. The doc is excellent as a *record of pain* — every
row names the misleading error a host would otherwise get — but its existence
is the problem: a package needing 30 hand-written config rows before it boots
is expensive to adopt, and every row is a place two copies can drift.

Three pieces of evidence that the current shape is not working:

1. **thin-app carries a 665-line published copy of `config/numerosis.php`
   that differs from the package original in exactly two places** (one
   commented-out feature, the module catalogue). 663 lines of duplicated
   defaults for 2 real decisions — and `mergeConfigFrom()` merges one level
   deep, so every package key added after that publish is silently absent.
   `verifyDomainConfig()` exists solely to catch that.

2. **The package still invents keys inside framework config files** —
   `auth.defaults.guards.context.*`, `auth.social.providers`,
   `auth.social.routes.*`, `auth.verification.expire`, and
   `permission.filament.*` (that last one **undocumented and unverified**,
   found by grepping `src/` for config reads). The doc's own `config/app.php`
   section explains at length why this is a mistake — "keys placed in
   `config/app.php` can have no default at all" — then the same mistake sits
   unfixed one section above it, in `config/auth.php`.

3. **`tests/TestCase.php` hand-sets ~290 lines of host configuration.** The
   package's own harness is a host, and it needs 290 lines to become one.
   That number is the honest measure of adoption cost.

The mechanism for fixing this already exists and is proven here:
`NumerosisServiceProvider::packageRegistered()` already supplies
`filesystems.disks.livewire`, `livewire.temporary_file_upload.disk` and
`livewire.component_namespaces` when the host has not, with
`NumerosisServiceProviderDefaultsTest` guarding the fallbacks. That pattern —
**normalize at register time, verify only that the host has not broken it** —
generalises to nearly every remaining row.

**Outcome:** a host that sets `APP_URL`, database credentials and Stripe keys,
runs `migrate` + `filament:assets`, and writes a one-line `bootstrap/app.php`
gets a working multi-tenant SaaS. Everything else becomes an override.

### What survives as a genuine host obligation

| # | Requirement | Why irreducible |
|---|---|---|
| 1 | `APP_URL` correct | every domain value derives from it |
| 2 | `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` | secrets; cannot be defaulted |
| 3 | DB credentials + `php artisan migrate` | MySQL — tenancy needs `CREATE DATABASE` |
| 4 | one-line `bootstrap/app.php` | `withRouting()` runs at builder time, before any provider |
| 5 | `php artisan filament:assets` | already required for Filament's own core CSS |
| 6 | Wildcard DNS + a worker on the `provisioning` queue | infrastructure |

Plus one conditional: `NUMEROSIS_APEX_DOMAIN` when served at the apex of a
multi-part public suffix (`example.co.uk`) — the documented limit of
`Domains::apexFromAppUrl()`'s label-count heuristic.

### Re-evaluation against the latest commits

- `8168df2` ("ship the Filament theme prebuilt, drop the host vite step") and
  `thin-app@8713d74` ("check in filament:assets output") **prove the
  distribution route Phase 3 depends on.**
  `public/css/nvade/numerosis/numerosis-filament-theme.css` already lands in a
  host via a command that host already runs. Phase 3 is now "do that again for
  the app JS/CSS", not a new mechanism.
- `be1915a` ("move host-installed deps into package require") already removed
  the dependency-list class of requirement — thin-app's `require` went 24 → 13.
  Nothing here re-litigates it.
- `.claude/plans/admin-panel-provider-polish.md` (**Not executed**, per the
  2026-08-10 audit) targets thin-app's `AdminPanelProvider.php` — a file Phase
  2 **deletes**. That plan is superseded, not abandoned: see Phase 2.

### Deviation from the answered questions, and why

The chosen option was "stop publishing config by default" *without* the deep
merge. Shipping only that is unsafe, and the minimal override file itself is
the proof: `'modules' => ['catalogue' => [...]]` in a host file replaces the
**whole** `modules` array under one-level merge, so `modules.plugins`
disappears and `Config::array('numerosis.modules.plugins')` throws
`InvalidArgumentException` — the same failure shape the doc records for
`auth.social.providers`. This plan therefore includes a recursive fill scoped
to `numerosis.*` only (Phase 1, ~6 lines inside a normalizer being built
anyway), rather than replacing spatie's `mergeConfigFrom` wholesale. The
alternative, if that is unwanted, is constraining the host override file to
top-level keys only.

---

## Phase 0 — Move package keys out of framework config files

No back-compat shims: thin-app is the only consumer and the package is
unpublished.

| Moves from | Moves to | Package default |
|---|---|---|
| `auth.defaults.guards.context.central` | `numerosis.auth.guards.central` | `'web'` |
| `auth.defaults.guards.context.tenant` | `numerosis.auth.guards.tenant` | `'tenant'` |
| `auth.social.providers` | `numerosis.social.providers` | the 5-provider metadata block currently duplicated in thin-app *and* `TestCase` |
| `auth.social.routes.{redirect,login}.name` | `numerosis.social.routes.*` | `'oauth'` / `'oauth.callback'` — the package owns those routes in `routes/auth.php`, so it can name them |
| `auth.verification.expire` | `numerosis.auth.verification_expire` | `60` |
| `permission.filament.*` | `numerosis.panels.access_control.*` | `'Access Control'` + labels |

Call sites: `src/Support/Social/ConfiguredProviders.php`,
`src/Features/Auth/EmailVerificationFeature.php`,
`src/Notifications/Auth/VerifyEmail.php`,
`src/Filament/{Admin,App,TenantAdmin}/**/Resources/{Roles,Permissions}/*.php`,
`src/Filament/NumerosisAdminPlugin.php` + `NumerosisTenantPlugin.php`
(`->authGuard(...)`), and everything
`grep -rn "auth.defaults.guards.context" src/ tests/` finds.

Keep reading through `Config::string()`/`array()` per
`.claude/rules/static-analysis.md` — the change is that the key now has a
package default, so "missing" is unreachable.

Also in this pass, unrelated but adjacent to host footprint: move
`rector/rector` and `driftingly/rector-laravel` from `require` to
`require-dev`. Every consumer currently installs a refactoring tool into
production.

## Phase 1 — `HostConfig`: one boot-time normalization layer

New `src/Support/HostConfig.php`, called first thing in
`NumerosisServiceProvider::packageRegistered()`. Same phase as the three
existing Livewire/filesystem defaults, for the reason that method's docblock
already records (Livewire reads its config eagerly in `boot()`).
`config:cache` captures provider-set values, because `ConfigCacheCommand`
bootstraps a fresh app and dumps `$app['config']->all()` after providers run.

Every entry follows one rule: **set only when the host has not, or when the
host still holds a stale upstream default.** Each records what it changed into
`HostConfig::applied()`, so `numerosis:install` can print "configured 14 keys
for you" rather than leaving a host guessing what is live.

```
tenancy.tenant_model / domain_model / central_user_model / tenant_user_model
    ← Numerosis::model(...) when still stancl's stock class
tenancy.central_domains          ← [Domains::hostFromAppUrl()] when empty
tenancy.bootstrappers            ← append SpatiePermissions + AuthGuard bootstrappers
tenancy.migration_parameters     ← append Numerosis::tenantMigrationPath(), force --realpath
tenancy.seeder_parameters        ← package tenant seeder when unset
tenancy.filesystem.disks         ← strip 'livewire' if present
tenancy.filesystem.root_override.local
    ← '%storage_path%/app/private/' when still stancl's stale '%storage_path%/app/'
tenancy.database.central_connection ← 'central'
database.connections.central     ← clone of database.connections.{database.default} when absent
database.connections.*.options   ← mirror innodb_lock_wait_timeout when only lock_wait_timeout set
session.domain                   ← '.'.numerosis.domains.apex when null
queue.failed.database            ← central connection when it currently names database.default
auth.guards.tenant               ← session guard over the tenant provider, when absent
auth.providers.tenant            ← eloquent + Numerosis::model(Tenant\User::class), when absent
auth.providers.users.model       ← Numerosis::model(CentralUser::class) when the configured
                                   model does not implement Contracts\Auth\CentralUserModel
auth.passwords.{defaults.passwords} ← broker over the central provider, when absent
numerosis.*                      ← array_replace_recursive(package defaults, host file)
```

Two entries need their reasoning in the code, because both are correctness
fixes rather than conveniences: `queue.failed.database` (per
`.claude/rules/exception-handling.md`, gated by `QUEUE_FAILED_DRIVER` not
`queue.default`, and must not name a connection that moves under tenancy) and
`tenancy.filesystem.root_override.local` (per
`.claude/rules/tenant-filesystem.md`, stancl's stub predates Laravel 11's
private-by-default local disk).

Idempotent by construction — every entry is "set if absent/stale" — which
matters because the provider re-runs against an already-normalized cached
config.

## Phase 2 — Bootstrap and Filament panels

**`Numerosis::configure(?string $basePath = null): ApplicationBuilder`** wraps
`Application::configure($basePath)` and applies `withRouting(using:
self::routes(...))`, `withMiddleware(self::middleware(...))` and
`withExceptions(self::exceptions(...))`, returning the builder so a host can
chain and call `->create()`. `bootstrap/app.php` becomes one line.

Safety nets in the provider, for a host not using the factory — this is the
three-stacked-bug failure `.claude/rules/package-host-bootstrap.md` records,
where each fix only exposed the next:

- `Numerosis::routes()` sets a static "registered" flag; if still false at
  `booted()`, the provider calls it. Provider-registered routes are captured
  by `route:cache` (`RouteCacheCommand` boots a fresh app and reads
  `$router->getRoutes()`), so this costs nothing.
- If `app(HttpKernel::class)->getMiddlewareGroups() === []`, the host never
  called `withMiddleware()` — apply `(new Middleware)`'s baseline groups, then
  the package's own. Verified against `ApplicationBuilder::withMiddleware()`:
  that `afterResolving(HttpKernel::class, …)` hook is the *only* thing that
  seeds Laravel's `web`/`api` groups. Without it `Route::middleware('web')`
  dies with `BindingResolutionException: Target class [web] does not exist`.

**Panels move into the package.** New
`src/Providers/Filament/NumerosisAdminPanelProvider.php` and
`NumerosisTenantPanelProvider.php` — thin `PanelProvider` subclasses applying
the existing `NumerosisAdminPlugin`/`NumerosisTenantPlugin`, gated by the
existing `shouldRegisterPanel()`. Register them from
`$this->app->booting(fn () => $this->app->register(...))`, **not** directly in
`packageRegistered()`: `Filament::registerPanel()` resolves `PanelRegistry`
from the container, and registering ahead of Filament's own provider risks
binding a different instance. `booting()` runs after every provider has
registered.

New config, all defaulted:

```php
'panels' => [
    'default' => 'admin',              // 'admin' | 'tenant' | null
    'admin'   => ['provider' => null], // a host class replaces ours entirely
    'tenant'  => ['provider' => null],
],
```

thin-app then deletes `app/Providers/Filament/{Admin,TenantAdmin}PanelProvider.php`
and both `bootstrap/providers.php` entries, plus
`AppServiceProvider::register()`'s `isCentralDomain` macro — the package has
registered its own guarded copy since `registerRequestMacros()`, and the
host's copy wins by registration order for no reason.

**Supersedes `admin-panel-provider-polish.md`.** Its Phases 1–4 (`->spa()`,
expanded `navigationGroups()`, `->databaseNotifications()`, branding,
`ActivityLogPlugin`, widget ordering) were written against the host provider
this phase deletes. They are still wanted — they land on
`NumerosisAdminPlugin` instead, where every host gets them. Mark that plan
superseded and move its checklist into a follow-up, rather than executing it
against a file that is about to disappear.

## Phase 3 — Prebuilt frontend assets

Today a host publishes `resources/{css,js}` into `resource_path()`, adds two
entries to `vite.config.js`, and lives with a drift warning on
payment-critical JS. Two latent bugs sit underneath: `central.js`/`tenant.js`
inline `import.meta.env.VITE_REVERB_*` at **build** time (so changing a Reverb
host means rebuilding package-owned JS), and the published `app.css` scans
`../**/*.blade.php` — the **host's** views — so Tailwind classes used only by
package views are generated only if a compiled copy happens to sit in
`storage/framework/views`.

- **Runtime config, not build-time env.** New
  `resources/views/partials/script-config.blade.php` emits
  `window.Numerosis = { reverb: {...}, tenantId: …, stripeKey: … }` from PHP
  config. `central.js`/`tenant.js` read that instead of `import.meta.env.*`;
  `tenant.js` also drops its `meta[name="tenant-id"]` lookup. This is what
  makes a prebuilt bundle possible at all.
- **Maintainer build.** `package.json` gains a Vite lib build producing
  `dist/numerosis.js` (bundling `@laravel/echo-vue`, `@stripe/stripe-js`,
  `axios` and the two Stripe modules; Alpine and Livewire stay global) and
  `dist/numerosis.css` (Tailwind from a new maintainer-only
  `resources/theme-src/app.css` that `@source`s the package's own
  `resources/views/**`). Same shape and same checked-in treatment as the
  existing `build:filament-theme` → `dist/filament-theme.css`.
- **Distribution rides a command the host already runs.** Register both files
  via `FilamentAsset::register([Js::make(…), Css::make(…)], package:
  'nvade/numerosis')` next to the existing `Theme::make()` call, so
  `php artisan filament:assets` copies them to
  `public/{js,css}/nvade/numerosis/` — exactly what `thin-app@8713d74` already
  checks in for the theme. No new host step, no `vendor:publish`, no
  `vite.config.js` edit.
- `resources/views/partials/styles.blade.php` resolves through a new
  `Numerosis::assetTags()`: prefer a Vite manifest entry when the host has
  published sources and built them, else the static copy. Publishing
  `numerosis-assets` stays the customisation escape hatch.
- thin-app's `vite.config.js` `input` drops to host-authored entries only
  (`app.css`, `app.js`); `resources/js/{central,tenant,stripe-*}.js` and the
  published CSS copies are deleted.

Trade-off to document: the package stylesheet carries its own Tailwind
preflight, so a host that also builds Tailwind emits preflight twice
(harmless, ordering-stable). A host wanting one copy imports
`tailwindcss/utilities` only in its own CSS.

## Phase 4 — Models by convention

`Numerosis::model()` gains a memoized convention step between config and the
package class:

```
config('numerosis.models.<FQCN>')                       (explicit override, still wins)
  ?? App\Models\<suffix>   when class_exists && is_subclass_of(<FQCN>)
  ?? <FQCN>
```

`is_subclass_of` is what makes this safe, and it is exactly the check
`verifyModelOverrides()` already performs — the difference is the host no
longer has to *also* name the class in `.env`. Deletes 9 env keys,
`appendModelOverrides()`, and the phpdotenv single-quote trap (a
double-quoted class-string stops the whole app booting, with an error naming
neither key nor package). Memoize in a static map; `getNamespace()` is not
free.

## Phase 5 — Seeding

- Bind `Database\Seeders\DatabaseSeeder` → the package's own seeder when the
  host class does not exist, so `php artisan db:seed` reaches package data on
  a fresh host with no file edit. A host writing its own file keeps winning
  (`class_exists` check).
- `numerosis:install` seeds by default; `--no-seed` opts out. Keep resolving
  the seeder from the container, never through `Artisan::call('db:seed')` —
  `.claude/rules/testing.md` records both instances of that collision.

## Phase 6 — Install command, docs, and the two hosts

- `InstallNumerosisCommand` splits into **what it configured** (print
  `HostConfig::applied()`) and **what is still wrong**. Most `verify*()`
  methods delete or narrow to "has the host broken what we set" — the framing
  `verifyLivewireUploadDisk()` already uses. Survivors: Stripe keys,
  `filament:assets` output present, central data seeded, model overrides
  resolve to real subclasses, tenant-migration path. This also closes
  `post-extraction-review.md` Phase 4.
- Rewrite `docs/host-requirements.md` as two short lists: the 6 obligations
  above, and "what the package configures for you, and how to override it".
  `HostRequirementsTest`'s doc↔command parity test stays and keeps working —
  surviving rows still carry a `Checked by` cell; new normalizations are
  covered by `HostConfigTest` instead.
- **thin-app**: `config/numerosis.php` 665 → ~30 lines (features minus
  Turnstile, module catalogue + plugins); delete or trim `config/tenancy.php`,
  `config/auth.php`, `config/session.php`, `config/queue.php`, and
  `config/permission.php`'s `filament` block; one-line `bootstrap/app.php`;
  `bootstrap/providers.php` down to `AppServiceProvider` (+ Telescope,
  Socialite); delete both Filament panel providers, the `isCentralDomain`
  macro, `resources/{css,js}` package copies, and the corresponding
  `vite.config.js` inputs.
- **`tests/TestCase.php`**: delete every `Config::set()` the package now
  supplies. Target ~40 lines (DB credentials, test hostnames, array cache/
  session/mail drivers, Stripe dummies) down from ~290 — that delta is this
  plan's acceptance metric.
- Update `.claude/rules/package-host-bootstrap.md` (bullets 2 and 3 describe
  requirements this removes — rewrite rather than delete, so the reasoning
  survives) and `.claude/rules/auth-guards.md`'s references to
  `auth.defaults.guards.context.*`.

---

## Verification

1. **Fresh-host acceptance test** — new `tests/Feature/FreshHostTest.php`: a
   Testbench app configured with *nothing but* DB credentials and `APP_URL`.
   Asserts it boots, `/login` renders 200, `tenancy.bootstrappers` carries
   both package bootstrappers, `auth.guards.tenant` resolves, and a tenant
   provisions end to end. This is the test that would have caught the whole
   class of problem.
2. **`HostConfigTest`** — per normalization: unset → default applied;
   host-set → untouched; already-normalized → idempotent (re-running
   `packageRegistered()` changes nothing). Extends the existing
   `NumerosisServiceProviderDefaultsTest` pattern and its `rebootPackage()`
   helper.
3. `composer test` — full suite green against the shrunken `TestCase`. Per
   `.claude/rules/testing.md`, baseline any suspicious failure against
   `git stash` first; the known ~9 `SQLSTATE 1205` lock-wait failures are
   pre-existing, not regressions.
4. `composer analyse` — PHPStan level 9; new keys read through
   `Config::string()`/`array()`.
5. **thin-app, by hand** — after trimming: `numerosis:install` reports 0
   problems and lists what it configured; `migrate --seed`; `filament:assets`;
   then `/`, `/login`, `/admin`, a tenant subdomain, one Livewire file upload
   (the tenant-suffixed-disk trap) and one checkout render (the prebuilt
   Stripe JS).
6. **Config-cache check** — `php artisan config:cache`, then re-run the smoke
   URLs. Provider-set config must survive the cache; this is the one step
   where a normalization running too late would show up.
7. **Durable guard** — `post-extraction-review.md` Phase 5 (thin-app gets Pest
   + CI, currently has neither) is what stops host requirements re-growing
   after this lands. A single CI job that boots thin-app and hits the smoke
   URLs from step 5 turns every future re-introduced requirement into a red
   build instead of a discovery. Worth pulling forward into this work rather
   than leaving it in that plan.
