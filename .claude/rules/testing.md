---
topic: testing
updated: 2026-08-29
---
# Test Suite

## Running it

- **This repo has no Sail, and `CLAUDE.md`'s `vendor/bin/sail …` instructions do not apply to it.** `laravel/sail` appears nowhere in `composer.lock` and there is no `vendor/bin/sail`; the guidelines block in `CLAUDE.md` is inherited from the saas-m host app this package was extracted from. Here it is Testbench plus a single `docker-compose.yml` MySQL service, and the commands are run on the host:

  - formatting — `vendor/bin/pint` (run **first**, see below)
  - tests — `php -d memory_limit=1G vendor/bin/pest --compact` (or `composer test`)
  - one file/filter — `vendor/bin/pest --compact --filter=SomeTest`
  - static analysis — see `.claude/rules/static-analysis.md` (needs a `tmpDir` override)
  - MySQL must be up: `docker compose ps` should show `numerosis-mysql-1` healthy

  **Run Pint before Pest, not after.** Pint rewrites files (`class_definition`,
  `fully_qualified_strict_types`, `braces_position`, `single_line_empty_body`,
  …) — if the suite runs first, a Pint pass afterward can silently change the
  files a green run just checked, and that run is no longer evidence for the
  code on disk. Running Pint first means whatever Pest then executes is
  exactly what gets committed; no second test run needed to re-cover
  Pint's own edits.

  Anywhere below that still says `sail exec laravel.test …`, read it as "run this inside whatever gives you a shell next to MySQL" — the diagnostic SQL and the process-hunting are still right, the wrapper is not.

- **Suite run serial. No add `--parallel`.** Tried two sessions, abandoned; see "Why parallel was dropped" below. Single files (~5s), subsets (~10s) right granularity for iterating.

- **Two teardown hooks do work `RefreshDatabase` cannot**, both registered
  from `Tests\TestCase::setUp()` via `beforeApplicationDestroyed()` so run
  *after* its rollback:

  - `deleteCentralWrites()` — `RefreshDatabase` transacts only default
    connection, so anything written through `central` survives test.
    Written tables recorded from `DB::listen()` hook and cleared, so new
    central-connection model needs no change here. Transacting `central`
    instead not work: stancl issues its `CREATE DATABASE` on that
    connection and MySQL implicitly commits on DDL.
  - `deleteTenantDatabases()` — drops physical tenant databases, through
    dedicated connection. **Never issue this DDL on default connection**:
    implicit commit ends test's transaction, commits everything it
    wrote. And must run *after* rollback — test ending inside tenant
    context leaves default connection pointed at tenant database, so
    dropping first makes rollback reconnect to database that no
    longer exists (`Unknown database`).

  **"Registered from `setUp()` so run after rollback" only true on plain
  Laravel — under Testbench (numerosis package repo) it's inverted, and
  register order must flip.** `Illuminate\Foundation\Testing\TestCase::
  beforeApplicationDestroyed()` appends (`[] =`), so last-registered runs
  last; `Orchestra\Testbench\Concerns\ApplicationTestingHooks`'s does
  `array_unshift`, so last-registered runs **first**. Same source line,
  opposite meaning. Copying this class into a Testbench harness verbatim
  therefore ran `deleteTenantDatabases()` *ahead* of `RefreshDatabase`'s
  rollback and produced exactly the `Unknown database 'tenantX'` failure the
  bullet above warns about — 15 of 32 failures in numerosis's suite, fixed
  `numerosis@ef036e6` by calling `beforeApplicationDestroyed()` **before**
  `parent::setUp()` (array only reset in `tearDownTheApplicationTestingHooks()`,
  after callbacks run, and the method touches nothing but that property, so
  registering pre-app is safe). Tells it's this and not contention: it
  **reproduces one file at a time** (documented contention class needs a full
  run), the body's assertions all pass and only teardown throws, and the
  failure is a bare `PDOException` at `parent::tearDown()` with no test-side
  frame — Testbench's `callBeforeApplicationDestroyedCallbacks()` keeps only
  the *first* callback exception and swallows the rest, so the real thrower
  is invisible until each step is wrapped by hand. Any other saas-m teardown
  behaviour that depends on callback order needs re-checking the same way
  when ported.

  **2026-08-13: both hooks moved out of `tests/TestCase.php` into a shipped
  trait, `Nvade\Numerosis\Testing\CleansUpTenancyDatabases`, and the callback
  ordering above is no longer load-bearing.** Hosts need this teardown too and
  were copying it by hand, inverting the registration order half the time
  (`.claude/rules/host-integration-quickstart.md` records what that cost
  tabellio), so the trait stopped relying on landing after
  `RefreshDatabase`'s rollback and instead does what being after it bought:
  `endTenancy()` first (so nothing reconnects to a database this teardown is
  about to drop), then `releaseTestTransactions()` (rolls every open
  transaction back to level 0, so the central deletes don't block on the
  test's own row locks — `RefreshDatabase`'s later `rollBack()` returns early
  at level 0 rather than conflicting). `TestCase::setUp()` still registers it
  before `parent::setUp()`, which under Testbench still puts it behind the
  rollback; that's now belt-and-braces, not the mechanism. Tenant database
  *names* are also read **before** the central deletes run, since those empty
  the `tenants` table the lookup reads — the nested `finally` chain is
  unchanged in shape. Suite-specific bits stayed in `TestCase` as overrides:
  `additionalTenantDatabases()` (`CloneTenantSchema::takeCreatedDatabases()`)
  and `preservedTenantDatabases()` (the clone template), plus
  `keepDatabaseSchema()` in `tearDown()` where `keepSchema()` used to be.

