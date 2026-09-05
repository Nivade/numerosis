# Prefill + reuse saved billing info on second-workspace checkout

**Status: ✅ Executed.** `App\Actions\Billing\FetchReusablePaymentMethods`,
`App\Actions\Billing\Checkout\ResolveSavedPaymentMethod`, and
`Checkout::subscribeWithSavedPaymentMethod()` all exist and are wired
together; `vatNumber` prefill present in `Checkout.php`.

## Context

`App\Livewire\Billing\Checkout` (the single checkout component, standalone
at `/checkout/{domain}` and embedded in the registration wizard's Payment
step — see `.claude/rules/billing-checkout.md`) always presents a blank
Stripe Address Element and a fresh Payment Element, even when the
authenticated `CentralUser` already has a Stripe customer (`stripe_id`) with
an address, VAT tax id, and/or attached card from a prior tenant checkout.
Every returning customer starting a second workspace has to retype
everything. Stripe's customer record is already the single source of truth
for billing address (per the rules file — no local address/VAT columns
exist), and `CentralUser` already reuses one `stripe_id` across all their
tenants, so the data to prefill/reuse from already exists; nothing currently
reads it back.

Two additions, both gated on `$billable->hasStripeId()` in `mount()`:

- **A) Prefill**: read address + first VAT tax id off the existing Stripe
  customer, feed them into the client-side Address Element's `defaultValues`
  and the `vatNumber` Livewire property.
- **B) Reuse**: list the customer's reusable (card-only) payment methods in a
  radio group, let the user pick one and skip SetupIntent/Elements
  confirmation entirely.

Both are additive to the existing protocol. New customers and anyone who
chooses "enter new payment method" go through the unchanged existing path.

## Decisions locked in for this pass

1. Reusing a saved card **hides** the Address Element and reuses the
   customer's address as-is (no per-checkout address edit in that branch).
2. Saved cards are shown as a **radio group** (supports more than one saved
   card, not just a single button).
3. **Card-only** reuse — no Link, no redirect/bank-auth methods (iDEAL,
   Bancontact are proven non-reusable this way, see the 2026-07-30 incident
   notes in `.claude/rules/billing-checkout.md`).
