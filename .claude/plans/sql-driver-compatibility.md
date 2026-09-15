# SQLite and PostgreSQL compatibility

**Status: executed on `feat/sql-driver-compatibility`, 2026-09-16, except
PostgreSQL verification.** Written 2026-09-12 on branch
`refactor/provisioning-pipeline`, widened to PostgreSQL 2026-09-15. Option 2
was chosen: PostgreSQL at production parity, SQLite for development.

All six phases are built. MySQL is green (815 passed) and SQLite is green
(802 passed, 19 skipped, no leaked tenant file), both including an
end-to-end `tenancy:provision` run across a real queue worker reaching
`status=completed`.

**PostgreSQL is written and unverified.** The `pdo_pgsql` extension is not
installed on this machine, so no PHP has ever connected to the `postgres:17`
container this branch adds. Its four SQL assumptions were checked directly
with `psql` — `WITH (FORCE)`, `WITH TEMPLATE`, `pg_database` matching, and
`session_replication_role = 'replica'` — and the suite has not run on it.
Install `php8.5-pgsql`, then `NUMEROSIS_TEST_DRIVER=pgsql composer test`
before trusting the CI axis this branch turns on.

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

For PostgreSQL the same holds and more cheaply. `PostgreSQLDatabaseManager`
issues `CREATE DATABASE "name" WITH TEMPLATE=template0` and is mapped to
`pgsql` in stancl's stock config (`assets/config.php:64`). This repo publishes
only `config/numerosis.php` and never overrides `tenancy.database.managers`,
so the Postgres manager is already wired with no config change at all.
A `PostgreSQLSchemaManager` also exists, which gives one *schema* per tenant
instead of one database; that is a different topology and this plan does not
adopt it. Assume database-per-tenant throughout.

Laravel's schema grammars are also less of an obstacle than they look.
`SQLiteGrammar::$modifiers` includes `VirtualAs` and `StoredAs`, so generated
columns themselves are supported. It has no `After` modifier, so every
`->after()` in `database/migrations/**` is ignored on SQLite, which changes
column order and nothing else. `PostgresGrammar::$modifiers` has both
generated-column modifiers and likewise no `After`, so `->after()` is inert
there too. One caveat: `modifyVirtualAs()` emits
`generated always as (...) virtual`, which PostgreSQL only accepts from 18
onward — phase 1 removes the only `virtualAs()` in the tree, so this stops
mattering.

So the blocking set is small and specific, and most of it is not about
tenancy at all.

## What actually blocks each driver

S = blocks SQLite, P = blocks PostgreSQL.

| Blocker | Where | S | P | Fix |
|---|---|:-:|:-:|---|
| `SUBSTRING_INDEX()` in a generated column | `database/migrations/tenant/2025_12_17_035929_add_ability_and_context_virtual_columns_to_permissions.php:14-15` | ✓ | ✓ | Phase 1 |
| `INFORMATION_SCHEMA.SCHEMATA` to list tenant databases | `src/Console/Commands/PruneOrphanedTenantDatabases.php:48` | ✓ | ✓ | Phase 2 |
| Backtick-quoted `DROP DATABASE` | `PruneOrphanedTenantDatabases.php:82`, `src/Testing/CleansUpTenancyDatabases.php:327` | ✓ | ✓ | Phase 2 |
| `SET FOREIGN_KEY_CHECKS` has no session-level Postgres equivalent | `CleansUpTenancyDatabases::withoutForeignKeyChecks()` | — | ✓ | Phase 2 |
| Suite hardcodes MySQL for three connections | `tests/TestCase.php:252-282,627` | ✓ | ✓ | Phase 3 |
| Template cloning uses `SHOW CREATE TABLE` and `information_schema.tables` | `tests/Support/CloneTenantSchema.php:276,306` | ✓ | ✓ | Phase 3 |
| CI has one database and no axis for a second | `.github/workflows/run-tests.yml` | ✓ | ✓ | Phase 4 |
| Two driver guards accept only `mysql` and `mariadb` | `InstallNumerosisCommand::verifyDatabaseConnections()`, `ProvisionTenantCommand::handle()` | ✓ | ✓ | Phase 5 |
| Case-insensitive collation assumed for string comparison | suite-wide; `utf8mb4_0900_ai_ci` at `tests/TestCase.php:264` | — | ✓ | Phase 3 |

