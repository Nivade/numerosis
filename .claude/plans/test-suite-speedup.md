# Speed up the test suite: template tenant DB + parallel execution

**Status: ⚠️ Partially executed.** Phase 1 (template clone,
`tests/Support/CloneTenantSchema.php`) shipped and is the suite's current
mechanism — see `.claude/rules/testing.md`. **Phase 2 (parallel execution)
was later abandoned entirely** (deadlocks — see
`.claude/plans/parallel-test-isolation.md`'s status note and
`.claude/rules/testing.md` "Why parallel was dropped").

## Context

The suite takes ~370s (~6 min). Root cause is documented in
`.claude/rules/testing.md`: `QUEUE_CONNECTION=sync` means creating a `Tenant`
runs the `TenantCreated` job pipeline synchronously, so every tenant costs
`CreateDatabase` + `MigrateDatabase` + `SeedTenantDatabase` ≈ **1.9s**. 15 test
files create tenants, many of them repeatedly.

The documented fix — migrate and seed one template tenant database per process,
then copy its structure and seeded rows into each new tenant database — was
measured at **0.18s vs 1.9s** (~4.5x). It was prototyped and reverted;
`tests/Support/CloneTenantSchema.php` is the surviving, currently-unreferenced
half of that prototype (committed in `84ca840`).

This plan finishes that work (phase 1) and then adds parallel execution
(phase 2), which multiplies the win by the core count.

### Why the prototype failed, precisely

