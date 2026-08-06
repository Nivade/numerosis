# Plan: finish pulling host setup into the package

**Companion to `.claude/plans/package-extraction.md`, not a replacement.** That
file is the extraction itself and stays canonical for phases 0-10. This one
tracks a specific follow-up: a review on 2026-08-06 found that several things a
consumer had to wire by hand belonged in the package, and two of them were live
bugs. Items A-D are done and committed; E-I and the doc corrections are not.

**Audience: an executing agent with no prior context.** Read the Live status
block, then "Remaining work". Each remaining item states what to change, why,
and what "done" looks like.

---

## Live status

Overwrite this block; never append.

| | |
|---|---|
| Session | 2026-08-06 (third session that day) |
| numerosis | `67acfb1`. **Working tree not clean** — a concurrent session is mid-flight on an unrelated `#[UsePolicy]`/Filament refactor (new `src/Policies/*`, modified Filament resources/Models, `.claude/rules/auth-guards.md`); none of it is this plan's work, left untouched and unstaged. Suite **0 failed / 392 passed / 7 skipped** (~66s) as of this plan's own commits. PHPStan clean |
| thin-app | `e40c485`, clean apart from untracked `public/{css,js,fonts}` build output. `numerosis:install --verify-only` clean; real `/login` request confirmed 200 with no config errors |
| saas-m | frozen, untouched |
| Done this session | **E** (tenant migrations point at vendor path, dead clients-module migrations removed), **F** (asset publish drift detection), **H** (Livewire config defaults — had to land in `packageRegistered()`, not `packageBooted()`, per real-request bug found and fixed this session) — see "Completed" below |
| Next | **G/I** (task 6, half of I already landed), then doc corrections (task 7) |

Prerequisites for running anything: `cd ~/repos/private/numerosis && docker
compose up -d`, then `vendor/bin/pest --ci`, `composer analyse`,
`vendor/bin/pint --dirty --format agent`. thin-app uses `vendor/bin/sail`.
**Never run bare `vendor/bin/pint` in numerosis** — see "Traps" below.

---

## Completed (do not redo)

### A — Filament panels are plugins (`numerosis@1d82f8f`, `thin-app@572a501`)

Phase 8 of the extraction plan was recorded as done but had never been done as
designed: no `Filament\Contracts\Plugin` class existed. 545 lines of panel
definition were copied across thin-app (333) and numerosis's Workbench harness
(212), and had already drifted — the harness lacked `->registration()`,
`->profile()`, `->persistentMiddleware(['universal'])`, `->domains()` and the
navigation groups, and the two resolved the package source directory two
different ways.

Now `src/Filament/NumerosisAdminPlugin.php` and `NumerosisTenantPlugin.php`.
Both expose a static `shouldRegisterPanel()` because a Plugin configures a
panel but cannot decide whether one exists. Discovery paths are `__DIR__`
-relative — `InstalledVersions::getInstallPath()` is wrong when the package is
the root project, and `dirname(__DIR__, N)` is wrong the moment a file moves.
Host providers are ~45 lines and own only `->colors()` and `->default()`.

`request()->isCentralDomain()` moved into the package as a macro delegating to
`Numerosis::isCentralDomain()`; package code calls the method, since a macro is
invisible to PHPStan at level 9.

### B — package schedule never ran (`numerosis@273a5e5`)

`routes/console.php` held three cron entries and **nothing loaded it**: a host
passes its own `routes/console.php` to `withRouting(commands: ...)`, which
takes one path. Verified against thin-app — `schedule:list` said "No scheduled
tasks have been defined". `tenancy:prune-stalled-provisions` is the only
sweeper for abandoned `reserved` rows. Now registered via
`callAfterResolving(Schedule::class)`; the routes file is deleted.

### C — domain keys out of `config/app.php` (`numerosis@545d03e`, `thin-app@9a4437e`)

`app.domain` / `app.host` / `app.central.*` were package keys in a framework
file, so no package default was possible, and they had drifted:
`Domain::getUrl()` read `app.host` (from `DOMAIN_NAME.DOMAIN_EXTENSION`) while
`CreateTenantDomain` read `app.domain` (from `DOMAIN`). Now
`numerosis.domains.{apex,central,tenant_pattern}`, defaulted off `APP_URL` by
`src/Support/Domains.php`. `verifyAppDomain()`/`verifyCentralDefaultDomain()`
collapsed into `verifyDomainConfig()`.

### D — package seeders unreachable (`numerosis@4fba87f`, `thin-app@d74c247`)

`db:seed` runs the *host's* `DatabaseSeeder`. thin-app's central DB measured
at **zero permissions, zero payment plans** while every install check passed.
Added `numerosis:install --seed` and `verifyCentralDataSeeded()`.
`PaymentPlanSeeder` was rewritten: it was not re-runnable and built plans
through a **faker factory**, so every seeded plan had a random slug —
unaddressable via `findBySlug()`, the choke point every checkout path shares.

