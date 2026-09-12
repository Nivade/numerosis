# Findings

- 2026-09-12: no popular-cache invalidation exists — bug — out of scope for current plan, not introduced by it
- 2026-09-12: `Events\Billing\PaymentSettled` is dispatched from nowhere in `src/` — only registered in `NumerosisServiceProvider` and constructed by tests, so `SendPaymentConfirmedNotification` never fires in production — dead seam or a missing dispatch in the webhook path
