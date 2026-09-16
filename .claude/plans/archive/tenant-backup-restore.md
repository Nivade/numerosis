# Per-tenant backup, restore and import

**Status: executed 2026-09-17.** See "What shipped" at the bottom. Wave 3 of
`saas-readiness-roadmap.md`. Owns the tenant data serializer that
`gdpr-data-export.md` and `tenant-close-and-recovery.md` consume.

## Why

Nothing in the package can copy a tenant's data anywhere. The only operation
that touches a tenant database wholesale is stancl's `DeleteDatabase`, chained
off `TenantDeleted` (`src/Providers/TenancyServiceProvider.php:89`). Purge is
irreversible and unpreceded by a snapshot.

Three distinct needs, one mechanism:

1. **Operational** — take a snapshot before a risky migration or a purge.
2. **Customer** — "send me my data", and "we are leaving, give us everything".
3. **Support** — clone a production tenant into staging to reproduce a bug.

## Two formats, not one

| Format | What | Used by |
|---|---|---|
| Physical dump | Native per-driver dump of the tenant database | Backup, restore, clone |
| Logical export | Portable archive: JSON or CSV per table, plus the tenant's files | Customer export, GDPR |

They are not interchangeable and trying to make one serve both produces
something bad at each. The physical dump restores exactly and is unreadable to
a customer; the logical export is readable and cannot restore a schema.

## Driver reality

The package supports MySQL, MariaDB, PostgreSQL and SQLite, and each dumps
differently — `mysqldump`, `pg_dump`, and for SQLite a file copy, which is the
easy one. The dump path therefore goes behind
`Contracts\Tenancy\TenantDatabaseDumper` with one implementation per driver,
resolved off the existing `DatabaseDriver` enum. Shelling out to a binary that
may not be installed is the failure mode — detect it at boot through
`numerosis:install --verify-only` rather than at 3am during a purge.

## Phases

### 1. `TenantDatabaseDumper` contract and four implementations

Dump to a configurable disk, tenant-scoped path, timestamped. Restore is the
inverse and refuses to overwrite a database with rows unless forced.

### 2. `tenancy:backup` and `tenancy:restore`

```
tenancy:backup  {tenant?} [--all] [--disk=] [--chunk=] 
tenancy:restore {tenant} --from=<artifact> [--into=<new-tenant>]
```

`--into` is the clone path: restore an artefact into a freshly provisioned
tenant rather than over the original. That one flag is what makes support's
"reproduce it in staging" workflow possible.

### 3. Logical exporter

`Services\Tenancy\TenantDataExporter`: walks the tenant's tables, writes
JSON Lines per table, includes the tenant's files from its disk, and includes
the central rows that belong to the tenant — memberships, invitations,
subscription summary. Streams to a zip; never loads a table into memory.

This class is the shared foundation. `gdpr-data-export.md` reuses it filtered
to one user.

### 4. Retention and encryption

Artefacts contain everything a tenant has. Encrypt at rest by default, put
retention on a schedule beside the other prunes, and keep the disk
configurable so a host can point it at object storage rather than local
volumes.

### 5. Purge integration

`tenant-close-and-recovery.md` keeps purge disabled until this lands. Wire
it: purge takes a final backup, and refuses to drop the database if the backup
fails. This is the interlock that makes the 30-day window meaningful.

## Tests

- Round trip per driver: backup a seeded tenant, drop it, restore, and compare
  row counts and a content hash per table.
- Restore into a new tenant produces a working tenant reachable on its own
  domain, with no reference to the source tenant's id or slug left in it.
- Restore refuses a non-empty target without `--force`.
- Missing dump binary is reported at verify time, and the backup command fails
  with that message rather than a shell error.
- Exporter streams: a tenant with a large table does not exhaust memory —
  asserted with a memory ceiling, since this is the defect that only appears
  in production.
- Purge without a successful backup does not drop the database.

## Risks

- **Clone leaks identity.** Tenant rows can carry the old slug in URLs,
  stored settings and file paths. The `--into` path needs an explicit rewrite
  step, and the test above is the one that catches it.
- **Backups are a compliance liability.** An encrypted-at-rest default and a
  documented retention window are part of the feature, not a follow-up.
- **SQLite's single writer.** Copying a live SQLite tenant file mid-write
  gives a corrupt artefact; use the driver's backup API or take a write lock.

## What shipped

All five phases, 2026-09-17.

- `Contracts\Tenancy\TenantDatabaseDumper`, resolved per driver from
  `numerosis.tenancy.backup.dumpers` by a binding in `TenancyServiceProvider`.
- **The default dumper is PHP-native, not a binary**, which is the plan's one
  real deviation. `PortableTenantDatabaseDumper` reads and writes JSON Lines
  through PDO, so a backup needs nothing installed. `mysqldump`/`pg_dump`
  wrappers ship beside it as an opt-in for installations large enough to want
  them, and `SqliteFileTenantDatabaseDumper` uses `VACUUM INTO` rather than a
  file copy, which is the plan's own SQLite risk. The reason is testability:
  no `mysqldump` exists on the development host (only inside the MySQL
  container), so a binary-only default would have shipped with its round trip
  untested. The cost is that a PostgreSQL portable artefact carries rows
  without DDL and restores into a migrated database; `verifyBackupDumper()`
  warns about exactly that.
- `tenancy:backup {tenant|--all}` and `tenancy:restore {tenant} --from= [--into=]`,
  with `RewriteClonedTenantReferences` behind `--into`.
- Encryption is on by default: `ArtifactCipher` streams the artefact through
  libsodium's secretstream a megabyte at a time. `Crypt::encryptString()`
  would have held a whole tenant database in memory, and a truncated artefact
  now fails to decrypt rather than restoring half a database.
- `Services\Tenancy\TenantDataExporter` behind `Contracts\Tenancy\ExportsTenantData`:
  a zip of JSON Lines per table, the tenant's files, and the central rows that
  belong to it. `?string $forGlobalUserId` narrows every table to one person,
  which is the seam `gdpr-data-export.md` consumes.
- Retention is `numerosis:prune-tenant-backups`, scheduled behind
  `numerosis.schedule.prune_tenant_backups`.
- Purge interlock: `tenancy:prune-orphaned-databases` takes a final backup per
  closed tenant and keeps the database when it fails, exiting non-zero.
  `numerosis.tenancy.backup.before_purge` is the switch.

Two things the plan did not predict. Stancl's `run()` has no `try`/`finally`,
so a dumper failing inside it left tenancy initialized and broke the *next*
test's teardown — everything here goes through `Tenant::runHere()`, which
`tests/Feature/ArchTest.php` already enforced for `src/`. And
`getSchemaBuilder()->getTables()` lists every schema the connection can see on
MySQL, central included, so each call names the tenant's own database.

`PruneOrphanedTenantDatabasesTest`'s existing recovery-window test gained a
line turning the interlock off: its subject is the window, and its factory
tenants have no database to snapshot.