Also fixed en route: `Feature` and `PaymentPlanFeature` were the only
`Models\Central\*` without stancl's `CentralConnection`, despite `features`
existing solely in `database/migrations/central`.

### E — tenant migrations pointed at a duplicated copy (`numerosis@49ef87a`, `thin-app@400a58f`)

25 files were byte-identical in both repos. `Numerosis::tenantMigrationPath()`
(`__DIR__`-relative, same reasoning as the Filament plugin discovery paths) is
now the canonical `--path` a host wires into
`config('tenancy.migration_parameters')`; thin-app's copy under
`database/migrations/tenant/` is deleted (25 files). Publishing
`numerosis-tenant-migrations` remains available as the opt-in customisation
escape hatch — no longer auto-published by `numerosis:install`.
`verifyTenantMigrationPath()` now checks the vendor path is present in
`--path`, not merely that some directory exists.

User decided (asked directly, no real deployed tenants at stake): the four
dead clients-module migrations
(`2025_05_26_101655_create_clients_table.php` + 3 follow-ups) are deleted
outright, no drop migration. Verified by provisioning a real tenant in
thin-app via tinker after the change (41 tables, no local migrations
directory needed).

### F — asset publish drift detection (`numerosis@fedeca4`, `thin-app@51f133a`)

`resources/{css,js}` stays a deliberate publish (`central.js` imports the
`stripe-*.js` files by relative path, `styles.blade.php` `@vite`s the host's
own `resources/js` root — nesting under a package subdirectory breaks both),
but nothing detected drift in the published copy. `Numerosis::assetSourcePaths()`
is now the single source/target map both `publishGroup()` and the new
`verifyPublishedAssetsMatchSource()` read, so a host's customisation and the
package original can't silently drift apart from each other unnoticed — the
check warns, does not fail the install. thin-app's `vite.config.js` no longer
lists the dead `app/Filament/**` refresh glob (panels moved to the package's
Filament plugins in phase A); points at
`vendor/nvade/numerosis/src/Filament/**/*.php` instead.

### H — Livewire config defaults (`numerosis@67acfb1`, `thin-app@e40c485`)

`NumerosisServiceProvider::packageRegistered()` now sets
`livewire.component_namespaces.{layouts,pages}`, the `livewire` filesystem
disk, and `livewire.temporary_file_upload.disk` whenever a host hasn't
already — thin-app's three hand-wired copies are deleted.
`verifyLivewireComponentNamespaces()`/`verifyLivewireUploadDisk()` are
reworded to "has the host broken what we set", not "did the host wire this
up". `docs/host-requirements.md`'s three rows now say "package-supplied,
override if needed"; a new `resources/css`, `resources/js` doc section covers
F's check.

**Load-bearing correction made mid-session, worth carrying forward:** the
defaults were first written into `packageBooted()`, matching the sibling
`numerosis.views.path` default already there — and passed the package's own
suite, because `TestCase::getEnvironmentSetUp()` always pre-sets these three
keys, so the fallback path was never exercised. Only a real request against
thin-app (`GET /login`) surfaced the actual bug:
`LivewireServiceProvider::boot()` reads `component_namespaces` eagerly to
register a Blade view-finder hint, and every provider's `register()` phase
completes before any provider's `boot()` runs — so `packageBooted()` is one
phase too late regardless of provider discovery order. Fixed by moving the
three `Config::set()` blocks into `packageRegistered()`.
`NumerosisServiceProviderDefaultsTest` now resets each key and re-invokes
`packageRegistered()` directly (not `packageBooted()`) so this class of gap
is caught by the suite going forward — **but this is exactly the kind of bug
"0 failed" cannot see; the real-request check is what actually caught it.**
Same lesson `testing.md` already records for `db:seed`/Testbench provider
ordering — provider *phase*, not just provider *presence*, is a thing a
green suite can silently get wrong.

---

## Remaining work

### G/I — exceptions seam, and the rest of the modules config

**G — `Numerosis::exceptions(Exceptions $exceptions)`.** thin-app's
`bootstrap/app.php` still hand-carries the package's own exception context:
`$exceptions->context()` returning `tenant_id` / `guard` / `user_global_id`,
plus `dontReportDuplicates()` and `throttle(fn () => Limit::perMinute(30))`.
That closure is documented package behaviour
(`.claude/rules/exception-handling.md`), and every consumer would have to copy
it. Add the method alongside the existing `middleware()`/`routes()`/
`broadcasting()`/`csrfExceptions()` seam and have thin-app call it. Leave
`Integration::handles($exceptions)` with the host — Sentry is a `suggest`.

Note while there: that closure reads `tenancy()->initialized` at **report**
time, which the rules file records as wrong for a job that failed inside
`$tenant->run()`. Do not try to fix that here; `TagsSentryScopeWithTenant` is
the existing answer.

