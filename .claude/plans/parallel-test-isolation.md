# Finish parallel test isolation

**Status: ❌ Not executed / superseded.** This plan's direction (keep the
root-cause fix, delete most of the parallel bootstrap, keep `--parallel`) was
not what shipped — `--parallel` was dropped from the suite entirely.
`.claude/rules/testing.md`: "The suite runs serially. Do not add `--parallel`.
It was tried across two sessions and abandoned." The root-cause diagnosis in
this plan is still correct and is preserved there.

## Context

The tenant-template work is done and measured: cloning a template tenant
database costs **~0.19s per tenant** against ~1.9s for the real
`CreateDatabase` + `MigrateDatabase` + `SeedTenantDatabase` pipeline. That part
is stable and is not what this plan is about.

The remaining work is `--parallel`, which is now the only supported way to run
the full suite (`composer test` passes it, and `.claude/rules/testing.md` says
so). The suite went from ~370s serial to **53.5s on 8 workers**, and the
deadlock that hung it repeatedly is gone. What is left is **41 failures**, of
which roughly 24 are pre-existing and roughly 17 were introduced by the
bootstrap I bolted onto `Tests\TestCase` to get there.

The recommendation is to **delete most of that bootstrap** and keep only the
one-line fix that addressed the actual root cause.

### What was actually wrong (keep this — it cost a lot to find)

`RefreshDatabase::beginDatabaseTransaction()` registers this teardown check:

```php
if ($connection->getPdo() && ! $connection->getPdo()->inTransaction()) {
    RefreshDatabaseState::$migrated = false;
}
```

Every test that bootstraps tenancy trips it. stancl's
`DatabaseTenancyBootstrapper` purges the default connection when it switches to
the tenant database, so at teardown `getPdo()` returns a *fresh* session that
was never in the transaction. Laravel concludes the schema is compromised and
makes the next test in that process run `migrate:fresh`.

Serially that is invisible waste — the suite silently re-migrates after most
tenancy tests, which is a real slice of the original ~370s. Under `--parallel`
it deadlocks: Pest starts a fresh worker *process* per batch of test files, and
processes sharing a token overlap. One process runs `migrate:fresh`, whose
`DROP TABLE` takes a pending exclusive metadata lock on a database another
process is still running tests against; the live test's second connection then
queues behind that pending lock while the DDL waits on the live test's
transaction. Neither yields and `lock_wait_timeout` defaults to a year.

Diagnosis recipe is in `.claude/rules/testing.md` under "Diagnosing a hung run".

## Current state (measured, run 8)

| Metric | Value |
|---|---|
| Duration | **53.5s** (8 workers) — was ~370s serial |
| Result | 41 failed, 110 passed, 1 skipped |
| Stalls | none |

Failure profile:

- **15** `Missing required parameter for [Route:
  filament.tenantAdmin.profile.pages.delete-account] … Missing parameter:
  tenant` — pre-existing, unrelated to this work. Confirmed identical on a
  stashed tree earlier in the session.
- **A handful** of `Duplicate entry`, `Field 'x' doesn't have a default value` —
  these are *new*, and they are leftover committed rows. When a test loses its
  transaction (see above) its writes commit, and suppressing the rebuild means
  nothing wipes them.
- Concentrated in: `Filament\TenantAdmin\Pages\ProfileTest` (10),
  `Filament\Pages\InteractsWithRecordTraitTest` (6),
  `Feature\Filament\TenantAdmin\Pages\Profile*` (5),
  `Filament\App\RoleResourceUiTest` (4).

## Recommended approach

Right now `Tests\TestCase` does far too much: it creates the token database,
takes a MySQL named lock, points the default and `central` connections at the
token database, runs `migrate:fresh` itself, restores the connection names, and
suppresses the re-migrate. Three separate bugs came out of that surface in one
session (`--database` skipping migrations that pin their own connection, a
swallowed exception leaving half a schema, and a `_test_1_test_1` double
suffix). It is not worth keeping.

