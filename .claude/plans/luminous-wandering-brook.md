# SQLite compatibility

**Status: not executed.** Written 2026-09-12 on branch
`refactor/provisioning-pipeline`. Nothing below is built. Six phases. Phase 1
is worth landing on its own merits even if the rest is dropped, and phases 2
and 5 are small; phases 3 and 4 carry almost all of the cost and all of the
ongoing cost.

This plan has one open decision in it that changes the size of phases 3 and 4
by a large factor. It is stated below under "What we would be promising" and
is not settled here.

## Context

Three places in this repo assert that the package requires MySQL and that
SQLite cannot host it:

| Claim | Where |
|---|---|
| "MySQL — tenancy needs `CREATE DATABASE`; SQLite cannot host it" | `README.md:46` |
| "MySQL — tenancy needs `CREATE DATABASE`, and the `central` connection has to exist" | `docs/host-requirements.md:58` |
| "Tenant provisioning needs CREATE DATABASE, which sqlite cannot provide" | `.github/workflows/run-tests.yml`, the `mysql:` service comment |

The `CREATE DATABASE` half of that is wrong, and was measured wrong on
2026-09-12. stancl ships
`Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager`, which creates
one file per tenant with `file_put_contents(database_path($name))` and is
wired in `vendor/stancl/tenancy/assets/config.php` alongside the MySQL and
Postgres managers. On a real SQLite provisioning run through the queue,
`CreateTenant`, `CreateTenantDatabase` and `MigrateTenantDatabase` all
recorded `done`. The run died at `SeedTenantDatabase`.

Laravel's SQLite schema grammar is also less of an obstacle than it looks.
`SQLiteGrammar::$modifiers` includes `VirtualAs` and `StoredAs`, so generated
columns themselves are supported. It has no `After` modifier, so every
`->after()` in `database/migrations/**` is ignored on SQLite, which changes
column order and nothing else.

So the blocking set is small and specific, and most of it is not about
tenancy at all.

## What actually blocks SQLite

| Blocker | Where | Shape of the fix |
|---|---|---|
| `SUBSTRING_INDEX()` in a generated column | `database/migrations/tenant/2025_12_17_035929_add_ability_and_context_virtual_columns_to_permissions.php:14-15` | Phase 1 |
| `INFORMATION_SCHEMA.SCHEMATA` to list tenant databases | `src/Console/Commands/PruneOrphanedTenantDatabases.php:48` | Phase 2 |
| `DROP DATABASE` with no driver branch | `src/Testing/CleansUpTenancyDatabases.php:327` | Phase 2 |
| Suite hardcodes MySQL for three connections | `tests/TestCase.php:262-282` | Phase 3 |
| Template cloning uses `SHOW CREATE TABLE` and `information_schema.tables` | `tests/Support/CloneTenantSchema.php:276,306` | Phase 3 |
| CI has one database and no axis for a second | `.github/workflows/run-tests.yml` | Phase 4 |
| Two driver guards accept only `mysql` and `mariadb` | `InstallNumerosisCommand::verifyDatabaseConnections()`, `ProvisionTenantCommand::handle()` | Phase 5 |

Two things that look like blockers and are not. `InstallNumerosisCommand:410`
already gates its `lock_wait_timeout` check on the driver being `mysql` and
needs no change. `CleansUpTenancyDatabases:373` does the same for
`SET FOREIGN_KEY_CHECKS`, correctly returning false so the caller does not
re-enable something it never disabled.

`$table->json()` appears in six migrations and is portable. SQLite stores it
as `TEXT` and Laravel's JSON operators work against it.

## What we would be promising

SQLite allows one writer per database file. This architecture splits along
that grain better than most, since every tenant gets its own file and
therefore its own writer. The central database does not split. One file
carries every tenant's users, memberships, subscriptions, payment plans,
invitations, provision rows and the `jobs` table, and the provisioning chain
writes to `tenant_provisions` on every step transition.

That makes two different products, and the choice decides how much of phases
3 and 4 is needed.