Things that look like blockers and are not. `InstallNumerosisCommand:400`
already gates its `lock_wait_timeout` check on the driver being `mysql` and
needs no change on either driver. `$table->json()` appears in six migrations
and is portable: SQLite stores it as `TEXT`, Postgres as native `json`, and
Laravel's JSON operators work against both. `DB::raw('created_at')` in
`2026_07_27_010100_add_provisioned_at_to_tenants_table.php:25` is a bare
column reference and is portable. The two `whereRaw('lower(email) = ?')` call
sites (`StoreInvitationRequest:46`, `LoginWithSocialAccount:107`) already
normalise case explicitly and are portable.

### The two Postgres-only items, in detail

**Foreign-key checks.** `CleansUpTenancyDatabases::deleteCentralWrites()`
deletes dirty central tables in arbitrary order and relies on
`SET FOREIGN_KEY_CHECKS = 0` to make that safe; the method documents that it
does not track relationships between tables. On SQLite this degrades
acceptably — `withoutForeignKeyChecks()` returns false, and SQLite enforces
foreign keys only when `PRAGMA foreign_keys` is on, which Laravel sets per
connection. On PostgreSQL constraints are always enforced and there is no
session switch, so the deletes fail on referential order. The options are
`TRUNCATE ... CASCADE` over the dirty set in one statement, or session
`SET CONSTRAINTS ALL DEFERRED` (only works for constraints declared
`DEFERRABLE`, which ours are not). `TRUNCATE ... CASCADE` is the one to take:
one statement, no ordering, and it matches the existing "the rows are all
going regardless" comment.

**Collation.** The suite's MySQL connection sets
`utf8mb4_0900_ai_ci`, which is accent- and case-insensitive, so
`where('email', $mixedCase)` matches today. PostgreSQL compares
case-sensitively by default. Any test or code path relying on that is a
silent behaviour difference, not an error, so it surfaces as a failing
assertion somewhere unrelated. Expect to find some during phase 3; the fix is
in core (normalise before comparing) rather than in the test.

## What we would be promising

The two drivers promise very different things and should not be decided
together.

**SQLite** allows one writer per database file. This architecture splits along
that grain better than most, since every tenant gets its own file and
therefore its own writer. The central database does not split. One file
carries every tenant's users, memberships, subscriptions, payment plans,
invitations, provision rows and the `jobs` table, and the provisioning chain
writes to `tenant_provisions` on every step transition.

**PostgreSQL** has no such ceiling. It is a peer of MySQL for this workload,
and "production parity" is a real option for it in a way it is not for SQLite.
Its cost is entirely in phases 3 and 4 — a second real database service in CI
and a second connection array in the harness — plus the collation work above.

That makes three different products, and the choice decides how much of
phases 3 and 4 is needed.

1. **SQLite for local development and small deploys; MySQL only in
   production.** SQLite is supported for running the package on one machine,
   for a host evaluating it, and for deployments where concurrent signups are
   not a concern. Phase 3 parameterises the suite but CI runs the full matrix
   on MySQL and a reduced smoke subset on SQLite. Cheapest, and matches what
   the driver is actually good at here.
2. **PostgreSQL at production parity, SQLite for development.** Phase 3 is
   done once and serves both. CI runs the full suite on MySQL and PostgreSQL
   and a smoke subset on SQLite. This is the largest version of the plan and
   the one that actually widens who can adopt the package, since "we are a
   Postgres shop" is a real reason a host cannot use it today and "we do not
   want to install MySQL locally" is not.
3. **Neither, and delete the wrong claim.** Leave the package MySQL-only,
   land phase 1 because it is an improvement regardless, and fix the three
   documentation claims so they say what is actually true. Costs almost
   nothing and leaves the door open.

Recommendation is option 2 if anyone has asked for PostgreSQL, and option 1 if
nobody has. Phase 3 is the same work in both, so the marginal cost of adding
Postgres once SQLite is parameterised is one connection array, one CI service
and the collation fixes. Option 3 is the right answer if nobody is asking for
either, because phases 3 and 4 are the whole cost and option 3 skips both.

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
migration. This removes the single most driver-specific construct in the tree:
`SUBSTRING_INDEX` is MySQL-only (SQLite has no equivalent, Postgres spells it
`split_part`), and `virtualAs` itself needs PostgreSQL 18. It buys nothing on
MySQL either, and it makes the attribute behave the same on every connection
for the first time.

