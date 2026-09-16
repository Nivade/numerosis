# Provisioning health and queue observability

**Status: ✅ Executed 2026-09-16** on `feat/provisioning-observability`, the
day it was written. Wave 2 of `saas-readiness-roadmap.md`. Screens landed in
the `staff-admin-panel.md` shell. Deviations in "What shipped" at the bottom.

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

## Settled before execution (2026-09-16)

| Question | Ruling |
|---|---|
| Alert transport | A `ProvisioningFailed` notification to whatever `numerosis.notifications.operator` resolves to, through an `OperatorRecipient` contract a host can bind. Null means silent, so nothing ships enabled and a host wanting Slack or PagerDuty swaps the binding rather than writing a listener |
| Health endpoint | Registered with the feature, unauthenticated, counts and booleans only. A health document a monitor cannot reach unauthenticated is not doing its job; the disclosure is queue depth and failure counts, never a tenant name or slug. 503 when the central connection is down |
| Screens | One provisions index, the staff panel's, widened with the failing step, error and retry count. Provision detail and queue screens are new. Two indexes with two retry buttons is how one of them goes stale, so the plan's separate screen set is not built |

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

## What shipped

`Features\Observability\HealthEndpointFeature` (`health_endpoint`), commented
out in `config('numerosis.features')`, serving
`Http\Controllers\Observability\HealthController` at
`numerosis.routes.health_path` (default `up/numerosis`). The document is
`Data\Observability\HealthReport` — central-database boolean, queue depth,
oldest job age, failed-job count, provisions failed in the last hour, stalled
and in-flight counts, and the scheduler's last-run age — built by
`Actions\Queries\GetSystemHealth` and cached for
`numerosis.cache.ttl.health_report` seconds.

Alerts go through `Contracts\Notifications\OperatorRecipient`, bound to
`Services\Notifications\MailsConfiguredOperator`, fired by
`Listeners\Tenancy\SendProvisioningFailedAlert` on the existing
`TenantProvisioningFailed` event and throttled on one global cache key.

Screens: the provisions index gained an attempts column and a link to a new
detail screen (`staff.provisions.show`), and there is a new queue screen
(`staff.queue`) in the layout's navigation.

Five deviations from the plan above:

- **A failing step is now recorded, which the plan assumed but the code could
  not do.** `StepOutcome::Failed`, written by `RunProvisioningStep::failed()`
  with the attempt count, and `TenantProvision::hasRun()` rejects it so a retry
  still resumes at exactly that step. Without this there was no failing step,
  no error per step and no retry count for the index to widen with.
- **The failure hook guards on `hasRun()`.** A sync chain runs each link inside
  the one before it, so a throw bubbles through every earlier link and fails
  each of them; without the guard every step of the chain recorded itself
  failed.
- **The detail screen is read-only.** Retry and cancel stay on the index, which
  is what the "two retry buttons" ruling was protecting against.
- **Scheduler last-run age needed a writer.** `numerosis.schedule.heartbeat`
  (on by default) stamps a global cache key every minute; nothing in Laravel
  records when the scheduler last ran.
- **Phases 4 and 6 were already half-built.** Retry and cancel shipped with
  `staff-admin-panel.md`; this added the failing-step data they now show.
