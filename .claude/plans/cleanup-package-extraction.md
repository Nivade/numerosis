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
| Session | 2026-08-06 (second session that day) |
| numerosis | `1d82f8f`, clean. Suite **0 failed / 387 passed / 7 skipped** (~62s). PHPStan clean, baseline **shrank by 2**, grew by 0 |
| thin-app | `572a501`, clean. `numerosis:install --verify-only` clean; 48 `filament.*` routes register; `schedule:list` shows 3 entries; central DB seeded (perms=99, plans=3) |
| saas-m | frozen, untouched |
| Done this session | **B** (schedule), **D** (seeders), **C** (domain config), **A** (Filament plugins) — see "Completed" below |
| Next | **E/F/H** (task 5), then **G/I** (task 6, half of I already landed), then doc corrections (task 7) |

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

---

## Remaining work

### E/F/H — the publish-duplication family

Three instances of the same shape: the package publishes files into the host,
nothing detects drift afterwards, and the default is the duplicating path.

**E — tenant migrations.** 25 files, byte-identical in both repos (`diff -rq`
clean). `NumerosisServiceProvider` publishes them under
`numerosis-tenant-migrations`, and thin-app's
`config/tenancy.php`'s `migration_parameters` points at
`database_path('migrations/tenant')`. Extraction plan 5.1 and
`docs/host-requirements.md` both say it should be the **absolute vendor path**,
with publishing as the opt-in customisation escape hatch.

- Add `Numerosis::tenantMigrationPath(): string` (`__DIR__`-relative, same
  reasoning as the plugin discovery paths) so a host writes
  `'--path' => [Numerosis::tenantMigrationPath()]` and cannot get it wrong.
- Repoint thin-app's `config/tenancy.php` at it; delete
  `thin-app/database/migrations/tenant/` (25 files).
- Rework `verifyTenantMigrationPath()` accordingly — it currently checks the
  published directory exists, which stops being the right question.
- **Separate finding, decide before deleting:** those 25 include
  `2025_05_26_101655_create_clients_table.php` plus three follow-ups for the
  **removed clients module**. The package is shipping a dead module's schema to
  every tenant. Do not silently drop them — a deployed tenant database already
  has those tables, so removing the migrations makes the `migrations` table
  disagree with reality. Ask the user; the options are leave-as-is, or add a
  drop migration and remove the four.

Done when: a fresh `numerosis:install` on a host with no
`database/migrations/tenant/` still provisions a tenant successfully, and
`diff -rq` finds no duplicated migration set.

**F — `resources/{css,js}`.** Byte-identical in both repos. Publishing to
`resource_path()` is deliberate and correctly reasoned (see
`NumerosisServiceProvider`'s comment: `central.js` imports `stripe-*.js` by
relative path, and `partials/styles.blade.php` `@vite`s the host's own
`resources/js` root), so this one **stays a publish** — but nothing detects
drift after it, and the three `stripe-*.js` files are load-bearing for payment.

- Add a `numerosis:install` check comparing each published file against the
  package original, warning (not failing) on divergence — a host is allowed to
  customise, it just should be told.
- thin-app's `vite.config.js` still lists `refresh: ['app/Filament/**']`, a
  directory that no longer exists there. Point it at the package's
  `src/Filament` or drop the entry.

**H — `config/livewire.php` + the livewire disk.** `docs/host-requirements.md`
line 88 is the longest row in the document and its fix is a path into
`vendor/`. The package already sets `numerosis.views.path` to `__DIR__` at boot
for exactly this reason.

- Set `livewire.component_namespaces.{layouts,pages}` in
  `NumerosisServiceProvider::packageBooted()`, host-overridable (only set what
  is not already set).
- Same for `filesystems.disks.livewire` and
  `livewire.temporary_file_upload.disk` — the package knows the correct value
  (`storage_path('app/private')`, absent from `tenancy.filesystem.disks`); the
  host only needs the ability to override. See
  `.claude/rules/tenant-filesystem.md` for why this disk must exist and must
  **not** be tenant-suffixed.
- Then `verifyLivewireComponentNamespaces()`, `verifyLivewireUploadDisk()` and
  `verifyLivewireDiskExclusion()` change from "is the host wired correctly" to
  "has the host broken what we set" — keep them, reword the failure text.

Done when: the three rows in `docs/host-requirements.md` say "package-supplied,
override if needed", and a host that deletes all three keys still boots.
`HostRequirementsTest` enforces the doc/command pairing in both directions, so
it will fail if a check is removed without its row.

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