Note for whoever executes this: a host may be querying these columns even
though core does not. Deleting the migration is a breaking change for such a
host and belongs in the changelog, not in a silent edit.

### 2. Driver-branch the places that name and drop databases

Three call sites, one shared shape: resolve a driver, branch, and keep the
existing safety property.

`PruneOrphanedTenantDatabases:48` issues
`SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?`
to find tenant databases with no matching tenant row.

- SQLite: glob `database_path()` for the same prefix.
- PostgreSQL: `SELECT datname FROM pg_database WHERE datname LIKE ?`.
  `information_schema.schemata` exists on Postgres but lists *schemas*, not
  databases, so it would silently return the wrong set — a query that
  succeeds and finds nothing, which is worse than one that errors. This is
  the single most important branch in the phase.

The surrounding filter that drops non-string names is load-bearing, because
the command issues `DROP DATABASE`, and the equivalent care is needed for
`unlink()`.

`PruneOrphanedTenantDatabases:82` and `CleansUpTenancyDatabases:327` both
build `DROP DATABASE IF EXISTS \`name\`` by escaping backticks. Backticks are
MySQL identifier quoting; Postgres needs `"name"` with doubled `"`, and SQLite
needs `unlink()`. Two further Postgres-only facts about `DROP DATABASE` that
will bite in teardown, not in the happy path:

- It cannot run inside a transaction block.
- It fails while any other session is connected to the target. Postgres 13+
  takes `WITH (FORCE)`; use it, since teardown has no reason to be polite.

`CleansUpTenancyDatabases:327` has no driver check at all today. On SQLite
that is a syntax error, so it fails loudly in teardown rather than leaking.
The name resolution above it at `:281` already works on all three, because it
reads `TenantWithDatabase::database()->getName()` and each of stancl's
managers returns the right thing there.

`CleansUpTenancyDatabases::withoutForeignKeyChecks()` gains a Postgres path as
described under "The two Postgres-only items" — or, more precisely, its caller
`deleteCentralWrites()` gains a `TRUNCATE ... CASCADE` branch and the
FK-checks helper stays MySQL-only.

This trait ships to hosts, so every branch here is host-facing and needs its
own coverage.

### 3. Parameterise the suite by driver

`tests/TestCase.php:255-276` builds one MySQL connection array and assigns it
to `database.default`, `central` and `tenant`. The array carries
`collation: utf8mb4_0900_ai_ci`, `strict`, `engine`, `unix_socket` and a
`Mysql::ATTR_INIT_COMMAND` setting `lock_wait_timeout` and
`innodb_lock_wait_timeout`. None of that survives a driver switch. The
Postgres equivalent of the init command is `lock_timeout` /
`statement_timeout` / `idle_in_transaction_session_timeout` as connection
options; SQLite has no equivalent and the whole block drops.

Introduce a driver selector read from the environment, defaulting to MySQL so
nothing changes for anyone who does not opt in. Build the connection array
per driver behind it. `parallelAwareDatabase()` and `ensureDatabaseExists()`
both assume a MySQL server: `:627` opens a raw `PDO('mysql:host=...')` and
needs a `pgsql:` DSN branch (connecting to the `postgres` maintenance
database) and a SQLite path that creates a file.

`tests/Support/CloneTenantSchema.php` is the harness's speed optimisation,
copying a template tenant database instead of migrating and seeding each
time. It is MySQL-specific at `:276` (`SHOW CREATE TABLE`) and `:306`
(`information_schema.tables`).

- SQLite: a file copy, simpler and faster than what MySQL needs.
- PostgreSQL: `CREATE DATABASE new WITH TEMPLATE template_db` — native,
  server-side, and it copies foreign keys, which is the exact property the
  `SHOW CREATE TABLE` comment says `CREATE TABLE ... LIKE` loses. Same
  connected-sessions restriction as `DROP DATABASE` applies to the template.
  `information_schema.tables` at `:306` is portable to Postgres as written,
  but the filter is `table_schema = ?` against a *database* name, which on
  Postgres means the schema (`public`) — another query that succeeds with the
  wrong answer rather than erroring.