- **A view test that renders a component from a `suggest`-only package
  asserts nothing, and passes.** Blade leaves an unregistered
  `<flux:button …>` as literal text rather than erroring, so
  `assertSee('/oauth/google')` fails while `assertDontSee(...)` passes
  *vacuously* — the file reads like one broken assertion in an otherwise
  working test. Moving `livewire/flux` to `require-dev` in numerosis turned
  1 failure into 5, every one of them a real bug the literal rendering had
  been hiding (an unset `auth.social.routes.*` reaching `route()` as
  `route(null)`; four Lucide icons the package shipped under the wrong view
  namespace). **If package code renders another package's components, that
  package belongs in `require-dev` even when it is `suggest` for
  consumers** — otherwise the view suite is measuring string literals.
  Same family as the `ArchTest` that scanned `base_path('app')` under
  Testbench: empty directory, zero assertions, reported *risky* rather than
  failing. Treat "risky" and "one odd failure in a green file" as the same
  signal — an assertion that never ran.

  **2026-08-10: `livewire/flux` went further, from `require-dev` to `require`,
  and stopped being `suggest` at all** — along with `internachi/modular`,
  `laravel/socialite` (+ both provider packages),
  `ryangjchandler/laravel-cloudflare-turnstile`,
  `spatie/laravel-livewire-wizard` and `alizharb/filament-activity-log`. The
  bullet's rule is unchanged and `require` satisfies it (dev installs get the
  package either way); what changed is that hosts no longer list those eight in
  their own `composer.json`. Reason, and the install-time-vs-feature-time rule
  for the next dependency, in `DEPENDENCIES.md` — short version: a `suggest`
  entry promises the package degrades cleanly without it, and a Blade tag
  rendering as literal text is not clean degradation, it is a silent pass.

- **`assertDatabaseHas()`/`assertDatabaseMissing()` default to the *default*
  connection, which under `RefreshDatabase` is a transaction — a central-connection
  write (autocommit, see above) is invisible to it under MySQL's REPEATABLE-READ
  isolation, since the default connection's transaction started its snapshot
  before the central-connection write committed. Passes locally by luck
  (connection reused, snapshot stale in the same direction) and fails or
  false-passes depending on ordering. Fix is the same one `deleteCentralWrites()`
  needs: pass the connection explicitly —
  `assertDatabaseHas('subscriptions', [...], 'central')`. Hit this in
  `ConnectSocialAccountTest` (`socialite_logins`) and `RecordSubscriptionTest`
  (`subscriptions`, `subscription_items`) — both central-connection tables,
  asserted with no third argument. Any assertion against a table written
  through `central`/`tenant` needs the connection named, not assumed.**

- **Seeding through the ambient default connection under an open
  `RefreshDatabase` transaction can deadlock a later central-connection write
  needing an FK check against the row just seeded — not flaky, guaranteed,
  every run.** `RoleAndPermissionSeeder` wrote `Role`/`Permission` (context-switching
  models — `SpatiePermissionsBootstrapper` repoints their default connection in
  tenant context) via the ambient default, leaving the new role row locked for
  the rest of the test. `PromoteFirstCentralUserToAdmin`'s `model_has_roles`
  insert, done via `CentralUser`'s own `central` connection, then blocks on that
  row's FK check for the full `lock_wait_timeout` — a second, narrower instance
  of the general cross-connection contention class below, but deterministic
  rather than timing-dependent because the seeder runs *before* the blocking
  insert in every test, not racing it. Fixed by pinning the seeder's writes to
  `central` explicitly (`Role::on($central)->firstOrCreate(...)`) — data is
  guard `web`-only, i.e. central by definition, so no correctness change, just
  removes the ambient-connection detour. **Any seeder writing central-only
  data (roles, permissions, plans) through a context-switching model should
  pin `::on($central)` rather than ride the ambient connection**, same
  reasoning as the `assertDatabaseHas` bullet above.

- **`Feature` and `PaymentPlanFeature` were the only `Models\Central\*` missing
  stancl's `CentralConnection`, despite `features` existing solely in
  `database/migrations/central`.** Same trap class as the seeder bullet
  above — a context-switching-capable model with no `CentralConnection` trait
  rides the ambient default connection, which is `tenant` inside any test that
  entered tenant context first. Found during D8 (package extraction) while
  auditing every `Models\Central\*` file for the trait, not from a failing
  test — the two models happened not to be read from inside `$tenant->run()`
  in any test that existed at the time, so nothing exercised the gap. **When
  adding a new `Models\Central\*` model, or auditing after a rename, check
  every sibling in that directory carries `CentralConnection` — a model that
  doesn't will pass every test that never mixes it with tenant context, and
  fail exactly the way `RoleAndPermissionSeeder` did above once one does.**

- **`Stancl\Tenancy\Commands\Seed` ("tenants:seed") does not work, and this bit
  a second caller beyond `SeedTenantDatabase`.** The full failure mode (two
  independent breaks: name collision with `db:seed`, then a missing
  `--tenants` option) is recorded in `.claude/rules/tenant-provisioning.md`'s
  `Stancl\Tenancy\Commands\Seed` bullet — read it there, this is only the
  second instance. `numerosis:install --seed` and Testbench's `$this->seed()`
  helper both go through `Artisan::call('db:seed', ...)`, which — with
  stancl/tenancy installed — does not reliably resolve to Laravel's own
  `SeedCommand`; the collision resolves by **registration order**, which
  differs between a real Laravel app and a Testbench harness. Fixed the same
  way as the first instance: resolve the seeder from the container directly
  (`Model::unguarded()` + `setContainer(app())` + `__invoke()`), never through
  `Artisan::call('db:seed', ...)` or `'tenants:seed'`. **The trap did not
  reproduce in thin-app** when first found in the package's own install
  command — "it works in the host" proves nothing about Testbench, and vice
  versa, because the resolution is order-dependent, not a stable fact about
  the package.

  ## Suggested better approach

  Both instances exist because `db:seed` is being used as "run some
  `Seeder::class`" when what's actually wanted is "instantiate and invoke a
  known seeder class directly" — the same operation `SeedTenantDatabase`
  ends up doing after working around the Artisan collision. If a third
  caller needs this, extract the resolve-and-invoke sequence into one
  shared helper (a static method, not another `Artisan::call`) rather than
  hand-rolling `Model::unguarded()`/`setContainer()` a third time — that
  removes the chance of a fourth caller reaching for `Artisan::call('db:seed'
  , ...)` out of habit and rediscovering this the hard way.