1. **Local development and small deploys.** SQLite is supported for running
   the package on one machine, for a host evaluating it, and for deployments
   where concurrent signups are not a concern. Phase 3 parameterises the
   suite but CI runs the full matrix on MySQL and a reduced smoke subset on
   SQLite. Cheapest, and matches what the driver is actually good at here.
2. **Production parity.** SQLite is a first-class target and the full suite
   runs against both drivers in CI. Doubles the test axis permanently, and
   commits us to answering write-contention questions on the central
   database, which is a design conversation this plan does not contain.
3. **Neither, and delete the wrong claim.** Leave the package MySQL-only,
   land phase 1 because it is an improvement regardless, and fix the three
   documentation claims so they say what is actually true. Costs almost
   nothing and leaves the door open.

Recommendation is option 1. It gets the real benefit, which is that a host
can clone the repo and run it without standing up MySQL, without promising
behaviour under concurrency that the central file cannot deliver. Option 3 is
the right answer if nobody is asking for SQLite, because phases 3 and 4 are
the whole cost and option 3 skips both.

## Phases

Each phase ends with `composer lint` and a green `composer test`. Phases 1 and
2 are independently landable and do not depend on the decision above.

### 1. Replace the generated columns with accessors

`permissions.ability` and `permissions.context` are declared
`virtualAs("SUBSTRING_INDEX(name, ' ', 1)")` and `(name, ' ', -1)`, which is
first word and last word of `name`. They are listed in `#[Guarded]` on
`src/Models/Permission.php:37-40` and documented as `@property string` at
`:20-21`.

Nothing reads them as a query predicate. A grep across `src/`, `packages/`,
`database/` and `tests/` finds them in exactly two files, the model attribute
and the migration that creates them. No `where`, `orderBy` or `groupBy`
names either column.

They are also only ever created on the tenant connection. The central
permission tables at
`database/migrations/central/2025_12_16_153536_create_permission_tables.php`
have no such columns, so `Permission::$ability` is already null for every
central permission row. The generated columns are inconsistent across the two
connections today and nothing has noticed, which is the strongest evidence
that nothing depends on them.

Replace both with accessors on `Permission` that split `name`. Drop the
migration. This removes a MySQL dependency that buys nothing on MySQL either,
and it makes the attribute behave the same on both connections for the first
time.

Note for whoever executes this: a host may be querying these columns even
though core does not. Deleting the migration is a breaking change for such a
host and belongs in the changelog, not in a silent edit.

### 2. Driver-branch the two places that name databases

`PruneOrphanedTenantDatabases:48` issues
`SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?`
to find tenant databases with no matching tenant row. The SQLite equivalent
is a glob of `database_path()` for the same prefix. The surrounding filter
that drops non-string names is load-bearing, because the command issues
`DROP DATABASE`, and the equivalent care is needed for `unlink()`.

`CleansUpTenancyDatabases:327` issues
`DROP DATABASE IF EXISTS` with no driver check at all. On SQLite that is a
syntax error, so this fails loudly in teardown rather than leaking. It needs
an `unlink()` branch. The name resolution above it at `:281` already works on
both, because it reads `TenantWithDatabase::database()->getName()` and
stancl's SQLite manager returns a filename there.

This trait ships to hosts, so the branch is host-facing and needs its own
coverage.

### 3. Parameterise the suite by driver

`tests/TestCase.php:262-282` builds one MySQL connection array and assigns it
to `database.default`, `central` and `tenant`. The array carries
`collation: utf8mb4_0900_ai_ci`, `strict`, `engine`, `unix_socket` and a
`Mysql::ATTR_INIT_COMMAND` setting `lock_wait_timeout` and
`innodb_lock_wait_timeout`. None of that survives a driver switch.

Introduce a driver selector read from the environment, defaulting to MySQL so
nothing changes for anyone who does not opt in. Build the connection array
per driver behind it. `parallelAwareDatabase()` and `ensureDatabaseExists()`
both assume a MySQL server and need SQLite paths.

