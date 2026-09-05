# Plan: post-extraction review — what the extraction left behind

> ## Status correction, 2026-09-04 — read this instead of the Live status block
>
> Re-validated against the tree. **Phases 1, 2, 3, 5 and 6 are closed.** The
> Live status block below is 3+ weeks stale: it names a `docker compose up -d`
> prerequisite this repo does not have, `.claude/rules/` (moved to
> `.ai/rules/`), and `filament-tenancy.md` (deleted).
>
> Closed since it was written:
>
> - **4.1** shipped as `numerosis:install --verify-only`, not `--check`. Every
>   task below that names `--check` means `--verify-only`.
> - **4.3** shipped — `schema_version` is in `config/numerosis/schema-version.php`,
>   `config/stubs/numerosis.php`, and checked in `InstallNumerosisCommand:749`.
> - **5.3** is **moot, not done.** It was a test for
>   `NumerosisTenantPlugin::shouldRegisterPanel()`; `packages/filament` and both
>   panels were deleted 2026-09-03 (Phase 1 of `archive/humming-nibbling-flame.md`).
>   The `PHP_SAPI` root-cause writeup in the Live status block is still a good
>   read, but nothing it describes exists any more.
> - **5.4** shipped as `tests/Browser/RegistrationWizardTest.php`, minus its
>   "log into the tenant panel" leg, which went with the panels.
> - **5.5** shipped — `thin-app/.github/workflows/smoke-test.yml`, green on
>   GitHub Actions (see `archive/better-dx.md`).
> - **6.1** shipped — `CHANGELOG.md` and `UPGRADING.md` both exist.
> - **6.3** moot — saas-m is archived.
>
> **Genuinely still open, and still worth doing — 4.2, 4.4, 6.2.** Nothing
> below them has changed in a way that invalidates them:
>
> - **4.2** — failure-path tests for the ~20 untested `verify*()` methods. The
>   count has grown; `InstallNumerosisCommand::handle()` now runs 14+ named
>   verifications and most still have no test that corrupts their key.
> - **4.4** — fold `verifyPublishedAssetsMatchSource()`'s warning into
>   `--verify-only` so it is reachable outside an install run.
> - **6.2** — the second-consumer smoke test (`laravel new` → path repo →
>   `numerosis:install`, by documentation alone). thin-app cannot prove the
>   documented path works; it was configured by hand.
>
> Ignore the phase headers' own DONE markers where they conflict with this
> block. Everything else in the file is preserved as written.

**Third plan in the series, and the last one that should be needed.**
`archive/package-extraction.md` is the extraction itself (phases 0-10)
and stays canonical for its own history.
`archive/cleanup-package-extraction.md` closed the "things a consumer
had to wire by hand" follow-up (items A-I, all done). This file is a
post-execution review of both, written 2026-08-07 against the real tree, and
holds **only the remaining work** — nothing here is a restatement of
something already done.

**Audience: an executing agent with no prior context.** Read the status
correction above, then the phase you are on. Every task states goal, files,
reasoning, dependencies, and what "done" looks like. Tasks inside a phase are
independently completable unless a dependency is named.

---

## Live status

Overwrite this block; never append.