- **Each teardown step needs own `finally`, since step that throws is
  first one.** `beforeApplicationDestroyed()` closure runs
  `deleteCentralWrites()` → `deleteTenantDatabases()` →
  `disconnectAllConnections()`. Wrapping first two in single `try` with
  only third in `finally` looks right, isn't: exception these guards
  exist for is lock-wait timeout thrown by `deleteCentralWrites()`, so
  sharing one block skipped `deleteTenantDatabases()` on exactly ~9 tests
  that hit it — leaking physical tenant database per occurrence, debris
  `tenancy:prune-orphaned-databases` written to clean up. Now nested,
  so each step runs regardless of previous outcome. Same trap for any
  cleanup chain added here later.

- **`TestCase::keepSchema()` pins `RefreshDatabaseState::$migrated = true`.**
  `RefreshDatabase` clears that flag whenever test's transaction gone at
  teardown, and every tenancy test trips it — stancl's
  `DatabaseTenancyBootstrapper` purges default connection, so `getPdo()`
  returns session never in transaction. Without pin, most
  tests followed by full `migrate:fresh`: measured ~3s per test, i.e.
  majority of suite's runtime. No test issues DDL against central
  schema, so rebuild only ever restores what's already there.

- **Statement never waits out metadata lock anymore — and, since
  2026-07-31, neither does one waiting on ordinary row/FK lock.**
  `config/database.php` applies `DB_LOCK_WAIT_TIMEOUT` (set to 10s in
  `phpunit.xml`, unset in production) as session variable on every MySQL
  connection, tenant connection inherits it since
  `DatabaseConfig::connection()` merges central connection's config.
  MySQL's default for `lock_wait_timeout` (metadata/DDL locks — `DROP TABLE`,
  `ALTER TABLE`) is 31536000 seconds — a year — so before this, a
  `migrate:fresh` colliding with stranded transaction didn't fail, it
  hung, and every later request for those tables queued behind it. Run
  that hangs reports nothing, finishes never, keeps its MySQL session open long
  after process killed. Ten seconds turns all that into normal red
  test naming the table.

  **`innodb_lock_wait_timeout` separate session variable** governing
  ordinary row/FK locks — kind `INSERT`/`UPDATE`/`DELETE` waits on, not
  DDL — and was never being set: original fix only issued
  `SET SESSION lock_wait_timeout = …`. MySQL's default for that variable is 50
  seconds, not a year, so failure mode subtler than true hang — every
  one of "~9 lock wait timeout" failures below genuinely bounded, just
  at 50s instead of intended 10s, which is most of why full run cost
  ~615–783s. `config/database.php`'s PDO init command now sets both variables
  in one `SET SESSION lock_wait_timeout = …, innodb_lock_wait_timeout = …`
  statement. Confirmed on actual failure (a `DELETE FROM socialite_logins`
  blocked on another connection's FK-shared-lock, per `SHOW FULL PROCESSLIST`
  + `performance_schema.data_locks`): before fix, two-test targeted run
  took 50+s per collision; after, 33.75s total for whole file, full
  suite run dropped from ~615–783s to ~307s with identical 9 failures
  still occurring — same underlying contention, five times cheaper to hit.
  `tests/Feature/Database/LockWaitTimeoutTest.php` asserts bound
  applied on `mysql`, `central`, `tenant` for *both* variables now — it
  previously only checked `lock_wait_timeout`, exactly the blind spot
  that let this ship unnoticed.

  Doesn't make *collision* impossible — see hang described
  below, one run blocking itself — only makes it visible. If
  timeout starts firing, question still "who left transaction open",
  answer still in `performance_schema.metadata_locks`.

- **Sail test run can't be stopped with host-level `pkill`.**
  Container's PHP survives, MySQL keeps its session — including any open
  `RefreshDatabase` transaction — until `wait_timeout` (8h). Next run's
  `migrate:fresh` then blocks forever on metadata lock behind that dead
  session. Kill it inside container instead:
  `sail exec laravel.test bash -lc "ps -eo pid,cmd | grep '[p]est'"` then
  `kill -9 <pid>`.

- **Diagnosing hung run:** stalled `migrate:fresh` shows up as
  `Waiting for table metadata lock`. Find holder with

  ```sql
  SELECT ml.OBJECT_NAME, ml.LOCK_TYPE, t.PROCESSLIST_ID
  FROM performance_schema.metadata_locks ml
  JOIN performance_schema.threads t ON t.THREAD_ID = ml.OWNER_THREAD_ID
  WHERE ml.OBJECT_SCHEMA = 'testing' AND ml.LOCK_STATUS = 'GRANTED';
  SELECT trx_state, trx_started, trx_mysql_thread_id FROM information_schema.innodb_trx;
  ```

  `KILL`ing blocked `migrate:fresh` mid-`DROP TABLE` leaves `testing`
  half-dropped, *every* later test fails on missing central tables. Looks
  like catastrophic regression, isn't. Recover with
  `DROP DATABASE testing; CREATE DATABASE testing …` before drawing any
  conclusion from red run.

