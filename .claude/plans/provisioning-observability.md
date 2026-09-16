# Provisioning health and queue observability

**Status: not executed. Written 2026-09-16.** Wave 2 of
`saas-readiness-roadmap.md`. Screens land in the `staff-admin-panel.md` shell.

## What exists, unread

Provisioning already records everything needed to diagnose a failure and
surfaces none of it:

- `TenantProvision` carries `status`, `settled_at`,
  `provisioning_started_at`, `completed_at`, `failed_at`, `error` and
  `step_records` (JSON, per-step) — `src/Models/Central/TenantProvision.php:39`.
- `RunProvisioningStep` retries five times with 5-second backoff and is
  resumable from `step_records` — `src/Jobs/RunProvisioningStep.php:28`.
- `PruneStalledTenantProvisions` runs hourly, releasing reservations after two
  hours and flagging chains stalled for fifteen minutes.
- `TagsSentryScopeWithTenant` tags job failures with the tenant, and no-ops
  unless `sentry` is bound.

So when a signup fails at `SeedTenantDatabase`, the evidence is complete and
nobody is told. The customer sees a half-finished signup; the operator finds
out when they complain.

## Scope

Three things: a health endpoint machines poll, screens humans read, and alerts
that fire without either.

### Health endpoint

`GET /up/numerosis` (the framework's `health:` slot stays the host's), a JSON
document, no authentication, no tenant detail:

```
central database reachable
provisioning queue depth and oldest job age
provisions failed in the last hour
stalled provisions currently flagged
scheduler last-run age
```

Booleans and counts only. Never tenant names — this endpoint ends up in
uptime monitors and dashboards.

### Screens

| Screen | Content |
|---|---|
| Provisions | Every `TenantProvision` not `Completed`, newest first, with the failing step, the error and the retry count |
| Provision detail | `step_records` rendered as a timeline: step, state, attempts, duration, error |
| Queue | Depth per queue, with `provisioning` first, plus failed-job count |

Retry and cancel act through the existing chain rather than re-implementing
it: retry re-dispatches from the first non-`done` step, so the resumability
`RunProvisioningStep` already has is what makes the button safe.

### Alerts

A `ProvisioningFailed` notification to a configurable operator channel, fired
by a listener on the existing failure event, throttled so one bad deploy does
not send two hundred mails. Off unless `numerosis.notifications.operator` is
set.

## Phases

1. Health controller and its route, behind a config flag so a host can
   disable it. Read-only, cached for a few seconds so polling cannot
   hammer the database.
2. Metrics query object — the same counts used by endpoint, screens and
   alerts, defined once.
3. Provisions index and detail screens in the staff panel.
4. Retry and cancel actions, idempotent, activity-logged.
5. Operator notification plus throttle.
6. Queue screen.

## Tests

- Health endpoint answers when the database is up and degrades (non-200)
  when the central connection is down, without throwing.
- It leaks no tenant name or slug.
- Retry from a failed step re-runs only the steps after the last `done` one,
  proven against a provision whose `step_records` show three done and one
  failed.
- Cancel marks the provision cancelled and drops queued steps.
- Operator notification throttles: twenty failures in a minute send one mail.
- Screens render for a provision with no `step_records` at all, which is what
  a `Reserved` row looks like.

## Risks

- **`failed_jobs` is a central table.** A tenant-context job failure writes
  there with whatever connection is current; this is a known trap and the
  screens must not assume the tenant connection when reading it.
- **Queue depth on non-database drivers.** Redis and SQS answer differently
  and some do not answer at all. Degrade to "unknown" rather than throwing,
  and never let the health endpoint's own failure look like a provisioning
  failure.