`.claude/rules/testing.md` records the symptom ("117 failures, corrupted central
testing database") and attributes it to building the template inside a
`RefreshDatabase` transaction. The underlying mechanism is broader and matters
for the per-tenant clone too:

`CloneTenantSchema` issues its `CREATE TABLE` / `INSERT` through the **default**
connection (`DB::statement(...)`, connection `mysql`, database `testing`). That
is the exact connection `RefreshDatabase` holds its transaction on, and MySQL
DDL causes an **implicit commit**. So both the template build *and* every
per-tenant clone silently commit the test transaction and destroy isolation for
every later test.

The existing production path does not have this problem because
`MigrateDatabase` runs `tenants:migrate`, which does its DDL on stancl's
separate `tenant` connection.

So the fix has two parts, not one:
1. build the template outside any transaction (a bootstrap step), **and**
2. run all clone SQL on a dedicated connection, never the default one.

---

## Phase 1 — Template clone

### 1. `tests/Support/CloneTenantSchema.php` (rewrite)

Keep the class name, docblock intent and `flushTemplate()`. Change:

- **Dedicated connection.** Register a runtime connection per clone instead of
  using the `DB` facade default:
  ```php
  config(["database.connections.tenant_clone" => array_merge(
      config('database.connections.central'),
      ['database' => $target],
  )]);
  DB::purge('tenant_clone');
  $conn = DB::connection('tenant_clone');
  ```
  All `CREATE TABLE` / `INSERT` go through `$conn`. Purge again when done so the
  stale PDO is not reused for the next tenant.
- **Copy structure with `SHOW CREATE TABLE`, not `CREATE TABLE ... LIKE`.**
  MySQL's `LIKE` copies columns and indexes but **drops foreign keys**, which
  would make test tenant databases behave differently from real ones. Read
  `SHOW CREATE TABLE \`template\`.\`t\`` and replay the returned DDL on
  `$conn` (whose default database is the target), wrapped in
  `SET FOREIGN_KEY_CHECKS=0` / `=1` so table order does not matter. Running it
  unqualified on `$conn` is what makes the unqualified FK references in that
  DDL resolve to the target schema rather than to `testing`.
- **Copy rows** with `INSERT INTO \`t\` (cols) SELECT cols FROM \`template\`.\`t\``
  on `$conn`. Keep the existing `copyableColumns()` helper — generated columns
  (`add_ability_and_context_virtual_columns_to_permissions`) cannot be inserted
  into and must stay excluded.
- **Split template build out of `handle()`.** `handle()` clones only. Building
  the template lazily from `handle()` is what put DDL inside a transaction;
  make it throw a clear exception if the template is missing instead.
  Expose `public static function buildTemplate(): void`.
- **Keep the real jobs for the template build** — `app()->call([new CreateDatabase($t), 'handle'])`,
  then `MigrateDatabase`, then `SeedTenantDatabase`. As the rules file notes,
  the tenant seeder resolves the `tenant` connection, which only exists once
  tenancy has bootstrapped, so hand-rolling migrate+seed does not work. The
  temporary `Tenant` row is created and deleted with `Tenant::withoutEvents()`
  so it does not re-enter the pipeline.

### 2. `tests/Support/RefreshesDatabaseWithTenantTemplate.php` (new trait)

The template must be built **after** the central `migrate:fresh` and **before**
`beginDatabaseTransaction()`. `RefreshDatabase::refreshTestDatabase()` gives
exactly one such point: `migrateDatabases()`, which runs once per process
(guarded by `RefreshDatabaseState::$migrated`).

It cannot be overridden on `Tests\TestCase`: Pest applies `RefreshDatabase` to
the generated subclass, and trait methods win over inherited parent methods. So
wrap it:

```php
trait RefreshesDatabaseWithTenantTemplate
{
    use RefreshDatabase { migrateDatabases as baseMigrateDatabases; }

    protected function migrateDatabases(): void
    {
        $this->baseMigrateDatabases();

        $this->app[Kernel::class]->setArtisan(null); // migrate:fresh leaves a stale Artisan instance
        CloneTenantSchema::buildTemplate();
    }
}
```

`tests/Pest.php`: swap `RefreshDatabase` for this trait in the `uses(...)` call.

### 3. `app/Providers/TenancyServiceProvider.php`

Make the `TenantCreated` pipeline job list overridable so tests can swap
`MigrateDatabase` + `SeedTenantDatabase` for `CloneTenantSchema`, without app
code referencing the `Tests\` namespace:

```php
/** @var list<class-string> */
public static array $tenantCreatedJobs = [
    CreateDatabase::class,
    MigrateDatabase::class,
    SeedTenantDatabase::class,
];
```
`events()` uses `JobPipeline::make(static::$tenantCreatedJobs)`. Mirrors the
existing `UpdateSyncedResource::$shouldQueue = true` idiom in the same file.

`tests/Pest.php` sets it once at file scope:
```php
TenancyServiceProvider::$tenantCreatedJobs = [CreateDatabase::class, CloneTenantSchema::class];
```
Note `MakeFirstUserAdmin` is **not** in this pipeline — it is dispatched from
`app/Actions/Tenancy/CreateTenant.php` after `AddTenantOwner`, and stays there.
(`.claude/rules/tenant-provisioning.md` still says it is the last pipeline job;
that bullet is stale and should be corrected as part of this work.)

### 4. `tests/TestCase.php`

`deleteTenantDatabases()` drops every `tenant%` schema — which includes the
template. Exclude it, or the template is rebuilt (1.9s) after every single test
and the change is a net loss. Take the name from `CloneTenantSchema` rather than
hardcoding it.

Optionally exclude it in `tenancy:prune-orphaned-databases` too; it has no
`tenants` row by design.

---

## Phase 2 — Parallel execution

`brianium/paratest` is already installed. `artisan test --parallel` gives each
process its own central database (`testing_test_1`, …) via Laravel's
`TestDatabases` hook — but that hook only rewrites the **default** connection.
Two things break without extra work:

1. **`central` connection is not switched**, so all processes would share one
   central database.
2. **Tenant schema names would collide** across processes — tenant ids come
   from `TenantFactory` slugs and from fixed strings in tests, so process 1 and
   process 3 both try to create `tenantacme`. Worse, `TestCase` teardown drops
   every `tenant%` schema, so each process would delete the other processes'
   tenant databases mid-test.

Fix both in `RefreshesDatabaseWithTenantTemplate::beforeRefreshingDatabase()`
(runs before `migrate:fresh`, i.e. before anything touches these):

```php
if ($token = ParallelTesting::token()) {
    config([
        'database.connections.central.database' => config('database.connections.mysql.database'),
        'tenancy.database.prefix' => "tenant{$token}_",
    ]);
    DB::purge('central');
}
```

Then `TestCase::deleteTenantDatabases()` must drop by the **configured** prefix
(it already reads `config('tenancy.database.prefix')` — verify it is not
hardcoded anywhere else), so each process only cleans its own schemas. The
template name inherits the prefix automatically, so each process gets its own
template with no further change.

Add a `composer test` / documented invocation using `--parallel`.

---

## Files

| File | Change |
|---|---|
| `tests/Support/CloneTenantSchema.php` | rewrite: dedicated connection, `SHOW CREATE TABLE` replay, `buildTemplate()` |
| `tests/Support/RefreshesDatabaseWithTenantTemplate.php` | new: template build hook + parallel token config |
| `tests/Pest.php` | use new trait; set `$tenantCreatedJobs` |
| `tests/TestCase.php` | exclude template DB from teardown; prefix-scoped drops |
| `app/Providers/TenancyServiceProvider.php` | `static array $tenantCreatedJobs` |
| `.claude/rules/testing.md` | record the real mechanism + resulting numbers |
| `.claude/rules/tenant-provisioning.md` | fix stale `MakeFirstUserAdmin` bullet |

## Verification

The full suite costs ~6 min, so verify narrow first and run it whole **once** at
the end.

1. `vendor/bin/sail artisan test --compact tests/Feature/CreateTenantTest.php`
   — the tightest tenant-creation test. Confirms clone works at all.
2. `vendor/bin/sail artisan test --compact tests/Feature/MakeFirstUserAdminTest.php tests/Feature/Actions/Tenancy/ProvisionTenantTest.php`
   — confirms seeded rows (roles, users) arrived in the clone.
3. `vendor/bin/sail artisan test --compact tests/Feature/Filament` — heaviest
   tenant-context consumers; also the best signal that test isolation survived
   (broken isolation shows up as later-test failures, not first-test failures).
4. Full suite once, timed, single process — record the new runtime.
5. Full suite once with `--parallel`, timed.
6. `vendor/bin/sail artisan tenancy:prune-orphaned-databases --dry-run` after
   the run — should list nothing (beyond the template), proving no schema leak.

Watch for: tests asserting on foreign-key/cascade behaviour inside a tenant DB
(the reason for `SHOW CREATE TABLE` over `LIKE`), and any test asserting on the
tenant `migrations` table.
