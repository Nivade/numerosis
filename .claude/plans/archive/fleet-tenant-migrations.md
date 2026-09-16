# Rolling migrations across every tenant

**Status: ✅ Executed 2026-09-16** on `feat/fleet-tenant-migrations`, the day
it was revised. Wave 2 of `saas-readiness-roadmap.md`, and the last of it.
Deviations in "What shipped" at the bottom.

## Context

`MigrateTenantDatabase` exists only as a provisioning step
(`src/Actions/Tenancy/MigrateTenantDatabase.php:16`, wired at
`config/numerosis.php`'s `tenancy.provisioning.steps`). It runs once, for one
tenant, at creation. There is no supported way to apply a new tenant migration
to tenants that already exist: ship a column today and the fleet is
inconsistent with no command to fix it.

stancl ships `tenants:migrate`, but nothing here wraps it, tests it, or gives
it what a fleet rollout needs — failure isolation, resume, progress, pacing, a
dry run and a record. Its own `Tenancy::runForMultiple()`
(`vendor/stancl/tenancy/src/Tenancy.php:138`) has no `try`/`finally`, so one
throwing tenant leaves the process initialized against it and every later
tenant in the run migrates into the wrong database.

Outcome: one command an operator runs after deploying a tenant migration, a
row per tenant per run to resume from and read afterwards, and a staff screen
that shows which tenants are behind.

## Settled 2026-09-16

| Question | Ruling |
|---|---|
| Queue under `--queue` | A dedicated `migrations` queue, not `provisioning`. The health endpoint reports provisioning depth as "customers waiting on a signup"; a 4000-job rollout on that queue destroys the signal exactly when a rollout is in flight |
| Staff status screen | In this pass. Wave 2 is the operator-visibility wave and the panel shell already exists |
| Reuse seam | Extract `Actions\Tenancy\MigrateTenant`, taking a `Tenant`. Both the provisioning step and the fleet path call it. A `TenantProvision` is the wrong input: `tenancy:prune-stalled-provisions` deletes completed rows after 30 days, so most existing tenants have none |
| `--rollback` | Not in this pass. A fleet-wide rollback is almost always a mistake, and `tenants:rollback --tenants=acme` already covers the one-tenant case |

## Mechanism

**Drive Laravel's `Migrator` directly, inside `Tenant::runHere()`**, rather
than `Artisan::call('tenants:migrate')`:

- `runHere()` (`src/Models/Central/Tenant.php:328` →
  `src/Concerns/Tenancy/RunsInTenant.php:19`) restores or ends tenancy in a
  `finally`. It is the existing answer to the leak above.
- `Migrator::run($paths, $options)` returns the migrations it applied
  (`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:124`),
  so the run row records names rather than scraped console output.
- It still fires `MigrationsStarted`/`MigrationsEnded`, which
  `Listeners\Tenancy\ForgetTenantColumnListing` depends on.

**The path list is `config('tenancy.migration_parameters')['--path']`**, which
`Boot\HostConfig::tenantMigrationParameters()` already builds from the host's
own `database/migrations/tenant` plus `Numerosis::tenantMigrationPaths()`.
Reading that key — never a hardcoded directory — is what closes the
host-contributed-migrations risk.

`Migrator::pendingMigrations()` is protected, so the query does the diff
itself: `getMigrationFiles($paths)` keyed by `getMigrationName()`, minus
`getRepository()->getRan()`. Guard on `repositoryExists()` first — a tenant
whose database was created but never migrated has no `migrations` table, and
`getRan()` throws there.

## Phases

### 1. Record

`database/migrations/central/2026_09_16_180000_create_tenant_migration_runs_table.php`,
following `create_impersonation_sessions_table.php` for shape: `run_id` (ULID,
indexed), `tenant_id` (FK to `tenants`, cascade), `status`, `started_at`,
`finished_at`, `migrations` (JSON, the applied names), `error`, timestamps,
unique on `(run_id, tenant_id)`.

`Models\Central\TenantMigrationRun` with `CentralConnection`, registered in
`numerosis.models` so `Numerosis::model()` resolves it —
`tests/Feature/Boot/ModelResolverBypassTest.php` fails on any bare static call
to a config-overridable model. `Enums\Tenancy\MigrationRunStatus`:
`Pending`, `Running`, `Succeeded`, `Failed`, `Skipped`.

### 2. Query and action

- `Actions\Queries\GetPendingTenantMigrations::handle(Tenant $tenant): list<string>`
  — the diff above, inside `runHere()`. Answers "is the fleet consistent" on
  its own.
- `Actions\Tenancy\MigrateTenant::handle(Tenant $tenant): list<string>` —
  `Migrator::run()` inside `runHere()`, returning applied names.
- `Actions\Tenancy\MigrateTenantDatabase` (the provisioning step) becomes a
  two-line delegate to `MigrateTenant`. The test template is built by that
  step (`tests/Support/CloneTenantSchema::buildTemplate()`), so every suite run
  exercises the rewiring.

### 3. Job

`Jobs\RunTenantMigration`, one tenant per job, `$tries = 1` — a half-applied
migration retried blindly is worse than a reported failure. Queue name from
`numerosis.tenancy.migrations.queue`, default `migrations`. Writes its own run
row transitions, and records the failure on the row rather than only in
`failed_jobs`.

### 4. Command

`tenancy:migrate`, following `PruneOrphanedTenantDatabases` for signature
attributes and output style:

```
tenancy:migrate [--tenants=*] [--pending] [--dry-run] [--chunk=50]
                [--delay=0] [--queue] [--stop-on-failure] [--resume=<run-id>]
```

- Inline by default; `--queue` dispatches one job per tenant.
- **`--stop-on-failure`, not `--continue-on-failure`.** Continuing is the
  default and a flag that is on by default cannot be turned off; this is the
  same ruling expressed as a flag that works.
- `--chunk` and `--delay` default from `numerosis.tenancy.migrations.*`.
  Iterate `Tenant::query()->orderBy('id')->chunk()`; `--delay` sleeps between
  chunks, because thousands of `ALTER`s saturate a database server.
- `--dry-run` reports pending migrations per tenant through the query and
  writes nothing — not `Migrator`'s `pretend`, which still opens every
  connection to print SQL nobody reads.
- `--resume=<run-id>` skips tenants whose row for that run is `Succeeded`.
- End-of-run summary naming every failed tenant, and a non-zero exit when any
  failed.

### 5. Screen

`resources/views/pages/staff/⚡migrations.blade.php`, in the existing
`staff.` route group and the `numerosis-layouts::staff` navigation: runs
newest-first, failures first within a run, with each tenant's applied
migrations and error. Strings into `resources/lang/en/staff.php`.

### 6. Documentation

`docs/extending.md` gains the rollout procedure, next to the tenant-migrations
seam it already documents: provisioning does not cover existing tenants, this
command does, and a host contributing migrations through
`tenancy.migration_parameters` is included automatically.

## Verification

- `vendor/bin/pest tests/Feature/Console/MigrateTenantsCommandTest.php` and the
  action/screen tests below, then `composer test` and `composer lint` before
  committing.
- Fixture migration directory under `tests/Support/migrations/`, appended to
  `tenancy.migration_parameters['--path']` in the test, so tenants cloned from
  the template are genuinely one migration behind. A second fixture that throws
  for one tenant id is what makes failure isolation testable.

Tests:

- The pending query names exactly the fixture migration, and nothing once it
  has run.
- A tenant failing mid-run does not stop later tenants, is recorded `Failed`
  with its error, and is named in the summary.
- **Tenancy is ended after every tenant, including after a failure** — the
  defect most likely to ship here, and the one that silently migrates tenant
  B's database twice.
- `--dry-run` changes no tenant schema and writes no run rows.
- `--resume` skips `Succeeded` rows and retries only the failed ones.
- The provisioning step still migrates a new tenant after the rewiring
  (covered by the template build, asserted directly too).
- The screen renders a run with no rows yet.

## Risks

- **Long-running lock waits.** MySQL `ALTER` on a large tenant table blocks;
  `LockWaitTimeoutTest` exists because this has bitten. Document `--delay`
  rather than hiding it.
- **A dedicated queue nobody runs a worker for.** `--queue` silently does
  nothing visible if `migrations` has no worker. The command prints the queue
  name it dispatched to, and `docs/extending.md` says a worker must consume it.
- **SQLite runs the suite serially** and one writer at a time, so chunk sizing
  behaves differently there; the driver matrix (`NUMEROSIS_TEST_DRIVER`) is
  what covers it, not extra code.

## What shipped

`tenancy:migrate` (`Console\Commands\MigrateTenants`) with every flag above,
`Jobs\RunTenantMigration` on the `migrations` queue, and a
`tenant_migration_runs` row per tenant per run behind
`Models\Central\TenantMigrationRun` and `Enums\Tenancy\MigrationRunStatus`.

`Actions\Tenancy\MigrateTenant` runs Laravel's `Migrator` inside
`Tenant::runHere()` and returns the applied names;
`Actions\Queries\GetPendingTenantMigrations` answers the same question without
writing. `MigrateTenantDatabase`, the provisioning step, is now a delegate to
the first of those. The staff panel gained `staff.migrations`.

Six deviations from the plan above:

- **`Actions\Tenancy\RecordTenantMigrationLeg` was added**, not planned. The
  command and the job both write legs, and `updateOrCreate` on
  `(run_id, tenant_id)` is what makes a resumed leg amend its row rather than
  add a second one.
- **Chunking pages with `forPage()` rather than `Builder::chunk()`.** The
  closure `chunk()` takes types its collection as `Collection<int, Model>` at
  level 9, and the `instanceof` narrowing that fixed it is what Rector then
  removed as always-true on the next `composer lint`.
- **The command resets its own tally at the top of `handle()`.** Console
  commands are resolved once and reused, so a second invocation in the same
  process inherited the first run's failures and reported a clean run as
  failed. Caught by the resume test, which runs the command twice.
- **Two fixture migration directories, not one.** `tenant-hostile` is named to
  sort before `tenant-extra`, because a migration that ran before the failing
  one is still applied, which is not what the isolation test asserts.
- **`workbench/app/Models/Central/TenantMigrationRun.php` was added**, mirroring
  every other configured model, so the host-subclass seam is exercised.
- **The screen shows one run at a time** with a selector over the twenty most
  recent, rather than every run's rows at once. Failures sort first within the
  run, as planned.
