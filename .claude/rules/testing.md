---
topic: testing
updated: 2026-07-31
---
# Test Suite

## Running it

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

- **Known failing tests — don't attribute these to your change.** Measured on
  full run, 2026-07-28: **149 passed, 28 failed, 1 skipped in ~546s**, then
  14 of those 28 fixed (see `.claude/rules/filament-tenancy.md`). Further
  pass on 2026-07-31 fixed rest of that list except lock-wait
  contention itself (see below) — current baseline, full run: **9 failed, 1
  skipped, 313 passed in ~307s**, all 9 same `SQLSTATE 1205` class. Baseline
  suspicious failure against `git stash` before investigating; list moves.

  What's left, by cause rather than file:

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

  Run `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"`
  after any rename. Takes seconds, doesn't need database.

  **Can't see factories.** Laravel resolves factory from model's
  namespace below `App\Models` — `App\Models\Tenant\User` looks for
  `Database\Factories\Tenant\UserFactory` and nothing else — that name
  built from string at runtime, so no reference for static analysis
  to check. Both tenant factories missing or in wrong namespace, cost
  11 failures only full run could reveal. When adding model under
  new `App\Models\*` sub-namespace, add matching factory sub-namespace
  with it.

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