`tests/Support/CloneTenantSchema.php` is the harness's speed optimisation,
copying a template tenant database instead of migrating and seeding each
time. It is MySQL-specific at `:276` (`SHOW CREATE TABLE`) and `:306`
(`information_schema.tables`). The SQLite equivalent is a file copy, which is
simpler and faster than what MySQL needs. This is the one place where SQLite
support makes the harness better rather than more complicated.

Four test files are inherently MySQL-only and need skipping on SQLite:
`tests/Feature/Database/LockWaitTimeoutTest.php`,
`tests/Feature/Testing/CleansUpTenancyDatabasesTest.php`,
`tests/Feature/Boot/HostConfigTest.php` for its lock-timeout assertions, and
`tests/Feature/FreshHostTest.php`, which builds its own host with a raw
`PDO('mysql:host=...')`. `InstallNumerosisCommandTest` needs its new driver
assertions revisited alongside phase 5.

Size this honestly before starting. It is 684 tests, and the work is not
uniform: most tests do not care about the driver, and the ones that do care
are the ones this package exists to get right.

### 4. Add the CI axis

`.github/workflows/run-tests.yml` currently runs two jobs, PHP 8.5 and 8.4
against Laravel 13, with a single `mysql:8.4` service. Adding a `database`
axis doubles it to four. Under option 1 above, add SQLite as a separate job
running a named subset instead, so the axis does not multiply.

The `mysql:` service comment repeats the incorrect `CREATE DATABASE` claim
and is revised here.

### 5. Relax the two driver guards

Both were added on 2026-09-12 and both currently accept only `mysql` and
`mariadb`:

- `InstallNumerosisCommand::verifyDatabaseConnections()` fails the install
  when the central driver is anything else.
- `ProvisionTenantCommand::handle()` refuses to queue a chain up front.

They exist because an unsupported driver otherwise surfaces as a seeder dying
five queued jobs later, which is a bad failure to debug. Keep that property.
Widen the allowed set to include `sqlite`, and under option 1 change the
message from a refusal into a warning that names what is not supported at
scale. Their tests move with them.

### 6. Documentation and rules

`README.md:46` and `docs/host-requirements.md:58` both state the MySQL
requirement and both cite `CREATE DATABASE` as the reason. Whichever option
is chosen, that reason is wrong and needs replacing with the real one.

Rule files that carry the current measurement and would need updating:

- `.ai/rules/package-host-bootstrap.md` has a section added 2026-09-12
  recording exactly what the SQLite run proved and what the remaining
  blockers are. It is accurate as of writing and becomes historical when this
  plan executes.
- `.ai/rules/testing.md` covers the suite's MySQL assumptions, including the
  container requirement and the reused-volume trap.
- `.ai/rules/tenant-provisioning.md` records that a reused MySQL volume hid a
  broken `SeedTenantDatabase` for months. The SQLite equivalent is a stale
  template file, and the same trap applies.

## Verification

- `composer lint` and `composer test` green after every phase, on MySQL,
  which stays the default throughout.
- Phase 1 in isolation: assert `ability` and `context` return the same values
  after the change as the generated columns produced before it, on a tenant
  connection, and that they now also work on the central connection where
  they previously returned null.
- Phase 2 in isolation: `PruneOrphanedTenantDatabasesTest` and
  `CleansUpTenancyDatabasesTest` both need a SQLite counterpart. The
  teardown one matters most, because a trait that fails to drop is how
  tabellio leaked a milestone of databases.
- The end-to-end proof is the same manual run that found the original
  failure, against SQLite this time: `composer build`, then
  `php artisan tenancy:provision demo --owner=<global_id>` with
  `php artisan queue:work --queue=provisioning --stop-when-empty`, asserting
  all seven steps reach `done` and `status=completed`. That run is what
  proved `CreateTenantDatabase` and `MigrateTenantDatabase` already work, and
  it is the only check that crosses a real worker boundary, since the suite
  runs `QUEUE_CONNECTION=sync`.
- Drop the tenant files between runs. A stale template is the SQLite version
  of the reused-volume trap.
