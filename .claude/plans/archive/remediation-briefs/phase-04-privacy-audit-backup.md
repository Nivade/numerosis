# Phase 4 — Privacy, audit and backup completeness

**Status: not executed.** Anchors verified against `e411bed`. Read
`README.md` in this directory first. Closes P10, P11, P9, P12. S23/S24 are
already closed.

Rules to read: `.ai/rules/tenant-provisioning.md`,
`.ai/rules/central-rows-on-tenant-routes.md`, `.ai/rules/exception-handling.md`.

## S23 and S24 are closed — skip them

Both export jobs already share `src/Jobs/Concerns/WritesDataExportOutcome.php`
(`markCompleted()`, `markFailed()`, `exportDisk()`), and the `fail()` that
shadowed `InteractsWithQueue::fail()` is `markFailed()` now. Nothing to do.

## 4.1 — Anonymize the causer (P10)

`src/Actions/Auth/AnonymizeUser.php:29` — `handle()` deletes social accounts
(`:78`) and memberships (`:80`) and soft-deletes the user (`:91`). It touches no
`activity_log` row on either connection, relying on the redacted central user
resolving through the causer relation. Tenant-database entries and any
`properties` copy of the name or email survive.

`gdpr-data-export.md` asked for "anonymized causer, entry retained".

Walk both connections:
- Null the causer morph (`causer_id`, `causer_type`) on every entry the subject
  caused, centrally and inside each tenant database the subject belonged to.
- Scrub the known name and email keys out of `properties`.
- Entries stay. Only the identity goes.

The membership rows are deleted at `:80`, so **collect the tenant list before
that line runs** or there is nothing left to walk.

## 4.2 — Filter by the acting person, not the actor class (P11)

`resources/views/pages/staff/⚡activity.blade.php:39` filters
`properties->actor`, which holds the actor *class* — `user`, `staff`, `system` —
not the person `audit-log-coverage.md` asked to filter by.

Keep the class filter. Add a causer filter beside it.

## 4.3 — `--chunk=` on the backup command (P9)

`src/Console/Commands/BackupTenantCommand.php:16` declares `{tenant?}`,
`--all` and `--disk=`. The spec signature has `--chunk=`.

`src/Services/Tenancy/PortableTenantDatabaseDumper.php:63` dumps through
`cursor()` (already lazy); the restore path (`:109`) buffers and inserts in
hardcoded 500-row chunks. Wire `--chunk=` through to that batch size, keeping
500 as the default so nothing changes for a caller that omits it.

## 4.4 — Schedule `session:prune` (P12)

`NumerosisServiceProvider::registerSchedule()` registers twelve commands, each
behind a `numerosis.schedule.*` key. There is no `session:prune`, and the
package now prefers database sessions — `session-management.md` names this as
its own risk.

Add it beside the others, with its own `numerosis.schedule.prune_sessions`
config key defaulting on, following the shape of the eleven siblings exactly.

## Tests

- `tests/Feature/Privacy/AnonymizeUserTest.php` — add: an activity entry the
  subject caused survives with a null causer and no name or email left in
  `properties`, on both the central and the tenant connection. This is the
  assertion that makes 4.1 worth having; write it so it fails if only one
  connection is walked.
- `tests/Feature/Audit/ActivityScreensTest.php` — add: the causer filter narrows
  to one person while the class filter still narrows to `staff`.
- `tests/Feature/Tenancy/TenantBackupTest.php` — add: `--chunk=` changes the
  batch size and the artefact still round-trips.
- Schedule: assert the command is registered under its config key, and that
  turning the key off removes it. Follow whatever the existing schedule tests do
  for the other eleven.

Prove each: write it, revert the fix, watch it fail, restore.

## Commit

```
fix(privacy): anonymization reaches the audit trail

AnonymizeUser redacted the central user and left every activity entry
pointing at it, which works centrally and not at all inside a tenant
database, where the entries and any name or email copied into properties
survived untouched. It walks both connections now, nulls the causer morph
and scrubs the known identity keys. The entries stay: an audit trail with
holes in it is not an audit trail.

The staff activity filter offered the actor class -- user, staff, system --
where the spec asked for the acting person. Both are there now.

tenancy:backup gained the --chunk= its signature always claimed, wired to
the restore batch size, and session:prune joined the eleven scheduled
prunes. Database sessions became the preferred driver and nothing was
removing the dead rows.

Closes P10, P11, P9 and P12.
```