| | |
|---|---|
| Session | 2026-08-11 (Phase 5.1 + 5.2 done, this session) |
| numerosis | dirty: `phpunit.xml.dist` (thin-app group exclusion removed), 11 test files deleted (moved to thin-app), 2 now-empty test dirs pruned. Not yet committed. |
| thin-app | dirty: Pest installed (`composer.json`, `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` rewritten for real-host teardown), 11 test files added (adapted namespace `Nvade\Numerosis\Tests\*` → `Tests\*`, `#[Group('thin-app')]` stripped), `app/Models/Permission.php` + `app/Models/Role.php` added (real bug found: app-modules seeders `use App\Models\Permission`/`Role` directly, host never had these stubs — first real consumer catching a real gap, exactly Phase 5's stated purpose). Not yet committed. |
| saas-m | frozen, untouched. |
| Package suite | **550 passed / 7 skipped / 1 failed in 73.6s**, measured 2026-08-11 after moving the 11 files out. The 1 failure (`RegisterTenantTest` missing `livewire.js`) is the same pre-existing failure, unchanged. |
| thin-app suite | **46 passed**, 0 failed — Pest installed, all 11 moved tests green (44 of them; 2 more come from the rewritten `ExampleTest`). Measured 2026-08-11. |
| Root-caused, 2026-08-12 | Central-domain HTTP routes (e.g. `app.thinapp.dev/`) 404 when dispatched from Pest/tinker/artisan — **not a routing bug, not fixed, doesn't need fixing.** `NumerosisTenantPlugin::shouldRegisterPanel()` deliberately registers the tenant panel on a central-domain request whenever `app()->runningInConsole()` is true (so `route:list`/queue workers/tenant tests still see the panel from console), and `runningInConsole()` is `PHP_SAPI === 'cli'` — true for every console entrypoint, false for real HTTP (`php-fpm`, and even `php -S`, both report `cli-server`/`fpm-fcgi`). So a console process that then *fakes* an HTTP dispatch against the central domain still has the tenant wildcard registered, and it wins the match. Confirmed empirically: identical `Request`, dispatched through literal `public/index.php` code, resolves correctly under `php -S` and incorrectly under plain `php`/`artisan tinker` — same code, only `PHP_SAPI` differs. Full writeup in `.claude/rules/filament-tenancy.md`. **Consequence for 5.3**: its "central routes bound per `tenancy.central_domains`" assertion cannot be a plain Pest HTTP-dispatch test — that will always see the tenant panel and always resolve the wildcard, regardless of config. Write it as either a Pest **browser** test (real request through the actual server, where `runningInConsole()` is genuinely false) or a unit test against `shouldRegisterPanel()`'s decision logic directly (stub `runningInConsole()` false, assert `false` for a central-domain request). |
| Next | Phase 2 is now done (reverified 2026-08-11, all 5 items already resolved, no changes needed). What's left: Phase 5.3/5.4 (bootstrap-wiring tests, browser gate test). 5.3 is unblocked — root cause found, needs writing as a browser test or a `shouldRegisterPanel()` unit test, not a Pest HTTP-dispatch test. |

Prerequisites: `cd ~/repos/private/numerosis && docker compose up -d`, then
`vendor/bin/pest --ci`, `composer analyse`, `vendor/bin/pint --dirty --format
agent`. thin-app uses `vendor/bin/sail`. **Never run bare `vendor/bin/pint`
in numerosis** — see Traps.

---

## What the review found (the reasoning, not the work)

Full comparison lives in the session transcript; the load-bearing conclusions:

- **A-I and doc corrections 1-4 are genuinely done**, verified against code
  rather than against the plan's own checkboxes. Two carry residue: **G**
  (`Numerosis::exceptions()`) shipped with **zero tests**, and **doc
  correction 1** annotated Phase 10's gate item 3 as "manual" rather than
  automating it, so R10's browser test is still unwritten.
- **Phase 6.3 was never finished.** 11 test files carry `#[Group('thin-app')]`
  and are excluded in `phpunit.xml.dist`. The plan said "move them in Phase 7";
  Phase 7 created thin-app but never gave it a test runner. Those ~14 tests
  therefore execute in **neither** repo. Quarantine with no destination is
  deletion with extra steps.
- **Verification is install-day-only.** 24 `verify*()` methods exist;
  `InstallNumerosisCommandTest` covers ~4 of them. Nothing re-checks a host
  after install, which is exactly when config drifts.
- **One hardcoded vendor path survived E.** thin-app's `bootstrap/app.php`
  still builds `dirname(__DIR__).'/vendor/nvade/numerosis/routes'` by hand for
  `channels.php` — the shape `Numerosis::tenantMigrationPath()` was created to
  delete, one file away from where it was deleted.
- **Genuinely better than planned, worth not undoing:** `docs/host-requirements.md`
  (61 rows, each with a "Checked by" cell `HostRequirementsTest` enforces) is
  stronger than R9 asked for; the `packageRegistered()`-vs-`packageBooted()`
  phase reasoning is captured in code rather than prose; workbench panel
  providers stayed the minimal stand-ins Phase 8 demanded (44/49 lines).
- **R10's `Support\ModelResolver` extraction is deliberately NOT in this
  plan.** See "Rejected" at the bottom — do not re-add it without new
  evidence.

---

## Phase 1 — Get the tree honest — **DONE 2026-08-07 (`numerosis@6165257`, `thin-app@9533af4`)**

Kept for the causes, which outlive this instance. All three tasks are closed:
the concurrent session's work was reviewed and committed as one change, the
D12 bypass was a real bug in new code, and the 8 PHPStan errors were three
real defects plus two test-idiom slips — none of them noise.

**What the 8 errors actually were, since "dirty files" undersold it:**

- `SubscriptionsTable`'s new tenant-name column read `$record->subscribable?->name`
  through a `MorphTo`, which resolves as a bare `Model` — it happened to work
  only because `Tenant` is the sole billable in that table today, and
  `CentralUser` is already `Billable`. Narrowed with `instanceof`.
- `SubscriptionForm`'s tenant Select mapped `pluck()`/`get()` output through
  closures claiming types the query builder never promised.
- `SubscriptionsByPlanChart` did the same over `pluck()`'s `mixed` values;
  rewritten as an explicit loop that narrows once, visibly.
- `FeaturesRelationManagerTest` chained `assertCanSeeTableRecords()` **after**
  `assertSuccessful()`, which Livewire's `Testable` forwards to the underlying
  `TestResponse` and returns *that* — so the table assertion was being called
  on the wrong object. Same family as `testing.md`'s vacuous-assertion bullet:
  an assertion that cannot run is worse than a missing one.
- `UserResourceTest` read a connection name with bare `config()`; now
  `Config::string()`, per `static-analysis.md`.

**One process lesson worth keeping:** D11 requires re-copying a changed rule
into thin-app, and *two* consecutive sessions skipped it — `diff -rq` found
`auth-guards.md`, `tenant-provisioning.md` and `testing.md` all stale there.
Run `diff -rq numerosis/.claude/rules thin-app/.claude/rules` at the end of
any session that edits a rule; only `INDEX.md` is allowed to differ (it
carries the not-canonical banner).

### 1.1 Resolve the concurrent session's working tree — DONE

- **Goal:** `git status --short` empty in numerosis.
- **Files:** 26 modified + 11 untracked, all under `src/Filament/`,
  `src/Policies/`, `src/Models/Central/`, `tests/Feature/Filament/`,
  `database/seeders/RoleAndPermissionSeeder.php`,
  `.claude/rules/auth-guards.md`.
- **Reasoning:** the 1 test failure and all 8 PHPStan errors originate here.
  R6's rule ("never end a session with an uncommitted tree") has been honoured
  by the plan's own commits and violated by everything else in this repo for
  four sessions running; the cost is that every baseline has to be re-derived
  with `git stash` before it can be trusted.
- **Dependencies:** none, but **this is a user decision — do not commit,
  stash-drop, or revert someone else's in-flight work on your own initiative.**
  Ask which it is.
- **Done when:** tree clean, and `vendor/bin/pest --ci` + `composer analyse`
  re-measured and written into the Live status block above.

### 1.2 Fix the D12 bypass in `SubscriptionForm` — DONE

- **Goal:** `ModelResolverBypassTest` green without touching the test.
- **Files:** `src/Filament/Admin/Resources/Central/Subscriptions/Schemas/SubscriptionForm.php`
  lines 48 and 56.
- **Reasoning:** both lines call `Tenant::` bare. The arch test is doing its
  job — a host's `numerosis.models.*` override never reaches those call sites.
  Route them through `Numerosis::model(Tenant::class)` like every other call
  site (D12 in `package-extraction.md`).
- **Dependencies:** 1.1 (the file belongs to that session's diff).
- **Done when:** `vendor/bin/pest --filter=ModelResolverBypass` passes and the
  test file itself is unmodified.

### 1.3 Clear the 8 PHPStan errors — DONE

- **Goal:** `composer analyse` exits 0.
- **Files:** same set as 1.1 — includes
  `tests/Feature/Filament/Admin/Resources/Central/PaymentPlans/FeaturesRelationManagerTest.php:50`
  (`assertCanSeeTableRecords()` on a `TestResponse`, i.e. a Livewire helper
  called on the wrong object) and
  `tests/Feature/Filament/Admin/UserResourceTest.php:61` (`Model::on()` given
  `mixed`).
- **Reasoning:** the baseline exists so **new** errors fail immediately
  (`static-analysis.md`). Eight live errors outside it destroys that signal.
- **Dependencies:** 1.1.
- **Done when:** exit 0 and `phpstan-baseline.neon` has not grown.

---

## Phase 2 — Delete dead weight — **DONE, reverified 2026-08-11**

All 5 items already resolved by the time this was rechecked: 2.1
(`src/Numerosis.php` doesn't exist), 2.2 (`src/Commands/` has only
`InstallNumerosisCommand.php`, nothing else registered), 2.3 (no stale
`numerosis-{tenancy,billing}.php` comment found in
`NumerosisServiceProvider`), 2.4 (only one publish tag, `numerosis-models`,
exists). 2.5's actual complaint — README pointing at
`saas-m/.claude/plans/archive/package-extraction.md` for remaining work — was
already fixed (points at `.claude/plans/post-extraction-review.md` in this
repo). The remaining `saas-m` mentions in README are the repo-role table,
accurate given the 2026-08-10 decision (`package-extraction.md`) to keep
saas-m rather than archive it — not stale pointers, nothing to change.

All independent of Phase 1 and of each other. Safe to do first if 1.1 stalls.

### 2.1 Delete `src/Numerosis.php`

- **Goal:** remove `class Nvade\Numerosis\Numerosis {}` — an empty skeleton
  leftover with zero references anywhere in `src`, `tests`, `workbench`,
  `config`.
- **Reasoning:** it shares a name with `Support\Numerosis` (the real seam) and
  with `Facades\Numerosis`, so it is worse than inert — it is a third thing
  called Numerosis for a reader to disambiguate.
- **Done when:** file gone, `composer dump-autoload`, suite green.

### 2.2 Delete `NumerosisCommand`

- **Goal:** remove `src/Commands/NumerosisCommand.php` and its
  `->hasCommand(NumerosisCommand::class)` line in
  `NumerosisServiceProvider::configurePackage()`.
- **Reasoning:** spatie skeleton output — `$signature = 'numerosis'`,
  `$description = 'My command'`, handle() prints "All done". It is registered,
  so every consumer gets it in `artisan list`.
- **Done when:** `artisan list | grep numerosis` shows only `numerosis:install`
  and the `tenancy:`/`billing:` commands.

### 2.3 Fix the stale D13 comment

- **Goal:** `NumerosisServiceProvider::packageRegistered()`'s opening comment
  stops claiming Tenancy/Billing providers "merge their own
  `config/numerosis-{tenancy,billing}.php` via `mergeConfigFrom()`".
- **Files:** `src/NumerosisServiceProvider.php`, ~line 90.
- **Reasoning:** D13 deleted both files and both merge calls. Same stale-claim
  class the previous session fixed in `host-requirements.md` and the model-stub
  comment. Keep the *real* reason the two providers are registered here
  (swappability), drop the false mechanism.
- **Done when:** the comment describes what the code does; no other reference
  to `numerosis-tenancy.php`/`numerosis-billing.php` survives outside the plan
  files' historical records (`grep -rn "numerosis-billing" src config docs`).

### 2.4 Collapse the duplicate publish tag

- **Goal:** one tag for the 9 model stubs, not two identical ones.
- **Files:** `src/NumerosisServiceProvider.php` (`numerosis-models` /
  `numerosis-stubs`, same `$modelStubs` map), `docs/host-requirements.md` if it
  names either, `InstallNumerosisCommand::publishAssets()`.
- **Reasoning:** the provider's own comment already concedes the two tags mean
  the same thing. Two names for one operation is the drift shape this codebase
  keeps finding; keep `numerosis-models` (what `publishAssets()` calls).
- **Done when:** `artisan vendor:publish --tag=numerosis-stubs` no longer
  exists, or it exists and the docs state it is an alias.

### 2.5 Rewrite `README.md`'s status block

- **Goal:** stop pointing consumers at
  `saas-m/.claude/plans/archive/package-extraction.md`.
- **Reasoning:** contradicts D11 (numerosis's `.claude/` is canonical) and
  points into the repo about to be archived. Also drop "Under extraction from
  the saas-m monolith" once Phase 6 lands — the extraction is finished.
- **Done when:** no `saas-m` path appears in `README.md`.

---

## Phase 3 — Close the last host seam — **DONE 2026-08-07 (`numerosis@3de87a3`, `thin-app@ce200ca`)**

### 3.1 `Numerosis::broadcastChannelsPath()`

- **Goal:** thin-app's `bootstrap/app.php` contains no `vendor/nvade` string.
- **Files:** `src/Support/Numerosis.php` (new method, `dirname(__DIR__, 2).'/routes/channels.php'`),
  thin-app `bootstrap/app.php` (`$packageRoutes` variable and the
  `withBroadcasting()` call).
- **Reasoning:** identical to cleanup item E. The `tenantMigrationPath()`
  docblock already states why: `InstalledVersions::getInstallPath()` is wrong
  when the package is the root project, a hardcoded `vendor/nvade/numerosis/…`
  string is wrong the moment the file moves, `__DIR__`-relative is right. This
  is the same file-locating problem one line away from where it was solved.
- **Done when:** `grep -rn "vendor/nvade" ~/repos/private/thin-app/bootstrap`
  is empty, and a real `GET /login` with the correct `Host:` header still
  returns 200 (plain `curl localhost` 500s on tenant identification — that is
  not a bug).

### 3.2 Decide what `Numerosis` means to a host

- **Goal:** one `Numerosis` symbol in host-facing docs.
- **Files:** `composer.json` (`extra.laravel.aliases`),
  `src/Facades/Numerosis.php`, `README.md`, `docs/host-requirements.md`.
- **Reasoning:** the global alias resolves to `Facades\Numerosis`, whose
  docblock lists 2 of the 13 methods on `Support\Numerosis`. Meanwhile
  `bootstrap/app.php` imports the support class directly and calls
  `Numerosis::routes()` on it. A host following the alias and typing
  `Numerosis::routes()` happens to work — facade `__callStatic` resolves the
  manager instance and PHP permits a static call through it — by accident, and
  it type-checks as nothing. **Recommended: drop the alias.** The class is a
  static bootstrap seam, not a resolvable service; a facade over it buys
  nothing and costs a second name. If the alias is kept instead, every method
  gets an `@method static` line.
- **Done when:** either the alias is gone and docs import `Support\Numerosis`,
  or the facade documents all 13 methods.

### 3.3 Tests for the seams themselves

- **Goal:** `Numerosis`'s public API — the entire contract between this package
  and `bootstrap/app.php` — has regression coverage.
- **Files:** new `tests/Feature/Support/NumerosisSeamTest.php`.
- **What to assert:** `middleware()` registers the 4 aliases and both groups,
  with `tenant` = `['web','tenancy.identification','tenancy.route','tenancy.session']`
  in that order; `broadcasting()` and `csrfExceptions()` return their exact
  lists; `routes()` binds one `web`-middleware group per `tenancy.central_domains`
  entry plus one `tenant` group; `exceptions()`'s context closure returns
  `tenant_id`/`guard`/`user_global_id` both inside and outside
  `$tenant->run()`; `assetSourcePaths()` and `tenantMigrationPath()` point at
  directories that exist.
- **Reasoning:** every host-seam bug this extraction found was in exactly these
  methods, and `Numerosis::exceptions()` (cleanup item G) shipped with none.
  The `middleware()` ordering assertions matter specifically:
  `auth-guards.md` and `filament-tenancy.md` both depend on identification
  running first, and `EnsureSessionMatchesTenant` must stay after
  `StartSession`.
- **Dependencies:** 3.1 (so the new method is covered too).
- **Done when:** every assertion has been **confirmed failing against a
  deliberately reverted seam** before being trusted. A test that only ever ran
  green against working code proves nothing — same rule the passwordless-login
  regression tests followed.

---

## Phase 4 — Verification that survives install day

### 4.1 `numerosis:install --check`

- **Goal:** a host can re-run the 24 verifications without publishing or
  seeding anything.
- **Files:** `src/Commands/InstallNumerosisCommand.php` — extract the
  `verify*()` sequence out of `handle()` into one method both paths call;
  `--check` skips `publishAssets()`, `appendEnvKeys()`, `appendModelOverrides()`,
  `seedCentralData()` and exits non-zero on any failure.
- **Reasoning:** step 2 of the extraction plan dropped `numerosis:doctor` on
  the grounds that "the audit's findings became direct fixes + tests instead."
  That reasoning expired the moment 24 `verify*()` methods landed: they now
  encode host requirements that can break *after* install (a host edits
  `config/tenancy.php`, publishes a stale `config/numerosis.php`, upgrades
  Livewire), and nothing re-checks. Reusing the install command rather than
  adding a second one keeps one list, which is the whole point of
  `HostRequirementsTest`.
- **Done when:** breaking `session.domain` in thin-app makes
  `sail artisan numerosis:install --check` exit non-zero and name the key; a
  clean host exits 0 and writes nothing.

### 4.2 Failure-path tests for the untested `verify*()`

- **Goal:** each of the ~20 currently-untested verifications has a test that
  unsets or corrupts its key and asserts the command fails naming it.
- **Files:** `tests/Feature/Console/Commands/InstallNumerosisCommandTest.php`
  (today: 7 tests, covering model overrides + seeded data only).
- **Reasoning:** a `verify*()` reading a mistyped config key passes silently
  forever — it is exactly the shape of "a cache write nobody reads"
  (`tenant-caching.md`). `HostRequirementsTest` enforces doc/method **name**
  parity, not behaviour, so it cannot catch this.
- **Dependencies:** 4.1 (test `--check`, not the publishing path).
- **Done when:** each new test is mutation-verified, and `HostRequirementsTest`
  is extended with a third assertion: every `verify*()` method has at least one
  test naming it.

### 4.3 Config schema version

- **Goal:** a host holding an older published `config/numerosis.php` fails
  loudly instead of silently losing keys.
- **Files:** `config/numerosis.php` (new `schema_version` int),
  `InstallNumerosisCommand` (new `verifyConfigSchemaVersion()`),
  `docs/host-requirements.md` (new row).
- **Reasoning:** `mergeConfigFrom()` merges one level deep, so an older
  `domains` or `modules` array wins wholesale. This has already bitten thin-app
  twice (D13's deleted files, and the re-copy recorded in the cleanup plan's
  Traps). The failure is total silence — nothing errors, the key is simply
  never read again.
- **Dependencies:** 4.1.
- **Done when:** an out-of-date published config fails `--check` with the
  re-publish instruction; the version is bumped in the same commit as any
  future top-level config key change.

### 4.4 Fold asset-drift into `--check`

- **Goal:** `verifyPublishedAssetsMatchSource()`'s warning is reachable outside
  an install run.
- **Reasoning:** it stays a warning, not a failure (a host is *allowed* to
  customise `resources/js`) — but a warning only ever printed at install time
  is a warning nobody sees.
- **Dependencies:** 4.1.

---

## Phase 5 — thin-app becomes a real consumer

This phase is where the extraction's remaining risk actually lives. Every
host-seam bug so far was found by a second consumer, and thin-app cannot
currently prove anything: it has no test runner.

### 5.1 Install Pest in thin-app

- **Goal:** `vendor/bin/sail artisan test` runs a real suite.
- **Files:** thin-app `composer.json` (`pestphp/pest`, plugins),
  `tests/TestCase.php`, `phpunit.xml`.
- **Reasoning:** prerequisite for 5.2-5.5. thin-app today has Laravel's two
  generated `ExampleTest`s and nothing else; the package's own suite cannot
  test host wiring by construction.
- **Done when:** `sail artisan test` green, and `phpunit.xml` sets the same
  `DB_LOCK_WAIT_TIMEOUT` pair `testing.md` requires.

### 5.2 Move the 11 quarantined test files

- **Goal:** delete `<group>thin-app</group>` from the package's
  `phpunit.xml.dist`.
- **Files, all currently in numerosis carrying `#[Group('thin-app')]`:**
  `tests/Feature/Actions/Modules/PurchaseModuleTest.php`,
  `tests/Feature/Console/Commands/{MigrateTenantModule,SeedTenantModule}Test.php`,
  `tests/Feature/Filament/TenantAdmin/Pages/Modules/MarketplaceTest.php`,
  `tests/Feature/Jobs/{MigrateModules,RollbackModules}Test.php`,
  `tests/Feature/Modules/{AnnouncementsModule,BrandingModule,NotesModule,TasksModule}Test.php`,
  `tests/Feature/Modules/Branding/ApplyBrandingTest.php`.
- **Reasoning:** Phase 6.3 tagged them "move them in Phase 7"; Phase 7 never
  did. They test app-side module packages that structurally cannot exist in the
  package — and they now run in **neither** repo, which is the outcome the
  no-delete rule was supposed to prevent.
- **Dependencies:** 5.1.
- **Expect real failures, not a clean move.** Likely: `Module [x] not found`
  from a stale in-process registry (restart the queue worker —
  `module-marketplace.md`), and tenancy left switched after a throwing
  `$tenant->run()` callback.
- **Done when:** the group exclusion is gone from the package, the package
  suite count drops by the moved tests, and thin-app's suite is green.

### 5.3 Bootstrap-wiring tests in thin-app

- **Goal:** cover Phase 6.3's right-hand column, which has never been written.
- **What to assert:** middleware group membership and order as registered by
  `Numerosis::middleware()` through the host's real `bootstrap/app.php`;
  central routes bound per `tenancy.central_domains`; the vite manifest
  resolves `resources/js/central.js` and `tenant.js` (a missing entry point
  breaks *payment*, not styling — the three `stripe-*.js` files are
  load-bearing); `numerosis:install --check` exits 0.
- **Dependencies:** 5.1, and 4.1 for the last assertion.

### 5.4 Pest browser test for Phase 10's gate item 3

- **Goal:** the end-to-end gate stops being manual.
- **What it covers:** register a tenant through the wizard → the chain runs on
  the `provisioning` queue → `tenants.provisioned_at` is set (**not** merely
  that a `tenants` row exists — `tenant-provisioning.md`'s first rule) → log
  into the tenant panel on the tenant subdomain.
- **Reasoning:** R10 asked for this, `package-extraction.md`'s Phase 10 now
  records the gate as "NOT REPRODUCIBLE AS WRITTEN" — the one prior run used
  `StartLocalCheckout::run()` in tinker and its fixtures were deleted. It is
  the only check covering routes + panels + provisioning + assets together,
  and it is the extraction's whole risk surface.
- **Dependencies:** 5.1. Needs a live worker on the `provisioning` queue
  (`docker/8.5/supervisord.conf`, `[program:queue-provisioning]`).
- **Done when:** it passes twice consecutively against a dropped-and-recreated
  database, **and** `package-extraction.md`'s Phase 10 gate text is changed
  from "manual" to this test's name. If the browser layer proves too flaky,
  fall back to a Livewire-level wizard test plus an explicit assertion on
  `provisioned_at` — that still beats a tinker run nobody can repeat.

### 5.5 thin-app CI

- **Goal:** the repo that proves the seam works has automated proof.
- **Files:** new `.github/workflows/run-tests.yml` in thin-app, mirroring the
  package's — mysql service, `pdo_mysql` extension (the package's own workflow
  lacked it until step 5 and therefore could never have connected), pest,
  phpstan.
- **Dependencies:** 5.1.

---

## Phase 6 — Release readiness, then archive

### 6.1 Version, changelog, upgrade guide

- **Files:** `CHANGELOG.md` (currently the empty template), a `v0.1.0` tag,
  new `UPGRADING.md`.
- **Reasoning:** thin-app requires `"@dev"` through a path repo, so nothing is
  pinnable and no consumer can tell what changed. D13 already moved config
  files in a way that silently drops a host's customisations — that is the
  first `UPGRADING.md` entry, and 4.3's schema version is what will detect it
  next time.

### 6.2 Second-consumer smoke test

- **Goal:** `laravel new` → path-repo the package → `numerosis:install` →
  boot, by the documentation alone.
- **Reasoning:** thin-app is the *first* consumer and was configured by hand
  across many sessions; it cannot tell you whether the documented path works.
  Every host-seam bug so far was found by adding a consumer.
- **Done when:** the app boots and `numerosis:install --check` exits 0 without
  any step not written in `docs/host-requirements.md`.

### 6.3 Archive saas-m — **STOP**

Unchanged from `package-extraction.md`'s Phase 10: retiring a repo is confirmed
in the moment, never pre-authorised by a document. Requires explicit user
go-ahead. Then add to `.claude/rules/INDEX.md` in both repos the line about
bare commit hashes referring to the archived repo (already present — verify,
don't duplicate).

---

## Rejected — do not re-add without new evidence

- **R10's `Support\ModelResolver` extraction.** `Support/Numerosis.php` is 310
  lines across three groups (bootstrap seam, name resolution, path resolution),
  but only `factoryNameFor()`/`modelNameFor()` share logic and both are already
  single-source-of-truth with the docblocks explaining why they must not be
  collapsed into each other or into `model()`. Moving them buys a smaller file
  and costs an import in two callers plus a second place a host has to look.
  If it is ever done, move **only** those two plus `model()`, and leave the
  `bootstrap/app.php` seam where a host expects to find it.
- **`PublishesPackageAssets` deletion.** One consumer, saves one
  `runningInConsole()` check, its own docblock admits it. Real, but not worth a
  commit on its own — fold it into whichever task next touches
  `NumerosisServiceProvider::packageBooted()`, or leave it for the second
  provider it was written for.
- **`numerosis:doctor` as a separate command.** 4.1 puts the same capability
  behind `numerosis:install --check` instead, so the verification list stays
  in one file and `HostRequirementsTest` keeps working unchanged.

---

## Traps (carried forward — they will recur)

- **`vendor/bin/pint` without `--dirty` rewrites ~160 unrelated files in
  numerosis.** Always `vendor/bin/pint --dirty --format agent`.
- **PHPStan needs 1G.** Use `composer analyse`; a bare `vendor/bin/phpstan
  analyse` dies at 128M with a `FatalError` inside
  `build/phpstan/resultCache.php`, which reads like a code error.
- **`db:seed` is not Laravel's command** with stancl/tenancy installed —
  resolve seeders from the container (`Model::unguarded()` +
  `setContainer(app())` + `__invoke()`). Two instances already; a third is
  waiting for whoever reaches for `Artisan::call('db:seed')` out of habit.
- **`Config::array()` throws on a missing key**, it does not return `[]`.
- **`HostRequirementsTest` fails** if a `verify*()` is added or removed without
  its `docs/host-requirements.md` row. That is the point — add the row.
- **A host holding a published `config/numerosis.php` from an older version
  silently loses new keys.** 4.3 is the fix; until then, re-copy the package
  default after any config change.
- **"0 failed" measured against a reused MySQL volume is unverified** for any
  path that only runs when a database does not yet exist (template build, first
  migrate, first seed). Drop the volume before trusting a suspicious green run.

## Method that worked, keep using it

- **Verify by mutation, not by going green.** Every regression test in the last
  three sessions was confirmed failing against the pre-fix code before being
  trusted. Phase 3.3 and 4.2 are explicitly written to require this.
- **Verify in thin-app, not only in the package suite.** Both live bugs the
  cleanup plan found (B and D) were invisible to a green package suite and
  obvious from one command in the host. Phase 5 exists to make that repeatable
  instead of manual.
- **Commit at every green point, in both repos.** Task 1.1 exists because this
  was not done.
