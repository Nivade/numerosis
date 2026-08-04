# Fix: iDEAL checkout crash ("PaymentMethods of type 'ideal' cannot be saved to customers")

**Status: ✅ Executed** (self-declared, corrected twice against real stuck
tenants — see the "Status" note below).

**Status: executed, then corrected twice against two real, previously-stuck
tenants (`blaap`, `banaan`) before the fix actually worked.** The plan text
below is left as originally written for the historical record of what was
planned; what actually shipped differs in three ways, all found by testing
against real Stripe objects rather than assumptions:

1. **`CreateInlineSubscription::handle()`** resolved its billable internally
   via `BillableResolver` (auth-context-based) rather than accepting the
   `$billable` `FinalizeCheckoutSubscription` passes — fine for
   `CompleteRedirectCheckout` and `Checkout::subscribe()` (both authenticated
   browser requests) but broken for the webhook, which has no session to
   resolve from at all ("Billable must be a CentralUser to create an inline
   subscription."). Fixed by adding an optional `?CentralUser $billable =
   null` parameter — falls back to the resolver when omitted, used directly
   when passed.

2. **The plan's whole premise about `setup_intent.succeeded` was wrong.**
   Verified directly against Stripe for the `blaap` incident: the
   SetupIntent's own `payment_method` field *never* gets updated to the
   reusable PaymentMethod Stripe generates for iDEAL — it points at the
   original, permanently-unattached `ideal`-typed PaymentMethod forever.
   Stripe creates a *separate* `sepa_debit` PaymentMethod (already attached
   to the customer) and the only link back to it is the succeeded
   SetupAttempt's `payment_method_details.ideal.generated_sepa_debit` field.
   `setup_intent.succeeded` also fires *before* that conversion completes.
   Both facts meant the originally-shipped `handleSetupIntentSucceeded`
   would defer forever and never complete an iDEAL checkout — exactly the
   "banaan perpetually setting up" symptom that prompted this correction.
   Replaced with `handlePaymentMethodAttached`, triggered by
   `payment_method.attached` (fires exactly when the generated PaymentMethod
   is ready), plus a new `ResolveAttachedPaymentMethod` action shared with
   `ResolveSetupIntent`'s synchronous path.

3. **`Stripe\Service\SetupAttemptService` has no `retrieve()`** — Stripe's
   API only supports listing SetupAttempts *by* `setup_intent`, never
   looking one up by its own id (caught by PHPStan, not by the earlier
   tests, which never exercised this branch for a real reason: see point 4).
   So `handlePaymentMethodAttached` cannot go PaymentMethod → SetupAttempt →
   SetupIntent as first written. It instead matches by customer (available
   directly on the attach event), lists that customer's still-open pending
   checkouts, and asks `ResolveAttachedPaymentMethod` which one (if any) the
   newly-attached PaymentMethod belongs to.

4. **The original tests gave false confidence.** They verified the handler
   using a real *card* SetupIntent/PaymentMethod — which never exercises
   the iDEAL-specific field lookup at all, since cards attach synchronously
   and the code path that reads `setup_intent.payment_method` is correct
   for them. Passing tests were mistaken for proof the fix worked; they only
   proved the card path (which was never broken) still worked. The bug was
   found only by inspecting two tenants stuck in production against the
   real Stripe API — `vendor/bin/sail artisan tinker` calls retrieving the
   actual SetupIntent, PaymentMethod, and SetupAttempt objects — not by
   anything in the test suite. Root infra cause, also found and fixed along
   the way: the Stripe Dashboard webhook endpoint was disabled, and even
   once re-enabled, `.env`'s `STRIPE_WEBHOOK_SECRET` was briefly pointed at
   the wrong endpoint's secret (there were two candidate values in `.env`,
   one commented, and the active one was stale) — now runs locally via
   `stripe listen --forward-to`, confirmed end-to-end with a `[200]`
   delivery.

Both stuck tenants (`blaap`, `banaan`) were completed manually via tinker
using the already-attached `sepa_debit` PaymentMethod Stripe had generated
for each, calling `FinalizeCheckoutSubscription::run()` directly — both are
now fully provisioned. Current test suite: 16 passing (the original tests
rewritten to match the `payment_method.attached` design in point 2/3
above), Pint and PHPStan clean on every touched file.

This plan is written to be executed directly, step by step, with no
follow-up research needed. Every file to touch, its exact new content, and
the exact diff against its current content is included below. Execute the
steps in order (1 → 4); each later step depends on files created in an
earlier one.

## Interaction with `.claude/plans/tenant-wizard-refresh-persistence.md`

That plan (being executed alongside this one) moves the registration
wizard's Payment step onto the embedded `App\Livewire\Billing\Checkout`
component and deletes `Payment.php`'s own duplicate
`subscribe()`/`confirmed()`/`settle()`. Checked directly against
`app/Livewire/Billing/Checkout.php`: its `subscribe()` method is the
non-redirect (card/Link) path — Stripe methods that resolve inline never
reach `CompleteRedirectCheckout` at all, so the guard this plan adds there
is unaffected by that other plan and needs no equivalent inside
`Checkout::subscribe()`. `Checkout`'s own `settle()` is already the single
choke point for its `SettleCheckout::run()` call (used by both `subscribe()`
and `confirmed()`/`settleFromPendingSubscription()`), so there's no
duplicate-logic risk to fix there either — this plan's
`FinalizeCheckoutSubscription` extraction only targets the two places that
otherwise WOULD duplicate the create+settle pair:
`CompleteRedirectCheckout` and the new webhook handler below.

**One real interaction, addressed in Step 2**: the wizard-refresh plan adds
`session()->forget('registration.wizard_state')` inside `Checkout::settle()`
right before its redirect, so a finished registration doesn't leave stale
wizard state behind for the next one. `CompleteRedirectCheckout` is a
separate browser-facing entry point (the redirect-flavoured return route)
that can reach the same "registration finished" outcome without ever
calling `Checkout::settle()` — so it needs the identical
`session()->forget(...)` call in its own successful branch, or a
redirect-flavoured checkout done through the embedded wizard leaks stale
wizard state into the next registration attempt. Added below. **Residual,
accepted gap**: when completion is deferred all the way to the
`setup_intent.succeeded` webhook (this plan's whole point), that webhook
request has no access to the browser's session at all, so it cannot clear
this key either. The stale key sits inert until the customer starts another
registration, at which point it would incorrectly pre-fill old field values.
Not fixed here — case is narrow (redirect-flavoured method + embedded wizard
+ genuinely-deferred attachment), and the existing `initialState()` restore
is keyed on session content that a second registration attempt would mostly
overwrite as soon as any step is submitted. Worth a follow-up if it turns
out to matter in practice, not before.

## Context

A customer hit checkout via
`https://saasm.nvade.dev/checkout/subscription/return?...&setup_intent=seti_1TytK1L6gWs2g790XI5hOdAA`
and got an uncaught `Stripe\Exception\InvalidRequestException`.

**Root cause.** iDEAL is a redirect/bank-auth payment method. The customer
bounces off-site and back to `checkout/subscription/return`
(`app/Actions/Billing/Checkout/CompleteRedirectCheckout.php`, whose own doc
comment already says "Landing point for a redirect-flavoured payment method
(iDEAL, Bancontact) bouncing back from the customer's bank"). iDEAL
PaymentMethods can never be attached to a Customer directly — Stripe's
supported pattern is to convert them into a reusable `sepa_debit`
PaymentMethod and attach *that* to the Customer, but **that
conversion/attachment is asynchronous relative to the SetupIntent's own
`status: succeeded`**. `ResolveSetupIntent::run()`
(`app/Actions/Billing/Checkout/ResolveSetupIntent.php:67`) reads whatever
`payment_method` is on the SetupIntent the instant the browser redirects
back, and `CompleteRedirectCheckout::handle()` (line 60) immediately hands
it to `CreateInlineSubscription::run()`
(`app/Actions/Billing/Checkout/CreateInlineSubscription.php:96`), which calls
`$stripeSubscription->create($paymentMethodId)`. Cashier's `create()` calls
`updateDefaultPaymentMethod()` internally, which has a fast path that skips
attaching when the PaymentMethod is *already* attached to the customer
(`if ($paymentMethod->customer === $customer->id) { ...skip attach... }`) —
but when we read it, Stripe's conversion+attach hadn't finished yet, so that
check failed and Cashier fell through to `$paymentMethod->attach(...)`,
which Stripe rejects outright for an `ideal`-typed PaymentMethod. This is
exactly the observed error.

**Not a "restrict to cards" situation** — confirmed by reading the code
directly: `app/Actions/Tenancy/SuspendTenant.php`,
`app/Actions/Tenancy/RestoreTenant.php`,
`app/Http/Middleware/EnsureTenantSubscriptionActive.php`, and
`app/Http/Controllers/Billing/WebhookController.php`'s
`past_due`/`unpaid`/`incomplete_expired` handling (lines 132-140) all
already exist and are wired up. `.claude/plans/custom-checkout.md`'s Phase 3
(the access-gate/suspension work it said must land "before iDEAL/Bancontact/
SEPA are enabled") is done. `SettleCheckout`'s `AwaitingPayment` status
(`app/Enums/TenantProvisionStatus.php`) and the `handleInvoicePaymentSucceeded`
clearing of it (`WebhookController.php:200-208`) are also already built and
correct — they were written anticipating exactly this class of payment
method, they're just never reached today because the crash happens one
step earlier, at PaymentMethod-attach time. The gap is narrow: nothing waits
for Stripe's async PaymentMethod attachment to finish before using it.

## Fix overview

Complete redirect-flavoured checkouts (iDEAL, Bancontact, and any future
async method) from a `setup_intent.succeeded` **webhook**, not from the
synchronous return-request. Stripe fires that event precisely when its own
async work — including the PaymentMethod conversion/attach — is done, so
waiting for it (instead of polling inline, which has no bounded wait time
and ties up a web worker) is the standard, correct pattern here. Cards are
unaffected: they already attach synchronously and never reach the deferred
branch introduced below.

Three files change, one new file is added:

1. **New** `app/Actions/Billing/Checkout/FinalizeCheckoutSubscription.php` —
   extracted shared "create the subscription + settle" step, so
   `CompleteRedirectCheckout` and the new webhook handler cannot drift the
   way two independent copies of the same logic would.
2. **Modify** `app/Actions/Billing/Checkout/CompleteRedirectCheckout.php` —
   check whether the PaymentMethod is actually attached yet; if not, defer
   instead of crashing.
3. **Modify** `app/Http/Controllers/Billing/WebhookController.php` — add
   `handleSetupIntentSucceeded`, which does the deferred completion once
   Stripe finishes.
4. **Modify** `lang/en/billing.php` — one new copy key for the deferred
   state.

No Blade changes are needed. `resources/views/pages/tenant/⚡mine.blade.php`
already renders every row in `pending_tenant_provisions` that hasn't become
a tenant yet as "{domain} — setting up…" with a spinner
(`⚡mine.blade.php:107-135`), for any status other than `Failed` — the
deferred case (row still at `reserved`, no subscription yet) already renders
correctly with zero changes. Confirmed also that `x-ui.alert` (which
auto-renders `session('info')`/`session('success')`/`session('error')`) is
only included on the registration wizard's own page today, not on
`tenants.mine` — so the `->with('info', ...)` flash added below is added for
convention-consistency with the rest of this file (which already does
`->with('success', ...)` / `->with('error', ...)` on other branches) but
isn't currently visible on that page; that's a pre-existing gap in this file
outside the scope of this fix, not something introduced by it.

---

## Step 1 — New file: `app/Actions/Billing/Checkout/FinalizeCheckoutSubscription.php`

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\PaymentMethod;

/**
 * Creates the subscription against an already-confirmed-attached
 * PaymentMethod and settles the checkout — the pair of steps that must run
 * exactly once a PaymentMethod is known to be attached to the customer.
 * Extracted so CompleteRedirectCheckout (the synchronous return-request
 * path, used when attachment already happened) and
 * WebhookController::handleSetupIntentSucceeded (the deferred path, used
 * when it hadn't) call the identical sequence rather than risking the two
 * copies drifting apart — the same reasoning ResolveSetupIntent's own doc
 * comment gives for being shared between those two entry points.
 *
 * App\Livewire\Billing\Checkout's own subscribe()/settle() is a third,
 * deliberately separate copy for the non-redirect (card/Link) path — not
 * touched here, see custom-checkout.md and this plan's own note on why.
 *
 * Does not catch IncompletePayment: the caller decides what an
 * IncompletePayment means for its own context (a redirect response for
 * CompleteRedirectCheckout; a log-and-acknowledge for the webhook, which
 * must not surface an error status to Stripe).
 *
 * @method static Subscription run(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?CentralUser $billable)
 */
class FinalizeCheckoutSubscription
{
    use AsAction;

    /**
     * @throws IncompletePayment
     */
    public function handle(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?CentralUser $billable): Subscription
    {
        $subscription = CreateInlineSubscription::run($pending, $paymentMethod->id);

        $stripeCustomerId = $billable?->stripe_id;
        $userId = $billable !== null ? (string) $billable->id : null;

        SettleCheckout::run($pending, $subscription, $stripeCustomerId, $userId);

        return $subscription;
    }
}
```

---

## Step 2 — Modify `app/Actions/Billing/Checkout/CompleteRedirectCheckout.php`

Current file (for reference, so the diff below is unambiguous):

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing\Checkout;

use App\Actions\Billing\SyncBillingAddress;
use App\Contracts\Billing\BillableResolver;
use App\Exceptions\Billing\CheckoutAlreadyCompleted;
use App\Exceptions\ShowsMessageToUser;
use App\Http\Requests\Billing\CheckoutReturnRequest;
use App\Models\Central\CentralUser;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Landing point for a redirect-flavoured payment method (iDEAL, Bancontact)
 * bouncing back from the customer's bank. Cards never reach this: they
 * confirm inline and call the Payment step's subscribe() directly instead.
 *
 * Exists from day one, cards-only or not — see custom-checkout.md,
 * "Designing for more payment methods".
 */
class CompleteRedirectCheckout
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $setupIntentId): RedirectResponse
    {
        try {
            $resolved = ResolveSetupIntent::run($setupIntentId);
        } catch (CheckoutAlreadyCompleted) {
            return to_route('tenants.mine')->with('success', __('billing.checkout.setting_up'));
        } catch (ShowsMessageToUser $e) {
            return to_route('tenants.create')->with('error', $e->getMessage());
        }

        $billable = $this->billables->resolve();

        if ($billable instanceof CentralUser) {
            try {
                SyncBillingAddress::run($billable, $resolved->paymentMethod);
            } catch (ShowsMessageToUser $e) {
                return to_route('tenants.create')->with('error', $e->getMessage());
            }
        }

        try {
            $subscription = CreateInlineSubscription::run($resolved->pending, $resolved->paymentMethodId());
        } catch (IncompletePayment) {
            return to_route('tenants.mine')->with(
                'error',
                __('billing.checkout.requires_verification'),
            );
        }

        $userId = $billable instanceof CentralUser ? (string) $billable->id : null;
        $stripeCustomerId = $billable instanceof CentralUser ? $billable->stripe_id : null;

        SettleCheckout::run($resolved->pending, $subscription, $stripeCustomerId, $userId);

        return to_route('tenants.mine')->with('success', __('billing.checkout.setting_up'));
    }

    public function asController(CheckoutReturnRequest $request): RedirectResponse
    {
        return $this->handle($request->setupIntentId());
    }
}
```

Replace the whole file with:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing\Checkout;

use App\Actions\Billing\SyncBillingAddress;
use App\Contracts\Billing\BillableResolver;
use App\Exceptions\Billing\CheckoutAlreadyCompleted;
use App\Exceptions\ShowsMessageToUser;
use App\Http\Requests\Billing\CheckoutReturnRequest;
use App\Models\Central\CentralUser;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\Exception\ApiErrorException;

/**
 * Landing point for a redirect-flavoured payment method (iDEAL, Bancontact)
 * bouncing back from the customer's bank. Cards never reach this: they
 * confirm inline and call the Payment step's subscribe() directly instead.
 *
 * Exists from day one, cards-only or not — see custom-checkout.md,
 * "Designing for more payment methods".
 */
class CompleteRedirectCheckout
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $setupIntentId): RedirectResponse
    {
        try {
            $resolved = ResolveSetupIntent::run($setupIntentId);
        } catch (CheckoutAlreadyCompleted) {
            return to_route('tenants.mine')->with('success', __('billing.checkout.setting_up'));
        } catch (ShowsMessageToUser $e) {
            return to_route('tenants.create')->with('error', $e->getMessage());
        }

        $billable = $this->billables->resolve();

        if ($billable instanceof CentralUser) {
            try {
                SyncBillingAddress::run($billable, $resolved->paymentMethod);
            } catch (ShowsMessageToUser $e) {
                return to_route('tenants.create')->with('error', $e->getMessage());
            }
        }

        // Cards attach synchronously and always reach here with `customer`
        // already set — this branch is only ever taken by a redirect-flavoured
        // method (iDEAL, Bancontact, ...) whose reusable PaymentMethod Stripe
        // has not finished converting/attaching yet. Forcing it here is what
        // used to crash with "PaymentMethods of type 'ideal' cannot be saved
        // to customers." Deferring instead: WebhookController's
        // handleSetupIntentSucceeded finishes this exact checkout once
        // Stripe's own attach completes.
        $stripeCustomerId = $billable instanceof CentralUser ? $billable->stripe_id : null;

        if ($resolved->paymentMethod->customer !== $stripeCustomerId) {
            return to_route('tenants.mine')->with('info', __('billing.checkout.confirming_payment'));
        }

        try {
            $subscription = FinalizeCheckoutSubscription::run(
                $resolved->pending,
                $resolved->paymentMethod,
                $billable instanceof CentralUser ? $billable : null,
            );
        } catch (IncompletePayment) {
            return to_route('tenants.mine')->with(
                'error',
                __('billing.checkout.requires_verification'),
            );
        } catch (ApiErrorException $e) {
            report($e);

            return to_route('tenants.mine')->with(
                'error',
                __('billing.checkout.requires_verification'),
            );
        }

        // See .claude/plans/tenant-wizard-refresh-persistence.md —
        // App\Livewire\Billing\Checkout::settle() clears this same key on its
        // own successful-completion path; this route is a separate
        // browser-facing entry point that can reach the same "registration
        // finished" outcome without ever calling that method, so it needs
        // the identical cleanup or a redirect-flavoured checkout reached
        // through the embedded wizard leaks stale wizard state into the next
        // registration attempt.
        session()->forget('registration.wizard_state');

        return to_route('tenants.mine')->with('success', __('billing.checkout.setting_up'));
    }

    public function asController(CheckoutReturnRequest $request): RedirectResponse
    {
        return $this->handle($request->setupIntentId());
    }
}
```

**What changed, precisely**: `CreateInlineSubscription::run()` +
`SettleCheckout::run()` are replaced by one `FinalizeCheckoutSubscription::run()`
call; a new attached-check guard sits before it; the `IncompletePayment`
catch is kept as-is; a new `ApiErrorException` catch is added alongside it
(defense in depth — the proactive check above should make this
unreachable for the known iDEAL case, but a Stripe-side edge case should
still redirect gracefully instead of crashing); a `session()->forget(...)`
call is added on the success path per the wizard-refresh interaction note
above; `$subscription` variable is assigned but not read after — that's
fine, `FinalizeCheckoutSubscription::run()`'s return value isn't needed
here, only that it succeeded.

---

## Step 3 — Modify `app/Http/Controllers/Billing/WebhookController.php`

Add these two things to the existing file:

**3a. New use statements** (add to the existing `use` block near the top,
alongside the others):
```php
use App\Actions\Billing\Checkout\FinalizeCheckoutSubscription;
use App\Actions\Billing\SyncBillingAddress;
use App\Models\Central\CentralUser;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;
```
(`Cache` and `Log` are already imported; `Cashier` is already imported;
`PendingTenantProvision` is already imported.)

**3b. New method** — add anywhere among the other `protected function handle*`
methods (e.g. directly after `handleCustomerSubscriptionCreated`, since both
deal with completing a pending checkout):

```php
/**
 * Completes a redirect-flavoured checkout (iDEAL, Bancontact, ...) once
 * Stripe has finished converting/attaching its reusable PaymentMethod to
 * the customer — the thing CompleteRedirectCheckout cannot wait for
 * synchronously. See FinalizeCheckoutSubscription's doc comment for why the
 * two share that action instead of duplicating its logic.
 *
 * @param  array{data: array{object: array{id?: string, customer?: string}}}  $payload
 */
protected function handleSetupIntentSucceeded(array $payload): Response
{
    $setupIntentId = $payload['data']['object']['id'] ?? null;

    $pending = is_string($setupIntentId)
        ? PendingTenantProvision::where('stripe_setup_intent_id', $setupIntentId)->first()
        : null;

    // Not ours, or CompleteRedirectCheckout already finished it (cards, or
    // an async method that happened to attach before the redirect landed)
    // — no-op either way.
    if ($pending === null || $pending->stripe_subscription_id !== null) {
        return $this->successMethod();
    }

    $handle = function () use ($pending, $setupIntentId): Response {
        // Re-fetch rather than trust the event payload: what's needed is
        // the freshest PaymentMethod-attachment state, and the payload is a
        // snapshot from whenever Stripe built the event.
        $setupIntent = Cashier::stripe()->setupIntents->retrieve(
            $setupIntentId,
            ['expand' => ['payment_method']],
        );
        $paymentMethod = $setupIntent->payment_method instanceof PaymentMethod
            ? $setupIntent->payment_method
            : null;

        if ($paymentMethod === null || $paymentMethod->customer !== $setupIntent->customer) {
            // Stripe fires setup_intent.succeeded once its own attach is
            // done, so this shouldn't normally happen — but if it does,
            // report and acknowledge rather than repeat the original crash;
            // Stripe retries events its webhook endpoint doesn't 2xx.
            report(new \RuntimeException("setup_intent.succeeded for {$setupIntentId} but PaymentMethod not attached yet"));

            return $this->successMethod();
        }

        $billable = CentralUser::where('global_id', $pending->global_id)->first();

        if ($billable !== null) {
            SyncBillingAddress::run($billable, $paymentMethod);
        }

        try {
            FinalizeCheckoutSubscription::run($pending, $paymentMethod, $billable);
        } catch (\Laravel\Cashier\Exceptions\IncompletePayment $e) {
            // The first invoice needs a 3DS challenge with no browser
            // listening for it here — same known gap CompleteRedirectCheckout
            // already documents for the redirect path. Log and acknowledge;
            // the customer sees requires_verification copy on their next
            // visit to tenants.mine via the subscription's own stripe_status.
            report($e);
        } catch (ApiErrorException $e) {
            report($e);
        }

        return $this->successMethod();
    };

    return Cache::lock("checkout-settle:{$setupIntentId}", 10)->block(5, $handle);
}
```

This mirrors `handleCustomerSubscriptionCreated`'s own lock pattern
(top of the file, lines 42-52) closely on purpose, for consistency within
this class.

---

## Step 4 — Modify `lang/en/billing.php`

In the `'checkout' => [...]` array (currently lines 7-19), add one key. Insert
it right after `'setting_up'`:

```php
        'setting_up' => 'Your tenant is being set up.',
        'confirming_payment' => 'Confirming your payment. This can take a moment.',
```

---

## Testing

Follow this repo's Pest conventions (`vendor/bin/sail artisan test --compact --filter=...`
per file while iterating; see `.claude/rules/testing.md` for full-suite
caveats — do not run the full suite unless asked).

1. **`FinalizeCheckoutSubscriptionTest`** (new) — given a `PendingTenantProvision`,
   a real/faked attached `PaymentMethod`, and a `CentralUser`, asserts a
   `Subscription` is created and `pending_tenant_provisions.status`/
   `stripe_subscription_id` end up as `SettleCheckout` would set them
   (mirror the existing `CreateInlineSubscriptionTest`/`SettleCheckoutTest`
   setup if those exist — check `tests/Feature/Actions/Billing/Checkout/`
   first and follow whatever fixture pattern is already there rather than
   inventing a new one).

2. **`CompleteRedirectCheckoutTest`** (existing file — add cases, don't
   replace it) —
   - New case: `ResolveSetupIntent` resolves a PaymentMethod whose
     `customer` does **not** match the billable's `stripe_id`. Assert:
     redirect to `tenants.mine` with `session('info')` equal to
     `__('billing.checkout.confirming_payment')`; assert no Stripe
     subscription-creation call happened (e.g. `Http::assertNothingSent()`
     if HTTP-faked, or that `pending_tenant_provisions.stripe_subscription_id`
     is still null afterward).
   - New case: successful synchronous completion clears
     `session('registration.wizard_state')` — set that session key before
     calling `handle()`, assert it's gone after.
   - Existing happy-path case(s) should still pass unmodified — the
     attached-check only changes behavior when `customer` doesn't match,
     which the existing passing tests' fixtures presumably don't trigger
     (confirm this after the change; if an existing test's fixture doesn't
     set `payment_method->customer` to match the billable's `stripe_id`, it
     will need that added, since the new guard is stricter than before).

3. **New test file** `tests/Feature/Http/Controllers/Billing/WebhookControllerSetupIntentTest.php`
   (or add to wherever existing `WebhookController` tests already live —
   check `tests/Feature/` for an existing `WebhookControllerTest` first and
   add cases there instead if one exists) —
   - Case: `PendingTenantProvision` exists with a matching
     `stripe_setup_intent_id`, no `stripe_subscription_id` yet. Fake
     Stripe's `setupIntents->retrieve()` to return a PaymentMethod whose
     `customer` matches. POST the `setup_intent.succeeded` webhook payload.
     Assert a subscription now exists and the pending row's
     `stripe_subscription_id` is set.
   - Case: same, but the faked PaymentMethod's `customer` does **not**
     match (not yet attached). Assert `report()` was called (or just that
     no exception propagates and no subscription was created) and the
     response is still a success status (Stripe must not see a non-2xx, or
     it will retry aggressively).
   - Case: `stripe_subscription_id` already set on the pending row (already
     completed via the redirect path). Assert the handler no-ops (no second
     subscription created) — this is the idempotency/race-safety case.

## Not in scope here

- Any change to Stripe Dashboard payment-method configuration.
- Adding support for a payment method type not already enabled in Stripe —
  this fixes the completion path for whatever's already enabled, it
  doesn't add new methods.
- Fixing `⚡mine.blade.php`'s lack of `x-ui.alert` rendering — noted above as
  pre-existing and out of scope.
- The residual session-cleanup gap for the genuinely-deferred-to-webhook
  case (see the interaction note at the top) — narrow edge case, not fixed
  here.