**I — modules config.** Half done: `numerosis.modules.{catalogue,plugins}`
exists with empty defaults, `ModuleOfferingSeeder` and `NumerosisTenantPlugin`
read it, and thin-app's `config/modules.php` is deleted with its content moved
into its published `config/numerosis.php`. Remaining: document both keys in
`docs/host-requirements.md` (they are host *data*, so they may not need a
`verify*()` — if not, the "Checked by" cell must be a dash **with a reason**,
which `HostRequirementsTest` enforces).

### Doc corrections from the review

1. **`package-extraction.md`'s Live status and Phase 8/10 rows are wrong.**
   Phase 8 is recorded as done; it was not done as designed (see A above) —
   that is now true, but the plan should say so rather than implying it was
   always the case. Phase 10 gate item 3 was run via `StartLocalCheckout::run()`
   in tinker and the fixture deleted, so it is not reproducible; R10 already
   asked for it to be a Pest browser test in thin-app. Either write that test
   or record explicitly that the gate item is manual and must be re-run.
2. **`docs/host-requirements.md` rows 19-22 still describe abstract models**
   ("extends the package's abstract base"), contradicting D8, which made them
   concrete. Same stale claim in `NumerosisServiceProvider`'s model-stub
   publish comment (search for `4.4's "abstract base in the package"`).
3. **Dead leftovers in thin-app**, all safe to delete, none urgent:
   `config/caching.php` (nothing in `src/` reads `caching.*` — `CacheKeys`
   moved to `numerosis.cache.prefix`), `routes/web.php` + `welcome.blade.php`
   (`withRouting(using: ...)` replaces default route registration wholesale, so
   both are unreachable), and `tenancy.seeder_parameters` (dead since
   `SeedTenantDatabase` stopped going through `tenants:seed`).
4. **Record the new learnings in `.claude/rules/`** via the
   `codebase-learnings` skill. At minimum: the `db:seed`-resolves-to-stancl
   trap now has a *second* instance (it bit `numerosis:install` and Testbench's
   `$this->seed()`), and the `Feature`/`PaymentPlanFeature` missing
   `CentralConnection` finding belongs in `testing.md`'s cross-connection
   deadlock bullet.

---

## Traps hit this session (they will recur)

- **`vendor/bin/pint` without `--dirty` rewrites ~160 unrelated files in
  numerosis.** The committed tree is not pint-clean under the current pint
  version. Always `vendor/bin/pint --dirty --format agent`. If a bare run
  happens, `git stash push -- <every file you did not touch>` then
  `git stash drop`.
- **PHPStan needs 1G.** Use `composer analyse` (which bakes in
  `-d memory_limit=1G`); a bare `vendor/bin/phpstan analyse` dies at 128M with
  a `FatalError` inside `build/phpstan/resultCache.php`, which reads like a
  code error.
- **`db:seed` is not Laravel's command.** With stancl/tenancy installed, that
  name can resolve to `Stancl\Tenancy\Commands\Seed`, whose `handle()` calls
  `$this->option('tenants')` — an option its shadowed constructor never
  registered — and throws `InvalidArgumentException: The "tenants" option does
  not exist`. Resolve the seeder from the container instead
  (`Model::unguarded()` + `setContainer(app())` + `__invoke()`), the way
  `SeedTenantDatabase` and `InstallNumerosisCommand::seedCentralData()` do.
  Testbench's `$this->seed()` goes through `artisan('db:seed')` and has the
  same problem. **It did *not* reproduce in thin-app** — the collision resolves
  by registration order, which differs between Testbench and a real app, so
  "it works in the host" proves nothing.
- **`Config::array()` throws on a missing key**, it does not return `[]`. Any
  new `Config::array('numerosis.x.y')` needs the key to exist in
  `config/numerosis.php`, or every consumer 500s.
- **`HostRequirementsTest` will fail** if a `verify*()` method is added or
  removed without the matching row in `docs/host-requirements.md`. That is the
  point; add the row.
- **A host holding a published `config/numerosis.php` from an older version
  silently loses new keys** — `mergeConfigFrom()` merges one level deep, so an
  older `domains`/`modules` array wins wholesale. thin-app was repaired by
  re-copying the package default (it had no local edits). Any future config
  change needs the same check.

---

## Method that worked, keep using it

- **Verify by mutation, not by going green.** Every regression test this
  session was confirmed to fail against the pre-fix code before being trusted
  (the schedule test: 2 of 4 assertions fail with the registration removed).
- **Verify in thin-app, not only in the package suite.** Both live bugs (B and
  D) were invisible to a green package suite and obvious from one command in
  the host — `schedule:list`, and a `count()` on `permissions`. Run the
  equivalent check in thin-app for every remaining item.
- **Commit at every green point, both repos.** Never end with an uncommitted
  tree in either.