- **After recreating `testing`, migrate by hand — suite won't.**
  `TestCase::keepSchema()` pins `RefreshDatabaseState::$migrated = true`, so
  `RefreshDatabase` never rebuilds schema, every test dies on
  `Base table or view not found: 1146 Table 'testing.users' doesn't exist`.
  Looks like recovery failed; didn't. Run
  `sail exec -T -e APP_ENV=testing -e DB_DATABASE=testing laravel.test \
  bash -lc "php artisan migrate --force"` once, suite works again.
  Recreate with same charset/collation as `laravel`
  (`utf8mb4` / `utf8mb4_0900_ai_ci`), not server default.

- **Cleaning up leftover databases: `tenantphpunittemplate` must survive**
  (it's `CloneTenantSchema`'s template — dropping costs rebuild), and so
  must any tenant database backing real row in `laravel.tenants`. Everything
  matching `tenanttest-%` or `testing\_test\_%` is debris from killed runs,
  safe to drop. Check real tenants first with
  `SELECT id FROM laravel.tenants;` — orphans left by tests use faker-slug or
  fixture names (`tenantsignaltenant`) easy to mistake for dev data.

- **Known failing tests — don't attribute these to your change.**

  **Current baseline, measured 2026-08-30 on this package repo, after the
  `numerosis-auth-ui` extraction: `php -d memory_limit=1G vendor/bin/pest
  --compact` ⇒ 0 failed, 7 skipped, 622 passed (5540 assertions) in ~105s, on
  *both* `stancl/tenancy` legs.** The assertion count fell while the pass
  count rose: 6 new tests, minus ~35 assertions `ArchTest`'s cashier-key scan
  no longer makes because the 9 files it covered moved to the satellite (which
  now carries that scan itself). **Diff the assertion count, not just the pass
  count, after any package move** — see `.claude/rules/package-split.md`.
  The suite is green; treat *any* failure as yours until proven otherwise.

  **One open flake**: a single stable-leg run once reported a second failure
  that did not recur across four further full runs and three targeted ones,
  and was never identified. Nothing was found stranded when checked directly
  (no `pest` process, no open transaction, no metadata lock on `testing`), so
  it is not the killed-run class documented below. If a second unexplained
  failure ever appears, capture the full output before re-running — the
  re-run is what destroyed the evidence last time.

