# Rolling migrations across every tenant

**Status: not executed. Written 2026-09-16.** Wave 2 of
`saas-readiness-roadmap.md`.

## The gap

`MigrateTenantDatabase` exists only as a provisioning step
(`src/Actions/Tenancy/MigrateTenantDatabase.php:16`, wired at
`config/numerosis.php:382`). It runs once, for one tenant, at creation.

There is no supported way to apply a new tenant migration to tenants that
already exist. Ship a column today and the fleet is inconsistent with no
command to fix it. stancl's own `tenants:migrate` is available from the vendor
package, but nothing here wraps it, tests it, or gives it the properties a
fleet rollout needs: failure isolation, resume, progress, and a dry run.

This is the plan with the shortest fuse. Every schema change from now on needs
it.

## What a fleet rollout has to do

| Property | Why |
|---|---|
| Failure isolation | Tenant 40 failing must not stop tenants 41 through 4000 |
| Resume | A run killed halfway restarts from where it stopped, not from the first tenant |
| Idempotence | Re-running skips tenants already at the target batch |
| Chunking and pacing | Thousands of `CREATE`/`ALTER` statements will saturate a database server; a delay between chunks is a required knob, not a nicety |
| Dry run | Report which tenants have pending migrations and what they are, changing nothing |
| A record | Which tenants ran, when, how long, what failed |
| Queue or inline | Long rollouts belong on a worker; CI and small fleets want inline |

## Shape

One command, `tenancy:migrate`, plus a per-tenant job.

```
tenancy:migrate [--tenants=] [--pending] [--dry-run] [--chunk=50] [--delay=0]
                [--queue] [--continue-on-failure] [--rollback] [--step=]
```

`--continue-on-failure` defaults to **on**: fleet rollouts stop for nobody,
and the failures are collected and reported at the end. `--rollback` is
deliberately opt-in and refuses without an explicit tenant list, because a
fleet-wide rollback is almost always a mistake.

A `tenant_migration_runs` central table records one row per tenant per run:
run id, tenant, started, finished, migrations applied, error. This is what
makes resume and the status screen possible, and it is the artefact an
operator wants after a bad deploy.

## Phases

### 1. Query for tenants with pending migrations

Per tenant: initialize tenancy, read the `migrations` table, compare against
`database/migrations/tenant/` plus any host contributions, end tenancy. This
query alone is worth shipping — it answers "is the fleet consistent".

### 2. `MigrateTenant` job

One tenant, one job, on the `provisioning` queue. Reuses
`MigrateTenantDatabase` rather than duplicating it. Records its run row.
Retries are **off**: a half-applied migration retried blindly is worse than a
reported failure.

### 3. The command

Chunking, delay, dry run, progress bar, end-of-run summary naming every failed
tenant. Inline by default, `--queue` to dispatch.

### 4. Resume

`--resume=<run-id>` skips tenants whose run row is finished. Combined with
`--continue-on-failure`, the normal recovery is: run, read failures, fix,
resume.

### 5. Status screen

Runs and their per-tenant rows in the staff panel, with the failures first.

### 6. Documentation

`docs/extending.md` gains the rollout procedure. A host contributing tenant
migrations needs to know this command exists and that provisioning no longer
covers them.

## Tests

- Pending-migration query is accurate against a tenant deliberately left one
  migration behind.
- A tenant failing mid-run does not prevent later tenants from migrating, and
  is named in the summary.
- Resume skips finished tenants and retries only the failed ones.
- Dry run writes nothing — asserted by comparing schema before and after.
- Tenancy is ended after every tenant, including after a failure. A leaked
  tenant connection poisons every subsequent tenant in the run and is the
  defect most likely to ship here.
- Runs against all four supported drivers, since SQLite's one-writer rule
  makes chunk sizing behave differently.

## Risks

- **Long-running lock waits.** MySQL `ALTER` on a large tenant table blocks;
  the `LockWaitTimeoutTest` in the suite exists because this has bitten
  before. Document the pacing knob rather than hiding it.
- **Host-contributed tenant migrations.** The package's migration path is not
  the only one — anything a host registers must be included, or the fleet
  query reports consistent while it is not.