4. If fetching saved billing info/cards fails during `mount()`, show a
   **visible, non-blocking banner** ("Couldn't load your saved billing info —
   enter it fresh below.") rather than silently degrading. The fetch actions
   must distinguish "nothing saved" from "fetch failed" so `mount()` can set
   this banner only in the latter case.

## Constraints carried over from existing checkout code (do not violate)

- Never weaken the `$resolved->pending->domain === $this->pendingDomain` /
  `global_id` ownership checks — this exact class of bug has bitten this
  codebase twice (documented in `.claude/rules/billing-checkout.md`).
- Any new Stripe read that is load-bearing for completing a purchase must
  fail closed to user-facing copy via `ShowsMessageToUser` /
  `DomainException`, never leak `$e->getMessage()` — per
  `.claude/rules/exception-handling.md`. Non-blocking prefill reads are the
  one deliberate exception (see decision 4 — they still `report()`, they
  just don't throw).
- New public Livewire properties that are server-computed must be
  `#[Locked]` — a public property is client input by default (see the
  `$pendingDomain` lesson in `.claude/rules/billing-checkout.md`).
- Keep `Checkout` as the single implementation for both standalone and
  embedded usage.
- Action classes under `app/Actions/Billing/` (or `.../Checkout/`),
  `AsAction`-style, single purpose — matches existing pattern.

## Part A — Prefill address + VAT

### New: `app/Data/Billing/SavedBillingDetails.php`

Spatie-Data DTO, matching the existing `app/Data/Billing/` pattern:

```php
final class SavedBillingDetails extends Data
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $name = null,
        public ?string $vatNumber = null,
        public bool $fetchFailed = false,
    ) {}

    public function hasAddress(): bool
    {
        return $this->line1 !== null || $this->country !== null;
    }
}
```

`$fetchFailed` is what lets `mount()` tell "brand new / nothing saved" apart
from "Stripe call errored" (decision 4).

### New: `app/Actions/Billing/FetchSavedBillingDetails.php`

```php
/**
 * @method static SavedBillingDetails run(CentralUser $billable)
 */
class FetchSavedBillingDetails
{
    use AsAction;

    public function handle(CentralUser $billable): SavedBillingDetails
    {
        if (! $billable->hasStripeId()) {
            return new SavedBillingDetails();
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve(
                $billable->stripeIdOrFail(),
                ['expand' => ['tax_ids']],
            );
        } catch (ApiErrorException $e) {
            report($e);

            return new SavedBillingDetails(fetchFailed: true);
        }

        $address = $customer->address;
        $taxId = $customer->tax_ids?->data[0] ?? null;

        return new SavedBillingDetails(
            line1: $address?->line1,
            line2: $address?->line2,
            city: $address?->city,
            state: $address?->state,
            postalCode: $address?->postal_code,
            country: $address?->country,
            name: $customer->name,
            vatNumber: $taxId?->value,
        );
    }
}
```

Deliberately does **not** throw for a Stripe error — a prefill failure must
not block checkout from rendering — but it no longer swallows silently
either: `fetchFailed` surfaces to `mount()`, which turns it into the banner.

### `Checkout.php` changes for A

New locked property:

```php
#[Locked]
public ?array $savedBillingAddress = null;   // Stripe Address Element defaultValues shape, or null

#[Locked]
public bool $savedBillingFetchFailed = false;
```

In `mount()`, right after resolving `$billable` (same place `$customerEmail`
is set from `$billable`, before the `ResumeCheckout` try block — so a
mount that's about to redirect/error from `ResumeCheckout` doesn't waste
the extra Stripe round trip, but the property is still set from the same
source as everything else derived from `$billable`):

```php
if ($billable instanceof CentralUser && $billable->hasStripeId()) {
    $saved = FetchSavedBillingDetails::run($billable);

    if ($saved->fetchFailed) {
        $this->savedBillingFetchFailed = true;
    } elseif ($saved->hasAddress()) {
        $this->savedBillingAddress = [
            'name' => $saved->name,
            'address' => [
                'line1' => $saved->line1,
                'line2' => $saved->line2,
                'city' => $saved->city,
                'state' => $saved->state,
                'postal_code' => $saved->postalCode,
                'country' => $saved->country,
            ],
        ];
    }

    $this->vatNumber = $saved->vatNumber;
}
```

Array shape matches Stripe's Address Element `defaultValues` option
verbatim — no client-side reshaping needed. `$vatNumber` is already an
unlocked, client-writable property today, re-validated server-side against
Stripe's `createTaxId` in `SyncBillingAddress` — defaulting it here adds no
new trust boundary.

### `stripe-checkout.js` changes

Add a 6th param to the Alpine factory, thread into the Address Element:

```js
Alpine.data('stripeCheckout', (clientSecret, publishableKey, returnUrl, declineCodes, customerEmail, savedBillingAddress) => ({
  ...
  const addressElement = this.elements.create('address', {
    mode: 'billing',
    defaultValues: savedBillingAddress ?? undefined,
  });
```

`defaultValues` is creation-time-only — no reactive update needed.

### `checkout.blade.php` changes

Add the 6th arg:

```blade
x-data="stripeCheckout(@js($checkoutClientSecret), @js($checkoutPublishableKey), @js(route('checkout.subscription.return')), @js(__('billing.decline_codes')), @js($customerEmail), @js($savedBillingAddress))"
```

Add the banner (decision 4), near the top of the form:

```blade
@if($savedBillingFetchFailed)
    <flux:callout variant="warning" icon="exclamation-triangle">
        {{ __('billing.checkout.saved_billing_fetch_failed') }}
    </flux:callout>
@endif
```

(`Couldn't load your saved billing info — enter it fresh below.` — add this
string to the existing `lang/en/billing.php`-equivalent translation file,
matching however other checkout copy in that file is keyed.)

`address-element.blade.php` needs no change — `wire:model="vatNumber"`
already reflects `$this->vatNumber`.

## Part B — Reuse a saved payment method

### New: `app/Data/Billing/SavedPaymentMethodOption.php`

```php
final class SavedPaymentMethodOption extends Data
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public bool $isDefault,
    ) {}
}
```

### New: `app/Actions/Billing/FetchReusablePaymentMethods.php`

```php
/**
 * @method static FetchReusablePaymentMethodsResult run(CentralUser $billable)
 */
class FetchReusablePaymentMethods
{
    use AsAction;

    public function handle(CentralUser $billable): FetchReusablePaymentMethodsResult
    {
        if (! $billable->hasStripeId()) {
            return new FetchReusablePaymentMethodsResult(collect());
        }

        try {
            $paymentMethods = Cashier::stripe()->customers->allPaymentMethods(
                $billable->stripeIdOrFail(),
                ['type' => 'card', 'limit' => 10],
            );
            $customer = Cashier::stripe()->customers->retrieve($billable->stripeIdOrFail());
        } catch (ApiErrorException $e) {
            report($e);

            return new FetchReusablePaymentMethodsResult(collect(), fetchFailed: true);
        }

        $defaultId = $customer->invoice_settings->default_payment_method ?? null;

        $options = collect($paymentMethods->data)->map(fn (PaymentMethod $pm) => new SavedPaymentMethodOption(
            id: $pm->id,
            brand: $pm->card->brand ?? 'card',
            last4: $pm->card->last4 ?? '',
            expMonth: $pm->card->exp_month ?? 0,
            expYear: $pm->card->exp_year ?? 0,
            isDefault: $pm->id === $defaultId,
        ));

        return new FetchReusablePaymentMethodsResult($options);
    }
}
```

`FetchReusablePaymentMethodsResult` is a tiny wrapper
(`public function __construct(public Collection $options, public bool $fetchFailed = false) {}`)
so this action can signal failure the same way `SavedBillingDetails` does,
without overloading an empty collection to mean two different things.

### New: `app/Actions/Billing/Checkout/ResolveSavedPaymentMethod.php`

Required server-side re-check — the payment method id the user picks is
client-supplied (radio value), and while `FetchReusablePaymentMethods` only
ever lists the caller's own PMs, nothing stops the client from submitting a
different id. Mirrors `ResolveSetupIntent`'s customer-match check, plus the
type restriction:

```php
/**
 * @method static PaymentMethod run(CentralUser $billable, string $paymentMethodId)
 */
class ResolveSavedPaymentMethod
{
    use AsAction;

    private const REUSABLE_TYPES = ['card'];

    public function handle(CentralUser $billable, string $paymentMethodId): PaymentMethod
    {
        try {
            $paymentMethod = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId);
        } catch (ApiErrorException $e) {
            report($e);

            throw new SavedPaymentMethodUnavailable(__('billing.checkout.saved_payment_method_unavailable'), previous: $e);
        }

        $customerId = is_string($paymentMethod->customer) ? $paymentMethod->customer : $paymentMethod->customer?->id;

        if (! $billable->hasStripeId() || $customerId !== $billable->stripe_id) {
            throw new SavedPaymentMethodUnavailable(__('billing.checkout.saved_payment_method_unavailable'));
        }

        if (! in_array($paymentMethod->type, self::REUSABLE_TYPES, true)) {
            throw new SavedPaymentMethodUnavailable(__('billing.checkout.saved_payment_method_unavailable'));
        }

        return $paymentMethod;
    }
}
```

New exception `app/Exceptions/Billing/SavedPaymentMethodUnavailable.php`,
`extends DomainException implements ShowsMessageToUser`, matching
`BillingAddressUnavailable`'s shape. Both the wrong-customer and
wrong-type cases return the same generic message deliberately — do not leak
"this payment method belongs to someone else" to the caller attempting
misuse.

### Refactor: share the "already completed" replay guard

`ResolveSetupIntent`'s private `hasLiveSubscription()` guard (turns a
replayed confirm into "settle from what already exists" instead of
double-charging) is needed identically by the new saved-PM branch, which has
no SetupIntent to route through `ResolveSetupIntent`. Extract instead of
duplicating — same reasoning documented for `FinalizeCheckoutSubscription`
in `.claude/rules/billing-checkout.md`.

New: `app/Actions/Billing/Checkout/AssertPendingReservationIsFresh.php`

```php
/**
 * @throws CheckoutAlreadyCompleted
 *
 * @method static void run(PendingTenantProvision $pending, CentralUser $billable)
 */
class AssertPendingReservationIsFresh
{
    use AsAction;

    public function handle(PendingTenantProvision $pending, CentralUser $billable): void
    {
        if ($pending->stripe_subscription_id === null) {
            return;
        }

        $subscription = $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first();

        if (! $subscription || $subscription->stripe_status !== 'canceled') {
            throw new CheckoutAlreadyCompleted(__('billing.checkout.already_subscribed'));
        }
    }
}
```

`ResolveSetupIntent::handle()` replaces its inline `hasLiveSubscription()`
block with a call to this action and deletes the private method — behavior
unchanged, just de-duplicated ahead of the new caller.

### `Checkout.php` changes for B

New locked properties:

```php
#[Locked]
public array $savedPaymentMethods = [];   // array<int, array{id,brand,last4,expMonth,expYear,isDefault}>

#[Locked]
public bool $savedPaymentMethodsFetchFailed = false;
```

In `mount()`, alongside the Part-A block, same `hasStripeId()` guard:

```php
if ($billable instanceof CentralUser && $billable->hasStripeId()) {
    $result = FetchReusablePaymentMethods::run($billable);
    $this->savedPaymentMethods = $result->options->map->toArray()->all();
    $this->savedPaymentMethodsFetchFailed = $result->fetchFailed;
}
```

(Both Part A and B blocks read `$billable->hasStripeId()` independently —
fine to combine into one `if` block sharing the check, implementer's call
on tidiness; two Stripe round trips either way since they hit different
endpoints.)

New public method, parallel to `subscribe()` but for the no-SetupIntent
path — re-implements the same guard *sequence* (pending-row lookup,
`global_id` ownership) rather than routing through `ResolveSetupIntent`,
which is SetupIntent-specific:

```php
public function subscribeWithSavedPaymentMethod(string $paymentMethodId): void
{
    $this->paymentError = null;

    $billable = GetAuthenticatedUser::run();

    if (! $billable instanceof CentralUser || ! $billable->hasStripeId()) {
        $this->paymentError = __('billing.checkout.session_expired');
        return;
    }

    $pending = PendingTenantProvision::find($this->pendingDomain);

    if (! $pending || $pending->global_id !== $billable->global_id) {
        $this->paymentError = __('billing.checkout.session_expired');
        return;
    }

    try {
        AssertPendingReservationIsFresh::run($pending, $billable);
    } catch (CheckoutAlreadyCompleted) {
        $this->settleFromPendingSubscription();
        return;
    }

    try {
        $paymentMethod = ResolveSavedPaymentMethod::run($billable, $paymentMethodId);
    } catch (ShowsMessageToUser $e) {
        $this->paymentError = $e->getMessage();
        return;
    }

    try {
        $subscription = CreateInlineSubscription::run($pending, $paymentMethod->id, $billable);
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

Notes:

- `CreateInlineSubscription` needs **zero changes** — it already accepts any
  `string $paymentMethodId`, and its optional 3rd `$billable` arg avoids a
  redundant `BillableResolver::resolve()` call.
- Per decision 1, no `SyncBillingAddress::run(...)` call in this branch —
  the address already on the Stripe customer is left as-is.
- `handleIncompletePayment()` / `settle()` are reused unchanged — an
  off-session reuse of a saved card can still 3DS-challenge, and the
  existing `requires-action` JS listener doesn't care which server method
  dispatched it.

### UI wiring for B

New: `resources/views/components/billing/saved-payment-method.blade.php`,
rendered in `checkout.blade.php` **before** `<x-billing.payment-element />`,
only when `$savedPaymentMethods` is non-empty. Radio group (decision 2),
Alpine-scoped selection state feeding the Livewire call:

```blade
@if($savedPaymentMethodsFetchFailed)
    <flux:callout variant="warning" icon="exclamation-triangle">
        {{ __('billing.checkout.saved_payment_methods_fetch_failed') }}
    </flux:callout>
@elseif(! empty($savedPaymentMethods))
    <div
        x-data="{ mode: 'saved', selectedId: '{{ $savedPaymentMethods[0]['id'] }}' }"
        class="rounded-2xl border p-6 space-y-3"
    >
        @foreach($savedPaymentMethods as $pm)
            <label class="flex items-center gap-3">
                <input
                    type="radio"
                    name="pm-choice"
                    x-model="selectedId"
                    value="{{ $pm['id'] }}"
                    @change="mode = 'saved'"
                    {{ $loop->first ? 'checked' : '' }}
                />
                <span>
                    {{ ucfirst($pm['brand']) }} ending in {{ $pm['last4'] }}
                    (exp {{ $pm['expMonth'] }}/{{ $pm['expYear'] }})
                    @if($pm['isDefault'])<flux:badge size="sm">Default</flux:badge>@endif
                </span>
            </label>
        @endforeach
        <label class="flex items-center gap-3">
            <input type="radio" name="pm-choice" @change="mode = 'new'" />
            {{ __('billing.checkout.use_different_payment_method') }}
        </label>

        <flux:button
            type="button"
            x-show="mode === 'saved'"
            x-on:click="$wire.subscribeWithSavedPaymentMethod(selectedId)"
            variant="primary"
        >
            {{ __('billing.checkout.subscribe') }}
        </flux:button>
    </div>
@endif
```

The existing Payment/Address Elements block + its own submit button gets
`x-show="mode === 'new'"` added to the same outer Alpine scope (requires
lifting the `mode` state up one level so both blocks share it — merge this
new `x-data` with the existing `stripeCheckout(...)` factory's root element,
or nest and reference via `$data` — implementer's call based on current
template structure, no business logic involved).

## Tests to add

Following `tests/Feature/Livewire/Billing/CheckoutTest.php`'s existing style
(`Livewire::test(Checkout::class, ['domain' => ...])`, real
`Cashier::stripe()` calls against Stripe test mode, no mocking):

**`tests/Feature/Actions/Billing/FetchSavedBillingDetailsTest.php`**
- empty, `fetchFailed: false` for a billable with no `stripe_id`
- empty, `fetchFailed: false` for a Stripe customer with no address set
- populated address + VAT for a customer with both set (check
  `SyncBillingAddressTest`'s seeding pattern before writing this)

**`tests/Feature/Actions/Billing/FetchReusablePaymentMethodsTest.php`**
- empty for no `stripe_id`
- empty for a customer with no attached PMs
- returns a card PM, `isDefault` true when matching
  `invoice_settings.default_payment_method`
- excludes a non-card-type PM

**`tests/Feature/Actions/Billing/Checkout/ResolveSavedPaymentMethodTest.php`**
- resolves a card PM attached to the billable's own customer
- throws `SavedPaymentMethodUnavailable` when the PM belongs to a *different*
  Stripe customer (the security-relevant case — tampered radio value)
- throws `SavedPaymentMethodUnavailable` for a non-card-type PM even when it
  belongs to the billable

**`CheckoutTest` additions** (extend existing class):
- `test_it_prefills_the_saved_billing_address_for_a_returning_customer`
- `test_it_leaves_the_saved_billing_address_null_for_a_brand_new_customer`
- `test_it_shows_a_banner_when_fetching_saved_billing_details_fails`
- `test_it_lists_reusable_payment_methods_for_a_returning_customer`
- `test_it_shows_a_banner_when_fetching_saved_payment_methods_fails`
- `test_subscribing_with_a_saved_payment_method_creates_the_subscription_and_settles`
- `test_it_refuses_a_saved_payment_method_belonging_to_a_different_customer`
  (create PM on victim's Stripe customer, call
  `subscribeWithSavedPaymentMethod` as an attacker with their own distinct
  `stripe_id`, assert `paymentError` set and no subscription/redirect)
- `test_replaying_subscribe_with_saved_payment_method_settles_the_existing_subscription`
  (mirrors the `CheckoutAlreadyCompleted` replay test for `subscribe()`)
- `test_savedPaymentMethods_and_savedBillingAddress_cannot_be_set_by_the_client`
  (locked-property test, same shape as the existing `pendingDomain` one)

**`ResolveSetupIntentTest`** (existing file) — after extracting
`AssertPendingReservationIsFresh`, assert the "already completed" replay
behavior still passes unchanged (regression guard on the extraction).

## Verification

1. `vendor/bin/sail artisan test --compact --filter=Checkout` — component +
   action tests above.
2. `vendor/bin/sail bin pint --dirty --format agent` on all touched/new PHP
   files.
3. Manual: in Sail, complete one full checkout as a fresh `CentralUser`
   (card), then start a **second** checkout (`/get-started` again) as the
   same user — confirm the Address Element pre-fills, VAT input pre-fills,
   the saved-card radio appears with correct brand/last4, and choosing it
   completes a subscription without re-entering payment details. Also
   confirm "use a different payment method" still goes through the normal
   Elements flow untouched.

## Critical files

- `app/Livewire/Billing/Checkout.php`
- `app/Actions/Billing/FetchSavedBillingDetails.php` (new)
- `app/Actions/Billing/FetchReusablePaymentMethods.php` (new)
- `app/Actions/Billing/Checkout/ResolveSavedPaymentMethod.php` (new)
- `app/Actions/Billing/Checkout/AssertPendingReservationIsFresh.php` (new, extracted from `ResolveSetupIntent`)
- `app/Actions/Billing/Checkout/ResolveSetupIntent.php` (refactor)
- `app/Data/Billing/SavedBillingDetails.php`, `SavedPaymentMethodOption.php` (new)
- `app/Exceptions/Billing/SavedPaymentMethodUnavailable.php` (new)
- `resources/js/stripe-checkout.js`
- `resources/views/livewire/billing/checkout.blade.php`
- `resources/views/components/billing/saved-payment-method.blade.php` (new)
- `tests/Feature/Livewire/Billing/CheckoutTest.php`