- **A long-standing red test attracts explanations instead of diagnosis, and
  a written root-cause note is not a diagnosis.** `RegisterTenantTest`'s
  `assertSee('livewire.js')` was carried for weeks as "pre-existing,
  unrelated, Livewire's asset filename is hashed now, don't waste time on it
  again" — a note that was half right and entirely load-bearing in stopping
  anyone from opening the page. The page was fine throughout: it serves
  `livewire.min.js`, and the minified name does not contain the un-minified
  one as a substring, so the assertion pinned `config('app.debug')` rather
  than behaviour.

  **Repairing an assertion is not finished until you have made it fail.**
  Fixing the needle here produced a test that *could not fail at all*:
  deleting `@livewireScripts` from the layout still passed (Flux emits the
  runtime too), and deleting `@fluxScripts` as well still passed (Filament's
  `@filamentScripts` does too) — three independent suppliers on one page. The
  test now also asserts the layout file itself carries the directive, which is
  what protects its non-Filament consumer, and that half was verified to fail
  when the directive is removed. Same discipline this file already demands of
  new regression tests; it applies equally to old ones being repaired.

  The previous baseline was 4 failed / 580 passed, all 4 in
  `tests/Feature/FreshHostTest`. They were not one cause but four, uncovered
  in sequence — worth keeping, because each was a real defect that only that
  harness could see:

  1. **SQLite refuses to drop a column an index still references.**
     `2025_06_25_105704_update_payment_plan_features.php` dropped
     `feature_key` while `payment_plan_features_payment_plan_id_feature_key_index`
     still named it. Fixed by dropping the index first. The same migration's
     two `try { $table->dropColumn(...) } catch (Exception)` blocks were
     removed with it: `Blueprint::dropColumn()` only *queues* a command, which
     runs after the closure returns, so nothing was ever thrown inside that
     `try` and the "silent fail if column doesn't exist" comment described
     protection that never existed.
  2. **`dropForeign('name')` is unsupported on SQLite.**
     `2026_01_07_001248_unfuck_payment_plans_and_features.php` dropped the
     `subscriptions.payment_plan_id` FK by constraint *name*;
     `SQLiteGrammar::compileDropForeign()` throws unless `$command->columns`
     is populated, i.e. unless you pass the **column array** form
     (`dropForeign(['payment_plan_id'])`). `Schema::hasForeignKey()` matches
     on either name or columns, so the guard works both ways.
  3. **`QUEUE_CONNECTION`/`CACHE_STORE` were assumed to be `sync`/`array` and
     are neither.** Testbench's own skeleton `.env` sets both to `database`
     (matching a fresh Laravel install), and this package's central schema
     drops the `jobs`/`cache` tables, so the real `TenantCreated` pipeline
     died on `Table 'jobs' doesn't exist`. Now set explicitly in that test's
     `setUp()`. See the separate note below — the dropped tables are a real
     host trap, not just a harness one.
  4. **The `Illuminate\Support\Env` repository is static and memoized, so
     `putenv()` only wins on the first app boot in a process.** This is the
     big one and has its own bullet below.

  `FreshHostTest` is the only harness that migrates the central schema
  against `:memory:` and the only one that proves a from-scratch host boots,
  so keep it green before trusting any "fresh install works" claim —
  including anything verifying a multi-package split or a `stancl/tenancy`
  version swap (`.claude/rules/stancl-tenancy-v4.md`).

  The lock-wait contention described below **did not reproduce in this run**
  (0 occurrences of `SQLSTATE 1205`, and ~82s rather than ~307s). Treat the
  `innodb_lock_wait_timeout` material as still-true mechanism and the
  9-failure count as history, not as an expected floor.

  Older measurements, kept for the fixed-list they carry, not as current
  fact: 2026-07-28 full run **149 passed, 28 failed, 1 skipped in ~546s**,
  then 14 of those 28 fixed (see `.claude/rules/filament-tenancy.md`); a
  2026-07-31 pass fixed the rest except lock-wait contention, landing at
  **9 failed, 1 skipped, 313 passed in ~307s**. Baseline a suspicious
  failure against `git stash` before investigating; the list moves.

  What that older run left, by cause rather than file:

  - **~9 `SQLSTATE 1205 Lock wait timeout exceeded`** on `delete from users`
    (`deleteCentralWrites()`), `delete from socialite_logins`, and
    `drop table testing.*` (`migrate:fresh`). These tests pass in isolation,
    fail in full run — cross-test/cross-connection contention, not the
    tests. Before `DB_LOCK_WAIT_TIMEOUT` existed same collision *hung*
    suite instead (see above), so these failures are bound working, not
    new problem. **Stranded transaction/contention itself still
    unfixed** — what changed 2026-07-31 only that it now costs ~10s per
    occurrence instead of ~50s (see `innodb_lock_wait_timeout` fix above),
    which is why same 9-failure count now finishes in ~307s instead of
    ~615–783s. Root cause traced live via `performance_schema.data_locks`: a
    test writing through default connection (an `INSERT` referencing
    `users` row, taking InnoDB FK shared-lock) before calling
    `$tenant->run()` leaves that row locked for rest of its own
    transaction; *different* connection (`central`, used inside
    `$tenant->run()` closure or by later test's teardown) then blocks trying
    to write same row/table. Two candidate fixes tried, rejected as
    insufficient alone: purging every named connection in `TestCase`
    after each test (`disconnectAllConnections()`, still in place as
    safety net, but measured zero effect on failure count by
    itself) and `innodb_lock_wait_timeout` fix above (real, but only
    lowers cost, doesn't remove contention). Real fix needs either
    serializing central-then-tenant writes within single test, or
    avoiding FK-locked row entirely (e.g. reading referenced user
    without lock, or restructuring so socialite/social-account delete
    doesn't need touch row open transaction elsewhere still holds).

  Fixed 2026-07-31, so *reappearance* is regression not baseline:
  `ArchTest`'s billing-contracts violation (`UnpaidTenantQuota` depended on
  concrete `CentralUser` model; now typed against
  `App\Contracts\Tenancy\HasTenants`), `General`'s abstract-model
  instantiation crash (`->model(User::class)` used shared abstract base
  instead of concrete `Tenant\User`) and its inverted
  email-verification-status visibility bug, `ProfileTest`'s
  `disconnectSocialAccount` — Filament page API drift, it's Action now
  (`->callAction('disconnectSocialAccount', arguments: [...])`), not
  callable method — `AddTenantOwner` not creating owner's `Tenant\User`
  row at all (`ProfileSyncTest`'s "tenant user never synced" — real
  missing-sync gap, not just test bug: nothing in provisioning, login, or
  stancl's sync listener ever created it; fixed by creating it inside
  `AddTenantOwner`, matching global_id, name/email/password/email_verified_at
  copied from owning `CentralUser`), `RoleResourceUiTest` (pointed at
  unbound base Filament page instead of real registered
  `TenantAdmin\Clusters\Team\Resources\Roles` resource, test user had
  no role so Filament's own authorization silently redirected it — the
  `instance()` returning null / "Call to a member function … on null" errors
  this produces read like framework bug, aren't one), and `ExampleTest`
  (deleted — `GET /` returning 302 turned out to be Filament's own unscoped
  `/` redirect-to-tenant route winning over app's domain-scoped `home`
  route for test's request; not regression worth chasing for what was
  just unmodified example test).

  Fixed 2026-07-28, so *reappearance* is regression not baseline:
  billing/tenancy rename fallout (7 files), Filament `{tenant}`
  parameter failures (14), missing tenant factories (11),
  `ClientsPluginTest` (deleted — its module gone), and chat tests that
  never provisioned tenant database.

- **A `Class "…" not found` failure in test usually rename tests
  weren't carried through, not broken autoloader.** Billing/tenancy
  split (`de06293`) renamed most of `app/Actions/Billing` and
  `app/Actions/Tenancy`, replaced `App\Data\Billing\CheckoutData` with
  `TenantRegistrationData` (user-supplied registration payload) and
  `TenantProvisionData` (that payload plus Stripe/central-user ids). Seven
  test files kept old names, errored at *autoload* time, so each one
  reported as several failures, drowned real signal; repaired
  in `438f12f`. Map, for anything still stale:
  `CreateTenant` → `CreateTenantWithOwner` (renamed back to `CreateTenant` on
  2026-08-04 — see tenant-provisioning.md), `DevCheckout` →
  `StartLocalCheckout`, `ReconcileTenantSubscription` →
  `LinkSubscriptionToTenant`, `CreateStripeSubscription`/`CreateMockSubscription`
  → `RecordSubscription`, `MakeFirstUserAdmin` → `FinalizeTenantProvisioning`,
  `PendingTenantProvision::STATUS_*` → `App\Enums\TenantProvisionStatus`.

  Three more files (`CreateSubscriptionTest` → `RecordSubscriptionTest`,
  `StripeCheckoutTest` → `StartSubscriptionCheckoutTest`, `TenantAdminAuthTest`)
  repaired in same pass; had been in this list as "pre-existing
  failures", were nothing of sort.

  Renaming symbol isn't always whole fix. `InterviewShowcaseTest`
  called `CreateTenantWithOwner` directly, still failed, since admin
  promotion moved out of tenant creation into `FinalizeTenantProvisioning`,
  which only `ProvisionTenant` dispatches — see
  `.claude/rules/tenant-provisioning.md`. Test meaning "provision
  tenant" must go through `ProvisionTenant`, not creation step.

  **Now caught statically.** `phpstan.neon` includes `tests/`, so dead
  reference is type-check error at moment of rename rather than
  runtime error discovered by whoever next runs that file. Adding it
  surfaced 89 errors, 24 real dead references across seven
  files nobody had run — including four more `PendingTenantProvision::STATUS_*`
  sites. Remainder is test-idiom noise (`property.nonObject` on Livewire
  test helpers, similar) captured in `phpstan-baseline.neon`; baseline
  exists so *new* errors fail immediately, shrinking it ordinary
  cleanup, not prerequisite.

  Run PHPStan after any rename — takes seconds, doesn't need a database. Use
  the `tmpDir`-override invocation in `.claude/rules/static-analysis.md`, not
  a bare `vendor/bin/phpstan analyse` (uid-owned cache aborts the run) and not
  `vendor/bin/sail …` (no Sail in this repo). Note the run is currently red on
  `main` — 28 errors outside the baseline as of 2026-08-29 — so diff your
  count against `git stash` rather than reading any error as yours.

  **Can't see factories.** At the time (pre-extraction, saas-m), Laravel resolved
  factory from model's namespace below `App\Models` — `App\Models\Tenant\User`
  looked for `Database\Factories\Tenant\UserFactory` and nothing else — that
  name built from string at runtime, so no reference for static analysis
  to check. Both tenant factories missing or in wrong namespace, cost
  11 failures only full run could reveal. **Post-extraction, this package
  overrides Laravel's own convention entirely** — `Numerosis::factoryNameFor()`
  (registered via `Factory::guessFactoryNamesUsing()` in
  `NumerosisServiceProvider`) matches on any `\Models\` segment, not literally
  `App\Models`, and always resolves to `Nvade\Numerosis\Database\Factories\{suffix}Factory`
  — see `.claude/rules/host-integration-quickstart.md`'s `#[UseFactory]` bullet
  for the trap this creates for a *host's own* model under `App\Models\`.
  The underlying lesson stands regardless of mechanism: when adding a model
  under a new `Models\*` sub-namespace (package or host), add the matching
  factory sub-namespace with it — nothing statically checks the pairing.

## `putenv()` in a test only wins on the first app boot in the process

- **`Illuminate\Support\Env::$repository` is static, built once per process,
  and wrapped in phpdotenv's `ImmutableWriter` — whose `$loaded` array
  accumulates across every app boot. That turns "set env, boot app" into
  something that works run alone and silently reverts run in the suite.**
  `ImmutableWriter::write()` refuses to overwrite a variable only while
  `isExternallyDefined()` is true, and that is
  `$this->reader->read($name)->isDefined() && ! isset($this->loaded[$name])`.
  Once the writer has written a key *once*, it is no longer "external", so the
  next `Dotenv::load()` overwrites it freely.

  Every Testbench boot runs `LoadEnvironmentVariables`, which loads
  `vendor/orchestra/testbench-core/laravel/.env` — and that file sets
  `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`.
  So on the **first** boot in a process a test's own `putenv()` wins (the
  values really are external, `$loaded` is empty); on **every boot after
  that** the `.env` clobbers them.

  `tests/Feature/FreshHostTest` is the one test that sets its environment this
  way, and it failed exactly this way: green under
  `--filter=FreshHostTest`, red in the full suite. The symptom does not look
  like an env problem at all — `DB_CONNECTION` reverted to `sqlite`,
  Testbench's `LoadConfiguration::configureDefaultDatabaseConnection()` saw
  `sqlite` with no database file and rewrote `database.default` to its
  in-memory `testing` connection, and `HostConfig` then cloned *that* into the
  `central` connection. Meanwhile `DB_DATABASE` is **not** in that `.env`, so
  it alone survived — producing a connection reported as
  `Connection: sqlite, Database: testing_fresh_host`, an incoherent
  MySQL/SQLite mix that reads like a `HostConfig` bug.

  Fix: discard the memoized repository before `parent::setUp()`, so the next
  `getRepository()` rebuilds with a fresh, empty `ImmutableWriter`.
  `Env::enablePutenv()` is the public way to do it (it nulls the repository;
  the putenv adapter is on by default, so nothing else changes). Do it in
  `tearDown()` too, so this test's own `$loaded` state is not what breaks
  whichever test boots next.

  **Tell, if this resurfaces:** a test that passes under `--filter` and fails
  in a full run, where the failure names a connection/driver/queue nobody
  configured. Check `config('database.default')` right after `parent::setUp()`
  against the `putenv()` above it before suspecting `HostConfig`.

## The central schema drops `jobs`/`cache`/`sessions`, and stock Laravel defaults use all three

- **`2026_01_07_195854_remove_redundant_tables.php` drops `cache`,
  `cache_locks`, `sessions`, `failed_jobs`, `jobs` and `job_batches` on the
  reasoning that Redis makes them redundant — but nothing in
  `docs/host-requirements.md` requires Redis, and a fresh Laravel install's
  own `.env` ships `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
  `SESSION_DRIVER=database`.** `failed_jobs` was already recreated
  (`2026_07_28_233114`, see `.claude/rules/exception-handling.md`) for exactly
  this class of reason; `jobs`/`cache`/`cache_locks`/`sessions` were not.

  Found 2026-08-29 while repairing `FreshHostTest`, which boots with
  Testbench's skeleton `.env` (same defaults as a fresh app) and died on
  `Base table or view not found: 1146 Table 'jobs' doesn't exist` thrown from
  inside `Tenant::create()` — the `TenantCreated` `JobPipeline` is
  `shouldBeQueued(true)`, so the *first* tenant a stock-configured host ever
  creates hits this. Then, past that, on
  `delete from cache where key in (...)` from Spatie's permission registrar
  clearing its own cache during tenant seeding.

  **Fixed 2026-08-29 (Phase 0.3): `remove_redundant_tables` no longer drops
  `jobs`.** It was left alone at first because recreating the table ships a
  new migration to every existing install — but the maintainer confirmed
  2026-08-29 that **there are no existing installs and the app is not live**,
  so that objection is void; the `Schema::dropIfExists('jobs')` line was
  simply deleted from the central migration. `FreshHostTest` still pins
  `QUEUE_CONNECTION=sync`/`CACHE_STORE=array` for its own reasons (a real
  fresh host now gets a working `jobs` table regardless, since it's never
  dropped). `cache` was deliberately left dropped, not simply
  "add the table back": `CacheTenancyBootstrapper` isolates tenants with cache
  *tags* and Laravel's `database` store is not taggable, so a host on
  `CACHE_STORE=database` is outside what this package supports regardless of
  whether the table exists (see `.claude/rules/tenant-caching.md`). `jobs` is
  the unambiguous one — it has a first-party consumer and no such caveat.
  `job_batches` has neither (nothing here uses `Bus::batch()`, only
  `Bus::chain()`).

## `TestCase::getEnvironmentSetUp()` runs *after* providers register, not before

- **This inverts a real host's config-file-then-providers order, and breaks
  the intuitive "delete a Config::set(), let `HostConfig` backfill it"
  refactor.** `Orchestra\Testbench\Concerns\CreatesApplication::
  resolveApplicationBootstrappers()` calls
  `$app->make(RegisterProviders::class)->bootstrap($app)` — which is what
  fires `NumerosisServiceProvider::packageRegistered()`, and therefore
  `HostConfig::apply()` — **before** it calls `getEnvironmentSetUp($app)`.
  On a real host, `LoadConfiguration` reads every `config/*.php` file long
  before any provider registers, so `HostConfig::apply()` sees the host's
  real values. In this harness, `HostConfig::apply()` runs first and only
  ever sees Testbench's and stancl's own stock defaults — every
  `Config::set()` call inside `getEnvironmentSetUp()` happens strictly
  *after*, and simply overwrites whatever `HostConfig` already decided.

  Tried during Phase 6 of `.claude/plans/better-dx.md`: deleted the
  `tenancy.*`/`database.connections.central`/`auth.guards.*`/`session.domain`
  block from `getEnvironmentSetUp()` on the theory that `HostConfig` would
  backfill every one of them, the same proof-by-deletion Phase 4 made safely
  for `numerosis.models.*`. It does not generalise: **`numerosis.models.*`
  is read lazily**, at the moment a test body calls `Numerosis::model()`
  (long after `getEnvironmentSetUp()` has already run, so it sees this
  method's real `database.default`/`numerosis.domains.*` etc) — but
  `HostConfig::apply()` computes its OWN values once, synchronously, during
  registration, and anything it derives from a key this method also sets
  (`database.connections.{database.default}`, `numerosis.domains.central`,
  stancl's own stock `tenancy.migration_parameters`) gets the pre-this-method
  version. Deleting the block produced a `central` database connection
  cloned from Testbench's own stock `sqlite`/`:memory:` default instead of
  the real MySQL one, an empty `tenancy.central_domains` (so
  `Numerosis::routes()` registered no central route group at all), and a
  bogus stock tenant-migration path validated as if real — 319 of 526 tests
  failed. Restored in full; see the comment left in
  `getEnvironmentSetUp()` at the point of restoration.

  **The tell, if this is attempted again:** a `QueryException` naming the
  `central` connection against a `:memory:`/`sqlite` database, or a 404 on a
  route that's registered but domain-bound to the wrong host. Neither reads
  like a config-ordering bug on its own.

  ## Suggested better approach

  Not pursued here — the safe way to prove a `HostConfig` normalization
  covers what a real host needs is `HostConfigTest`'s own pattern
  (`rebootPackage()`: mutate config live, call `packageRegistered()` again
  mid-test, assert the result), not deleting the equivalent line from
  `TestCase.php` and hoping boot order cooperates. That pattern already
  covers all 17 `HostConfig` normalizations independently of this ordering
  trap, which is exactly why `InstallNumerosisCommand`'s `verify*()` methods
  could be narrowed with confidence even though `TestCase.php` itself could
  not be trimmed the same session.

## Isolation

- **`Tenant::unsetEventDispatcher()` static, process-wide.** Single
  test calling it silences model events for *every* later test in same
  process. Why `TestCase` drops tenant databases with direct
  `DROP DATABASE` instead of relying on `TenantDeleted -> DeleteDatabase`:
  with dispatcher unset, listener never fires, database leaks.
  Teardown drops names `CloneTenantSchema` recorded plus those derived
  from surviving `tenants` rows, since tests routinely delete tenant
  themselves.

- **`TenantFactory` ids must stay subdomain-safe.** Id becomes both
  subdomain and physical database name. Used to be raw
  `faker->company()`, producing names like `Barrows, Buckridge and Bins` —
  containing commas, spaces, apostrophes. Apostrophes broke stancl's
  unquoted `SCHEMA_NAME = '...'` lookup, crashed teardown, orphaned
  database. Now `Str::slug()`ed, matching what registration
  wizard actually allows.

- `tenancy:prune-orphaned-databases` (`--dry-run`, `--force`) drops tenant
  databases with no matching tenant record. Written after ~300 had
  accumulated. Useful for cleaning up after crashed run.

## Why tenant creation is fast (`Tests\Support\CloneTenantSchema`)

- **`QUEUE_CONNECTION=sync` makes tenant creation synchronous**, so every
  test creating `Tenant` pays for `TenantCreated` pipeline inline. Real
  pipeline (`CreateDatabase` + `MigrateDatabase` + `SeedTenantDatabase`)
  costs **~1.9s per tenant**; 15 test files create tenants, most
  several times — bulk of ~370s runtime.

  Tests therefore swap pipeline for `CreateDatabase` +
  `Tests\Support\CloneTenantSchema`, which copies template tenant database
  built once per process. Measured at **~0.19s per tenant**.

  Switching tests to non-sync queue *not* alternative: no worker runs
  during tests, so tenant databases would simply never be created, every
  test entering tenant context would fail.

- **Swap happens in `tests/Pest.php`** via
  `TenancyServiceProvider::$tenantCreatedJobs`, exists solely so tests
  can override list without app code referencing `Tests\` namespace.
  Pest loads `Pest.php` before any test class, what makes
  override land before first app boot — assigning from `setUp()` would
  be too late, since provider reads property while booting. Has to
  be global like this since suite is mixed: most test files are
  PHPUnit-style classes that `use RefreshDatabase` directly, never go
  through `uses()`.

- **Nothing in `CloneTenantSchema` may touch default connection.**
  `RefreshDatabase` holds open transaction there, MySQL implicitly
  commits on DDL, so single `CREATE TABLE`/`DROP DATABASE` through `DB`
  facade's default connection ends that transaction, every later test runs
  without isolation. That — not laziness — what made first attempt at
  this produce 117 unrelated failures. All its statements go through
  `central` connection or purpose-built connection pointed at tenant
  database; migrate/seed jobs safe for same reason, since they
  work through stancl's separate `tenant` connection. Given that, building
  template lazily on first use is fine.

- **Template copied with `SHOW CREATE TABLE`, not `CREATE TABLE …
  LIKE`.** `LIKE` copies columns and indexes but silently drops foreign keys,
  which would let tenant databases behave differently under test than in
  production. Replayed DDL names foreign keys unqualified, so must run
  on connection whose *default database is tenant* — otherwise keys
  attach to central testing database. Generated columns
  (`add_ability_and_context_virtual_columns_to_permissions`) can't be
  inserted into, excluded from row copy.

- **Template must survive teardown.** Its database name matches same
  `tenant%` pattern `TestCase::deleteTenantDatabases()` drops, so that query
  excludes `CloneTenantSchema::templateDatabase()` explicitly. Without
  exclusion every test rebuilds template, change is net loss.

- **Tenant seeder resolves `tenant` connection**, which only exists
  once tenancy has bootstrapped. Template therefore has to be built by
  invoking real `CreateDatabase`/`MigrateDatabase`/`SeedTenantDatabase`
  jobs (via `app()->call()`, since their `handle()` methods take injected
  dependencies) rather than hand-rolling migrate + seed.

## Why parallel was dropped

`--parallel` implemented, measured, removed. Don't reintroduce it
without reading this.

- **Laravel's parallel wiring not enough on its own.**
  `Illuminate\Testing\Concerns\TestDatabases` calls `ensureSchemaIsUpToDate()`
  only for `DatabaseTransactions`, never for `RefreshDatabase`. With
  `RefreshDatabase` each worker *process* runs its own `migrate:fresh`, and
  paratest starts fresh process per batch of test files — so batch re-drops
  schema sibling workers still running tests against. Pending
  exclusive metadata lock queues ahead of every later request, live test's
  second connection blocks behind it, `lock_wait_timeout` defaults to
  year. That's hang, not slow run.

- **Suppressing rebuild not sufficient either.** Bootstrap that
  migrated each token database once under MySQL named lock, then pinned
  `RefreshDatabaseState::$migrated` narrowed window but didn't close it:
  any process whose migrations-table probe came back false still migrated
  mid-run, deadlocked same way.

- **Global cleanup collides with sibling workers.** Workers share token
  database, central-connection rows must be deleted outright since
  nothing rolls them back. `DELETE FROM users` in one worker's teardown blocks
  on another worker's open `RefreshDatabase` transaction for full
  `innodb_lock_wait_timeout`.

- **Two runs at once catastrophic.** Token databases keyed by worker
  slot, so second concurrent run collides on `testing_test_1..8` and on
  tenant schema prefixes. Several apparent hangs were only ever this. Before
  diagnosing anything, confirm nothing else running:
  `sail exec laravel.test bash -lc "ps -eo pid,cmd | grep '[p]est'"`.

Template clone below is where speed actually comes from, orthogonal
to all this — single-process, no locking semantics.

## Possible next step

  Reuse **one tenant database per process**, resetting between tests by
  truncating and re-copying seeded rows, instead of creating and dropping
  database per tenant. `CloneTenantSchema` already caches blueprint
  (table list + copyable columns), so `INSERT … SELECT` half reusable,
  only DDL half skipped. Removes ~25 `CREATE TABLE`s per
  tenant plus one `DROP DATABASE` per test in teardown.

  Two things block *literally* shared tenant, have to be designed around:
  tenant-database writes never rolled back (`RefreshDatabase` transacts
  only default connection), and transaction can't substitute for that
  since `DatabaseTenancyBootstrapper` purges and reconnects `tenant`
  connection on every `initialize()`/`end()`. Hard row-reset avoids both.
  Provisioning tests (`CreateTenantTest`, `ProvisionTenantTest`,
  `MakeFirstUserAdminTest`, `TenantProvisioningSignalTest`) must keep creating
  own tenants — testing pipeline itself.