Both non-MySQL paths make the harness simpler, not more complicated. This is
the one place where the port pays for itself.

Four test files are inherently MySQL-only and need skipping on the other
drivers: `tests/Feature/Database/LockWaitTimeoutTest.php`,
`tests/Feature/Testing/CleansUpTenancyDatabasesTest.php`,
`tests/Feature/Boot/HostConfigTest.php` for its lock-timeout assertions, and
`tests/Feature/FreshHostTest.php`, which builds its own host with a raw
`PDO('mysql:host=...')`. `InstallNumerosisCommandTest` needs its new driver
assertions revisited alongside phase 5.

On PostgreSQL, add the collation sweep: after the suite is green on Postgres
structurally, look at every failure that is an assertion rather than an error,
because those are the case-sensitivity differences and they are core bugs, not
test bugs.

Size this honestly before starting. It is 684 tests, and the work is not
uniform: most tests do not care about the driver, and the ones that do care
are the ones this package exists to get right.

### 4. Add the CI axis

`.github/workflows/run-tests.yml` currently runs two jobs, PHP 8.5 and 8.4
against Laravel 13, with a single `mysql:8.4` service. A full `database` axis
multiplies, so pick per option:

- Option 1: keep the two MySQL jobs, add one SQLite job running a named
  subset. Three jobs, no service added.
- Option 2: add `postgres:17` as a second service and a `database` axis of
  `mysql`/`pgsql` over both PHP versions (four jobs), plus the one SQLite
  subset job. Five jobs.

The `mysql:` service comment repeats the incorrect `CREATE DATABASE` claim
and is revised here.

### 5. Relax the two driver guards

Both were added on 2026-09-12 and both currently accept only `mysql` and
`mariadb`:

- `InstallNumerosisCommand::verifyDatabaseConnections()` (`:430`) fails the
  install when the central driver is anything else.
- `ProvisionTenantCommand::handle()` (`:56`) refuses to queue a chain up
  front.

They exist because an unsupported driver otherwise surfaces as a seeder dying
five queued jobs later, which is a bad failure to debug. Keep that property.
Both messages cite "MySQL-only generated columns" as a reason, which phase 1
makes false; they need rewording regardless of which drivers are added.

Widen the allowed set to whichever drivers the chosen option ships. Under
option 1, `sqlite` becomes a warning that names what is not supported at
scale, not a refusal. Under option 2, `pgsql` joins `mysql` and `mariadb` as a
plain accept with no warning. Their tests move with them.

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
  template file and the Postgres equivalent is a stale template database, and
  the same trap applies to both.

Two new facts worth recording if phase 2 or 3 lands: that
`information_schema.schemata` and `information_schema.tables` both answer the
wrong question on Postgres without erroring, and that `DROP DATABASE` there
needs `WITH (FORCE)` in teardown.

## Verification

- `composer lint` and `composer test` green after every phase, on MySQL,
  which stays the default throughout.
- Phase 1 in isolation: assert `ability` and `context` return the same values
  after the change as the generated columns produced before it, on a tenant
  connection, and that they now also work on the central connection where
  they previously returned null.
- Phase 2 in isolation: `PruneOrphanedTenantDatabasesTest` and
  `CleansUpTenancyDatabasesTest` both need a counterpart per added driver.
  The teardown one matters most, because a trait that fails to drop is how
  tabellio leaked a milestone of databases. For Postgres, the test that
  matters is the one with a second open connection to the database being
  dropped — that is the failure `WITH (FORCE)` exists for, and it will not
  reproduce in a single-connection test.
- The end-to-end proof is the same manual run that found the original
  failure, once per added driver: `composer build`, then
  `php artisan tenancy:provision demo --owner=<global_id>` with
  `php artisan queue:work --queue=provisioning --stop-when-empty`, asserting
  all seven steps reach `done` and `status=completed`. That run is what
  proved `CreateTenantDatabase` and `MigrateTenantDatabase` already work, and
  it is the only check that crosses a real worker boundary, since the suite
  runs `QUEUE_CONNECTION=sync`.
- Drop the tenant databases and files between runs. A stale template is the
  non-MySQL version of the reused-volume trap.
