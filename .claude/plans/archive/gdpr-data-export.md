# Data export, erasure and consent records

**Status: executed 2026-09-17.** See "What shipped" at the bottom. Wave 3 of
`saas-readiness-roadmap.md`. Consumes the exporter from
`tenant-backup-restore.md` and the coverage from `audit-log-coverage.md`.

## Where it stands

Half of erasure exists and none of access does.
`src/Actions/Auth/DeleteUserAccount.php:13` soft-deletes the central user and
refuses when they own a tenant. There is no export of personal data, no record
of consent, no retention policy, and no defined answer to what "delete me"
means for a user whose rows are scattered across a central database and N
tenant databases.

For a package sold as a SaaS foundation, the export and erasure paths are
table stakes the buyer's legal review asks for by name.

## The hard question first

**What does erasure mean for a member of somebody else's tenant?**

A tenant is a customer's workspace. The rows a member created inside it —
records, comments, audit entries — belong to the tenant, not to the member.
Deleting them on request destroys the customer's data.

The settled shape, and the one most SaaS products land on:

| Data | On erasure |
|---|---|
| Central identity: name, email, password, social accounts, sessions | Deleted |
| Membership rows | Deleted |
| Tenant-side `Tenant\User` row | Anonymized, not deleted — name and email replaced, id retained so the tenant's foreign keys survive |
| Content authored inside a tenant | Retained, attributed to the anonymized user |
| Activity log entries naming the user as causer | Anonymized causer, entry retained |
| Billing records | Retained. Financial records have their own statutory retention and are not erasable on request |

Anonymize-in-place is the mechanism; the current `DeleteUserAccount` soft
delete is not enough on its own because it leaves name and email in the row.

## Phases

### 1. `PersonalDataExporter`

Built on `TenantDataExporter`, filtered to one subject. Walks the central
rows for the user and, for each tenant they belong to, the tenant-side rows
naming them. Output is a zip with a human-readable manifest — a JSON dump with
no explanation does not satisfy an access request in practice.

### 2. Self-service export

On `/settings`, a request button. Generation is queued (it crosses N tenant
databases), the artefact lands on a configurable disk, and the user gets a
signed, short-lived download link by mail. Rate-limited to one request per
day per user, because it is expensive and is an obvious abuse vector.

### 3. `AnonymizeUser` action

Replaces `DeleteUserAccount`'s soft delete with the table above, in one
transaction per database. Fires `UserAnonymized`. Idempotent — re-running on
an already-anonymized user is a no-op.

Ownership still blocks, and now points at `tenant-ownership-transfer.md` and
`tenant-close-and-recovery.md` rather than dead-ending.

### 4. Tenant-level export and erasure

The Owner can export the whole tenant (the logical exporter, unfiltered) and
close the tenant, which is the tenant-level erasure path. Purge after the
30-day window is the erasure.

### 5. Consent and retention records

A central `consents` table: subject, purpose, version of the terms, timestamp,
IP. Written at registration and whenever terms change. Without a record of
what was agreed and when, the rest of this is unprovable.

Retention windows for activity logs, backups, closed tenants and export
artefacts gathered into one config block, documented in
`docs/host-requirements.md` as host-owned decisions with package defaults.

### 6. Staff-side

Export and erasure on behalf of a user from the staff panel, activity-logged,
for requests that arrive by mail rather than through the product.

## Tests

- Export contains every central row naming the user and the tenant-side rows
  from each of their tenants, proven against a user in two tenants.
- Export contains no other user's personal data — the assertion that makes it
  safe to hand over.
- Download link is signed, single-use and expires.
- Rate limit allows one request per day.
- Anonymization removes name and email centrally and tenant-side while leaving
  foreign keys intact and authored content readable.
- Anonymization is idempotent.
- Billing rows survive anonymization, deliberately, with a test that says so
  in its name so nobody "fixes" it later.
- Consent row is written at registration with the terms version.

## Risks

- **Export is a data-exfiltration primitive.** It bundles everything about a
  person into one downloadable file. Signed single-use links, short expiry,
  rate limiting and an activity-log entry per generation are all required, not
  optional.
- **Anonymization across N databases is not atomic.** A failure halfway leaves
  a partially anonymized user. Make it resumable per tenant and record
  progress, the same shape as the provisioning chain.
- **Legal advice is not this plan.** It implements a defensible shape. The
  host's counsel decides retention periods and lawful basis; the config block
  is where their answer goes.

## What shipped

All six phases, 2026-09-17.

- `Services\Auth\PersonalDataExporter` behind `Contracts\Auth\ExportsPersonalData`,
  wrapping the tenant exporter once per workspace the subject belongs to. The
  contract takes a global id rather than a `CentralUser`: `ArchTest` forbids a
  contract typed on this package's models, and it is the right boundary anyway.
  The archive leads with a written `README.md`.
- `settings/data` requests one, `Jobs\GeneratePersonalDataExport` produces it,
  and the link is signed, single-use (`downloaded_at`) and expiring. Two gates
  guard the download: the signature, and the row's own state — a link minted
  before the row expired still 404s.
- Throttled to `numerosis.privacy.request_interval_hours` per subject, not per
  IP: the cost is the databases read and the person is known.
- `Actions\Auth\AnonymizeUser` is what `DeleteUserAccount` now calls. Central
  identity, social accounts and memberships go; the tenant-side row keeps its
  primary key with name and address replaced; consent and billing rows stay.
  Idempotent on `anonymized_at`, which is a new column on both `users` tables.
- `consents` is written at registration with `numerosis.privacy.terms_version`.
- Staff paths on the users screen behind two new permissions,
  `exportData users` and `eraseData users`, both activity-logged.
- Owner-level workspace export from the team screen
  (`Actions\Tenancy\RequestTenantDataExport`), sharing the request row and the
  link with a personal export.
- Retention: `numerosis:prune-data-exports` deletes artefacts and keeps the
  request rows, which are the record that a request was answered.
  `docs/host-requirements.md` now gathers every retention window — activity
  log, backups, exports, closed tenants, invitations — into one table.

Three things worth knowing, none of which the plan predicted:

- A hydrated **tenant model returned out of `$tenant->run()` cannot be read
  after tenancy ends** — its connection is resolved lazily and there is none.
  Return scalars. `AnonymizeUserTest` hit this and says so inline.
- Adding a tenant migration means the test harness's **template databases have
  to be dropped by hand**, or `CloneTenantSchema` copies a stale schema and
  unrelated tests fail with "Unknown column".
- Seeding roles **after** creating a central user leaves `assignRole('admin')`
  throwing: `CentralUserObserver` promotes the first user, and spatie caches
  the missed lookup. Seed in `setUp()` before any user exists.

`DeleteUserAccountTest` changed shape rather than meaning: it counted tenants
off the event's user *after* the action, which erasure now empties, so it
counts inside the listener — which is the only thing that event ever promised.
