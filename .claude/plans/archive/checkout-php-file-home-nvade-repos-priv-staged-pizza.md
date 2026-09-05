# Simplify Checkout.php

**Status: ✅ Executed.** Finished the mid-refactor: added
`createSubscriptionAndSettle()`, both `subscribe()` and
`subscribeWithSavedPaymentMethod()` now call it, no more duplicated
try/catch+settle block. `phpstan`'s undefined-method error for it is gone.
`--filter=Checkout` tests: 48 passed, 3 failed — those 3 are the
pre-existing `propertySynthesizers` Livewire-wizard baseline failure
unrelated to this file (`.claude/rules/testing.md`). Pint clean.

## Context

`app/Livewire/Billing/Checkout.php` has two public entry points —
`subscribe()` (SetupIntent/card path) and `subscribeWithSavedPaymentMethod()`
(saved-card path) — that both end with the identical sequence: call
`CreateInlineSubscription::run(...)`, catch `IncompletePayment` into
`handleIncompletePayment()`, catch `ShowsMessageToUser` into `$paymentError`,
then call `settle()`. That's a verbatim 12-line block duplicated at
Checkout.php:173-186 and 224-236, differing only in the arguments passed to
`CreateInlineSubscription::run()`.

Confirmed via exploration (see agent report):
- No existing helper (trait method or Action) already collapses this
  "create subscription + settle" pattern for Livewire component use.
  `FinalizeCheckoutSubscription` looks similar but is a *different*,
  deliberately separate copy used by `CompleteRedirectCheckout` and the
  webhook path — `.claude/rules/billing-checkout.md` explicitly says
  Checkout's own subscribe()/settle() must stay a separate third copy, so
  it must not be merged with that Action.
- The repeated `PendingTenantProvision::find($this->pendingDomain)` +
  `GetAuthenticatedUser::run()` lookups (in `subscribeWithSavedPaymentMethod`,
  `settleFromPendingSubscription`, `settle`) have no memoization precedent
  anywhere else in this codebase's Livewire components, and the pending row
  is legitimately allowed to become null between calls (it's deleted once
  provisioning succeeds — real race, not just theoretical). Leave these as
  independent re-fetches; each call site already guards for null correctly.

## Change

Extract a single private method that both public methods call:

```php
private function createSubscriptionAndSettle(
    PendingTenantProvision $pending,
    string $paymentMethodId,
    ?CentralUser $billable = null,
): void {
    try {
        $subscription = CreateInlineSubscription::run($pending, $paymentMethodId, $billable);
    } catch (IncompletePayment $e) {
        $this->handleIncompletePayment($e);

        return;
    } catch (ShowsMessageToUser $e) {
        $this->paymentError = $e->getMessage();

        return;
    }

    $this->settle($subscription);
}
```

- `subscribe()` (Checkout.php:173-185) replaces its try/catch + settle call
  with `$this->createSubscriptionAndSettle($resolved->pending, $resolved->paymentMethodId());`
- `subscribeWithSavedPaymentMethod()` (Checkout.php:224-236) replaces its
  try/catch + settle call with
  `$this->createSubscriptionAndSettle($pending, $paymentMethod->id, $billable);`

No behavior change: same exceptions caught in the same order, same fallback
assignments, same call to the existing private `settle()`.

## Verification

- Run the existing Checkout feature tests (`vendor/bin/sail artisan test --compact --filter=Checkout`) to confirm both paths (fresh SetupIntent and saved payment method) still pass, including the `IncompletePayment`/3DS branch and the `ShowsMessageToUser` branch.
- `vendor/bin/sail bin pint --dirty --format agent` after editing.