**Let Laravel own the token database entirely.** Delete
`reuseParallelTestSchema()`, `migrateTokenDatabase()`, `hasMigrationsTable()`,
the `PROBE_CONNECTION`/`ADMIN_CONNECTION` constants and the
`refreshApplication()` override from `tests/TestCase.php`. Laravel's own
parallel wiring already creates and switches the database correctly; the first
test in each worker migrates it via the normal `RefreshDatabase` path.

**Keep only `keepParallelSchema()`** — the `tearDown()` hook that restores
`RefreshDatabaseState::$migrated = true` under a parallel token. That is the
whole root-cause fix: it stops the spurious rebuild, and with no `migrate:fresh`
mid-run there is no DDL to deadlock on.

**Then deal with the leftover rows properly.** The rebuild was doing real work —
wiping rows committed by tests whose transaction died. Replace it with a
targeted reset in `tearDown()`, only when the transaction was actually lost:

```php
if (DB::connection()->getPdo() && ! DB::connection()->getPdo()->inTransaction()) {
    // truncate the central tables tests write
}
```

Truncating a known list is far cheaper than `migrate:fresh` and takes only brief
per-table locks. Start from the tables behind the observed failures — `users`,
`socialite_logins`, `subscriptions`, `subscription_items`, `payment_plans`,
`payment_plan_features`, plus the `tenants` / `pending_tenant_provisions` that
teardown already clears.

Alternative worth one experiment first: add `'central'` to
`connectionsToTransact()` so central writes roll back with the test. It may
remove the leftovers outright, but it will not help for connections stancl
purges mid-test, so verify rather than assume.

## Files

| File | Change |
|---|---|
| `tests/TestCase.php` | delete the migration bootstrap; keep `keepParallelSchema()` + `isolateParallelProcess()`; add the conditional truncate |
| `.claude/rules/testing.md` | replace the "migrate once per token database" bullet with the transaction-loss root cause and whatever the reset ends up being |

Leave alone — these are done and verified: `tests/Support/CloneTenantSchema.php`,
`tests/Pest.php`, `app/Providers/TenancyServiceProvider.php`, the teardown
change that drops recorded tenant databases instead of scanning
`INFORMATION_SCHEMA`, and `composer.json`'s `--parallel`.

## Verification

Always `vendor/bin/sail artisan test --parallel`. Never serially — a full serial
run costs ~6 minutes and its failures are not representative.

1. Clear stale schemas first, or results are meaningless:
   ```sql
   SELECT CONCAT('DROP DATABASE `',SCHEMA_NAME,'`;') FROM INFORMATION_SCHEMA.SCHEMATA
   WHERE SCHEMA_NAME LIKE 'tenant%' OR SCHEMA_NAME LIKE 'testing_test%';
   ```
2. Full parallel run. **Target: 41 failures down to ~24**, all of them the
   Filament `Missing parameter: tenant` route error.
3. Confirm no stall: nothing in
   `information_schema.processlist` with `state LIKE '%metadata lock%'`.
4. Confirm no leaked schemas afterwards — only `tenant<token>_phpunittemplate`
   should survive.
5. Then commit. The user has asked for no commit until both a clean parallel run
   and the pre-existing-failure count line up.

## Traps

- **A Sail run cannot be killed from the host.** `pkill` leaves the container's
  PHP alive holding a MySQL transaction, which deadlocks the *next* run's
  migration and produces a fake catastrophic red. Kill inside the container:
  `sail exec laravel.test bash -lc "ps -eo pid,cmd | grep '[p]est'"` then
  `kill -9`.
- **A `KILL`ed `migrate:fresh` leaves `testing` half-dropped.** Recover with
  `DROP DATABASE testing; CREATE DATABASE testing …` before believing any run.
- **Master cannot run `--parallel` at all** (151 failed / 19.9s) — nothing
  repoints `central` per token there. Do not use it as a comparison baseline.
- `tests/Feature/Chat/ChannelTest` fails serially and passes in parallel; it
  `Queue::fake()`s the provisioning pipeline then migrates by hand. Not ours.
