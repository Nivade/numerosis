# Usage-based billing: finish the metering pipeline

**Status: executed 2026-09-17 on `feat/usage-metering`.** Wave 4 of
`saas-readiness-roadmap.md`. Depends on the counter from
`runtime-entitlements.md`. All six phases landed; see "What shipped" at the
bottom for the three shape changes contact with the code forced.

## Decision: finish, do not drop

`subscriptions` carries `meter_id` and `meter_event_name`
(`database/migrations/central/2025_12_24_204847_create_subscriptions_table.php:48`).
Nothing writes them, nothing reads them, no usage is recorded anywhere, and no
meter event is ever sent to Stripe. It is a half-schema.

Two options were on the table on 2026-09-16 — drop the columns and re-add them
with the feature, or build the pipeline. **Build it.** Recorded here so the
next reader does not re-open it.

## What a metering pipeline needs

| Stage | Requirement |
|---|---|
| Record | Cheap, in the request path, never blocking. One counter, shared with quotas |
| Aggregate | Per tenant, per meter, per billing period |
| Report | Stripe meter events, batched, idempotent |
| Reconcile | Detect and resend what Stripe did not acknowledge |
| Show | The customer sees current-period usage before the invoice, not after |

Idempotency is the whole game. Stripe meter events accept an identifier and
deduplicate on it; sending the same usage twice because a job retried is
double-billing a customer, which is the one bug in this package that would
cost real money and real trust.

## Shape

**Record** through `UsageCounter::consume()` — the same call quotas use. One
write, tenant-scoped, atomic.

**Report** through a scheduled `billing:report-usage` command: read counters
whose period is open, send Stripe meter events in batches, mark each batch
reported with the identifier that was sent. A counter row is never deleted
after reporting; it is the evidence behind the invoice line.

**Reconcile** by re-reading Stripe's meter event summaries per period and
comparing against local totals. A mismatch is an operator alert, never a
silent correction.

## Phases

### 1. Meter definitions

Meters are plan configuration: `PaymentPlan` metadata gains a meters block
naming the Stripe meter, the event name and the counter key. Validated at
boot with the rest of plan metadata.

### 2. Reporting job and command

Batched, idempotent, per tenant. Retries are safe because the identifier is
derived from tenant, meter and period bucket rather than generated per
attempt. That derivation is the single most important line in this plan.

### 3. Populate the columns

`meter_id` and `meter_event_name` get written when a subscription is created
against a metered plan, in `RecordSubscription` and the webhook path that
creates subscriptions. The dual-write path
(`SubscriptionDualWriter`) already exists and is where this belongs.

### 4. Customer-facing usage

Current-period usage against the included allowance on the billing screen,
plus an estimated overage. Reading the local counter, not Stripe — Stripe's
aggregation lags and a customer comparing two numbers that disagree files a
ticket.

### 5. Reconciliation command

Scheduled daily, compares local totals to Stripe's summaries, alerts on
divergence beyond a tolerance.

### 6. Invoice-time interaction

A metered subscription's invoice arrives with usage lines Cashier did not
create. `invoice.payment_succeeded` handling must not assume a fixed amount,
and the payment-confirmed notification should name the usage component.

## Tests

- Identifier derivation is stable: the same tenant, meter and period produce
  the same identifier across process restarts, and a retried job sends the
  same one.
- Reporting a period twice sends no second event.
- A tenant with no metered plan reports nothing.
- Counters survive reporting and remain readable as evidence.
- Reconciliation flags a seeded divergence and alerts once.
- Period boundaries follow the subscription's period, not the calendar month —
  proven with a subscription whose period starts mid-month. Calendar months
  are the assumption that silently mis-bills every annual plan.
- Usage screen matches the counter exactly.

## Risks

- **Double-billing.** Every failure mode here ends at "the customer was
  charged twice". Idempotent identifiers, never-deleted counter rows, and
  reconciliation are three layers against the same failure and all three are
  in scope.
- **Cashier's metered API surface.** Stripe's billing meters replaced the
  older usage-record API; check `Cashier::stripe()` usage against the
  installed version rather than memory. `Cashier::stripe()` is the escape
  hatch and `Billable` methods are preferred where they exist.
- **Clock skew across period boundaries.** Report against the subscription
  period recorded locally, not against wall-clock time at job execution.

## What shipped

Three shape changes against the plan above, all found on contact:

- **The meter columns are on `subscription_items`, not `subscriptions`.** The
  plan cites `create_subscriptions_table.php:48`, which is inside the
  `subscription_items` block. Stripe also moved the billing period onto the
  item, so `current_period_start`/`current_period_end` were added there too:
  the reporting job needs the period without a Stripe call.
- **There is no `SubscriptionDualWriter` class.** The name is a test
  (`SubscriptionDualWriterTest`) covering the two writers of a subscription
  row. Phase 3 landed as `Actions\Billing\Usage\StampSubscriptionMeters`
  (plan-declared event name onto the item) plus `SyncMeteredItems` (Stripe's
  meter id and period off a webhook payload), called from
  `LinkSubscriptionToTenant` and both subscription webhook handlers.
- **The identifier derives from the cumulative total, not from the period
  alone.** A fixed per-period identifier would have Stripe deduplicate every
  increment after the first, so the counter's running total is part of the
  hash and each report sends the delta. `tenant_usage` gained
  `reported_value`, `report_identifier` and `reported_at` for that, and the
  row is never deleted.

Also worth knowing: a metered capability's `included` allowance reads as its
limit but never refuses `Entitlements::consume()` — the overage is what the
plan sells. Phase 6's "must not assume a fixed amount" needed no repair (the
handler reads no amounts); what it gained is the metered total on
`PaymentSettled` and a line naming it in `PaymentConfirmed`.

The customer-facing screen is `UsageMeteringFeature` (off by default) at the
tenant route `usage`, since no billing screen existed to add a panel to.
