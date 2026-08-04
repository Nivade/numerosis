# Plan: in-app Livewire checkout, built for multiple payment methods

**Status: ✅ Executed.** All 4 phases shipped: `CheckoutIntent` contract
(`d9686d4`), inline Payment Element (`1f31c61`), tenant subscription lifecycle
(`7baca5c`), hosted Stripe Checkout removed (`dd9b964`).

## Goal

Collect the payment method and create the subscription inside our own Blade/Livewire
views instead of redirecting to `checkout.stripe.com` — and land in a state where
enabling iDEAL, Bancontact or SEPA Direct Debit is a Stripe Dashboard toggle, not a
code change.

## Constraint that shapes everything

Card details still have to be entered inside Stripe-hosted iframes (Stripe Elements).
Rendering our own `<input>` for a PAN moves the app from PCI SAQ-A to SAQ-D. So
"custom checkout" means: our page, our layout, our plan summary, our buttons, our
error states, our Livewire lifecycle — with a Stripe **Payment Element** mounted into
one styled container, themed through the `appearance` API to match Tailwind/Flux.

Rejected: Stripe **Embedded Checkout** (`ui_mode: 'embedded'`). Less work, but the
panel stays Stripe's UI and Stripe's copy.

## Decisions

1. **Payment Element**, not Card Element — one adaptive widget exposing every method
   enabled in the Dashboard for the currency.
2. **Fourth wizard step** inside `Registration`, plus a return route for methods that
   navigate off-site.
3. `@stripe/stripe-js` — approved.

## What this plan changes beyond the minimum, and why

The minimal version of this feature is "swap the gateway, add a Livewire step". That
version quietly bakes in four things that are cheap now and expensive later. Since big
changes are on the table, the plan fixes them:

| Change | Why the minimal version is wrong |
|---|---|
| Redesign `CheckoutGateway` to return a `CheckoutIntent`, not a `Responsable` | `start(..., $successUrl, $cancelUrl): Responsable` is Stripe-hosted-redirect vocabulary. An inline gateway has no success/cancel URL and nothing to redirect to, so it would have to return a no-op `Responsable` — a lie the type system would then propagate. |
| Keep provisioning immediate, and add the **suspend path** it has always been missing | Cards authorise in seconds; SEPA settles in **days**. The tempting fix is to gate provisioning on payment — but the app already provisions unpaid tenants via 14-day trials, and has no way to take one back. The gap is enforcement, not timing. |
| Shrink Stripe metadata to an opaque reservation id | Today `TenantRegistrationData` is pinned as wire format because every field round-trips through Stripe. Once the pending row is authoritative, metadata only needs a pointer, and the whole struct becomes free to refactor. |
| Delete the hosted-checkout path in a follow-up phase | Keeping it "as a config swap" means two payment flows, two provisioning entry points and two sets of tests, forever, to hedge a decision already made. |

## Architecture: the gateway contract

Replace:

```php
public function start(TenantRegistrationData $r, ?string $successUrl = null, ?string $cancelUrl = null): Responsable;
```

with:

```php
public function begin(TenantRegistrationData $registration): CheckoutIntent;
```

`App\Data\Billing\CheckoutIntent` is a small sealed-ish union (abstract base +
subclasses, `spatie/laravel-data` like the rest of `app/Data`):

- `RedirectCheckout(string $url)` — hosted Stripe, and the local dev shortcut
- `InlineCheckout(string $clientSecret, string $publishableKey)` — Payment Element
- `AlreadyProvisioned` — the "tenant exists, nothing to pay" case, currently expressed
  as an early `to_route()` inside `CompleteCheckout`

`StartSubscriptionCheckout` keeps `PlanPolicy` + `ReserveTenantDomain` and now returns
the intent to its caller instead of a response. The wizard step consumes it directly;
the (temporarily surviving) HTTP route maps it to a response. This is what lets one
contract honestly describe all three gateways.

## Target flow

1. Wizard step `Plan` validates, calls `StartSubscriptionCheckout` (unchanged:
   `PlanPolicy` check + `ReserveTenantDomain`), stores the returned `CheckoutIntent`,
   and advances. Its two blank-state guards (`company_name`, `domain`) stay.
2. `InlineCheckoutGateway::begin()` persists plan and cycle onto the
   `pending_tenant_provisions` row, creates a SetupIntent, and returns
   `InlineCheckout`.
3. Step `Payment` renders: our order summary (plan, cycle, price, trial, domain — from
   `PaymentPlanRepository` + `MoneyFormatter`, no Stripe data needed) and the Element
   mounted on the SetupIntent's client secret, `return_url` →
   `checkout.subscription.return`.
4. Submit → `stripe.confirmSetup({ redirect: 'if_required' })`.
   - **Card path**: no navigation. JS calls `$wire.subscribe(setupIntentId)`.
   - **Redirect path** (iDEAL, Bancontact): browser leaves and returns to the return
     route, which resolves the SetupIntent from `setup_intent_client_secret` and joins
     the same action.
5. `CreateInlineSubscription` — what `StripeCheckoutGateway` does today minus
   `->checkout()`: `newSubscription('default', $priceId)->withMetadata(...)->trialDays(...)->create($pm, [], [...])`.
6. `SettleCheckout` decides whether to provision now or wait (below).
7. `IncompletePayment` (3DS) → catch, hand `$e->payment->client_secret` back to the
   component, JS runs `stripe.confirmPayment(...)`, then `$wire.confirmed()` re-checks
   server-side and rejoins step 6.

**The client never names a price, an amount or a plan.** `subscribe()` accepts only a
SetupIntent id, which is then verified against the authenticated user's Stripe customer;
plan and cycle come from the pending row.

## Provisioning and settlement — the substantive change

**Provision immediately; suspend if payment fails.** Not "wait for settlement, then
provision". An earlier draft of this plan chose the opposite; the reasoning below is
why it was reversed, recorded because the cautious option looks obviously safer and is
not.

```
App\Actions\Billing\Checkout\SettleCheckout
  always                     → provision (ProvisionTenant, unchanged trigger)
  payment settled            → tenant active
  payment still processing   → tenant active, billing state = AwaitingPayment (UI only)
  payment failed / expired   → SuspendTenant
```

### Why not gate on payment

1. **The application already provisions before payment, via trials.** Starter carries
   `trial_days => 14`: the tenant is created at signup with zero money collected. A gate
   would make a SEPA debit — authorised, settling in ~3 days — stricter than a
   deliberate 14-day unpaid trial. That is backwards.
2. **The suspend path is required either way**, so gating buys nothing and costs a
   second mechanism. Trial expiry and failed card renewals both need
   `SuspendTenant` + an access gate. Once that exists, "provision now, suspend on
   failure" is the same machinery pointed at one more trigger. Gating instead adds
   `AwaitingPayment`-as-a-blocker, a reservation-release path, and a webhook-miss
   backstop — all of which exist purely to avoid using the mechanism already being
   built.
3. **Gating is worst for exactly the customers iDEAL exists for.** A Dutch customer
   authorises at their bank, returns, and is shown "we'll email you in a few days"
   instead of their tenant — on the most likely conversion path in this market.

The cost of being wrong: a tenant database exists for a few days that may never be paid
for. At ~1.9s to create and one MySQL schema, that is not a real constraint here.

**When the opposite answer would be right:** if provisioning were expensive (per-tenant
infrastructure, a paid seat licence, an external system billed per tenant), or if abuse
volume were plausible. Neither holds. Revisit this if either changes.

### Supporting changes

- **`AwaitingPayment` stays in the enum, but as a billing state, not a provisioning
  gate.** The tenant works; `⚡mine.blade.php` and the panel show an honest "payment
  settling" banner. This is the one piece of the cautious design worth keeping — the
  state is real, it just should not block anything.
- **Cap concurrent unpaid tenants per user (1–2).** Provisioning before settlement is
  otherwise a free-database faucet. A cap closes that without reintroducing a gate,
  and is checked in `StartSubscriptionCheckout` alongside the existing `PlanPolicy`.
- **`WebhookController` handles `invoice.payment_succeeded`** → clear the
  `AwaitingPayment` banner; **`invoice.payment_failed`, `customer.subscription.updated`
  (`past_due`/`unpaid`/`incomplete_expired`), `customer.subscription.deleted`** →
  `SuspendTenant`.
- **Suspended and never paid is pruned after ~30 days**, reusing
  `tenancy:prune-orphaned-databases` rather than a new command. Suspension keeps the
  data because dunning is the customer's chance to recover; thirty days past that, it
  is not.
- **`tenancy:prune-stalled-provisions` is untouched.** Its `Reserved`/`Provisioning`
  branches still describe reality, since nothing now sits in a long-lived pre-provision
  state.

## The access gate that does not exist

**Pre-existing gap, surfaced while planning this — not caused by it.** Grepping `app/`
for `subscribed(`, `onTrial(`, `past_due`, `hasIncompletePayment` returns two hits, both
Filament table colour columns. Nothing anywhere gates tenant access on subscription
status.

So today, with the hosted checkout: Starter carries `trial_days => 14`, the tenant is
provisioned at signup, the trial ends, the card fails — and the tenant keeps working
forever. The free-provisioned-tenant problem is already live, via trials, on cards.

That is why `SuspendTenant` is not a SEPA-specific concern. Minimum shape:

- `customer.subscription.updated` → `past_due` / `unpaid`, and
  `customer.subscription.deleted` → mark the tenant suspended
- a middleware on the tenant panel that refuses access to a suspended tenant and routes
  the owner to a "fix your payment method" screen (Cashier's billing portal is already
  wired at `/billing-portal`)
- keep the data. Suspension is not deletion, and Stripe's grace/dunning window is the
  customer's chance to recover.

This is worth doing whether or not the inline checkout ships, and it should probably be
its own branch rather than riding along with this one. It is also the load-bearing
piece of the provision-immediately decision above: without it, that decision really
does produce free tenants.

## Data model

`pending_tenant_provisions` becomes the checkout aggregate rather than a reservation
flag. New columns: `payment_plan`, `billing_cycle`, `stripe_setup_intent_id`,
`stripe_subscription_id`.

Why the row and not the session or the wizard state:

- it survives a refresh, a closed tab, an off-site redirect and a session
  regeneration. After iDEAL bounces the browser into a fresh page load with no wizard
  state, the row is the only thing that still knows what was being bought.
- it is already scoped by `global_id`, so "is this my reservation" reuses the ownership
  semantics `ReserveTenantDomain` established.
- no session carrier means no tamper surface.

A missing or foreign row raises `App\Exceptions\Billing\CheckoutSessionExpired`
(a `DomainException`, so the UI may show it verbatim per
`.claude/rules/exception-handling.md`) and returns the user to the wizard.

### Consequence: Stripe metadata shrinks

The webhook fallback exists for "our process died between `create()` and dispatch".
With the pending row authoritative, the subscription metadata only needs
`['pending_provision_id' => …, 'domain' => …]` — the webhook re-reads the row for
everything else. That retires the "field names are wire format and must not change
without a coordinated migration" constraint on `TenantRegistrationData`, which is
currently the reason that struct cannot be refactored.

The constraint only fully lifts once the hosted gateway is gone (it still needs full
metadata), which is one more reason to schedule that deletion rather than keep it.

## Designing for more payment methods

1. **`createSetupIntent(['automatic_payment_methods' => ['enabled' => true]])`.** Never
   pin `payment_method_types => ['card']` — that single line is what would turn every
   future method into a code change.
2. **Return route exists from day one**, even shipping cards-only.
3. **No card semantics in the UI.** No "Card details" heading, no brand icons, no
   `pm_last_four` in confirmation copy. The Element renders its own per-method labels;
   the summary says "Payment method" and reads back whatever Stripe returns.
4. **Async settlement handled** — the `SettleCheckout` gate above.

Note on scope: iDEAL and Bancontact confirm the SetupIntent and hand back a **SEPA
mandate**, so renewals charge SEPA. They are not "redirect-flavoured cards"; enabling
either pulls SEPA's settlement semantics in with it, and therefore the suspend path.
Methods that cannot be saved for future payments simply never appear in a
SetupIntent-mode Element — correct failure, no guard needed.

## Concurrency and idempotency

`create()` is an unguarded Stripe write. A double submit means two subscriptions and
two charges. Cashier's `SubscriptionBuilder` does not expose Stripe's idempotency-key
request option, so guard it the way this codebase already guards provisioning
(`.claude/rules/tenant-provisioning.md`):

```php
Cache::lock("checkout:{$registration->domain}", 10)->block(5, fn () => …);
```

plus a server-side re-check of `subscribed('default')` / `hasIncompletePayment()`
inside the lock. The disabled button is advisory only.

## Frontend

- `resources/js/stripe-checkout.js` — Stripe.js loader, Element mount,
  `confirmSetup`/`confirmPayment`, `$wire` bridge.
- `resources/js/stripe-appearance.js` — builds the Element `appearance` object from
  the same CSS custom properties Tailwind emits, and **re-themes on theme change**.
  The app has a `Settings\Appearance` component and a dark mode; an Element themed once
  at mount will be visibly wrong the moment the user toggles.
- The mount container is `wire:ignore`; the Alpine component owns that DOM. A Livewire
  re-render that blows away the iframe loses the entered card with no error.

## Security

- Return route must verify the SetupIntent's `customer` equals the authenticated user's
  `stripe_id`, and that the pending row's `global_id` matches. Fail closed — this is
  the same class of check `CheckoutSuccessRequest` performs today for session ids, and
  the reason it exists.
- `STRIPE_KEY` reaches the browser. It is the publishable key; that is its purpose.
  Worth an explicit note in `.env.example` so nobody "fixes" it.
- Never log payment-method or SetupIntent identifiers alongside user identifiers.

## File manifest

Grouped by phase. Existing conventions followed: actions under `app/Actions/{Domain}/`,
contracts under `app/Contracts/`, data objects under `app/Data/`, exceptions grouped to
match the actions' layout, Blade components under `resources/views/components/{area}/`,
Livewire views mirroring the component namespace.

### Phase 1 — contract and data model

**New**

| Path | Purpose |
|---|---|
| `app/Data/Billing/CheckoutIntent.php` | abstract base for the gateway return type |
| `app/Data/Billing/Intents/RedirectCheckout.php` | hosted Stripe and local dev: a URL to send the browser to |
| `app/Data/Billing/Intents/InlineCheckout.php` | client secret + publishable key for the Element |
| `app/Data/Billing/Intents/AlreadyProvisioned.php` | "tenant exists, nothing to pay" — today an early `to_route()` buried in `CompleteCheckout` |
| `database/migrations/*_extend_pending_tenant_provisions_for_checkout.php` | `payment_plan`, `billing_cycle`, `stripe_setup_intent_id`, `stripe_subscription_id` |

**Changed**

| Path | Change |
|---|---|
| `app/Contracts/Billing/CheckoutGateway.php` | `start(…, $successUrl, $cancelUrl): Responsable` → `begin(TenantRegistrationData): CheckoutIntent` |
| `app/Services/Billing/Checkout/StripeCheckoutGateway.php` | conform; wrap its `Checkout` in `RedirectCheckout` |
| `app/Services/Billing/Checkout/LocalCheckoutGateway.php` | conform |
| `app/Actions/Billing/Checkout/StartSubscriptionCheckout.php` | return the intent; `asController` maps it to a response |
| `app/Models/Central/PendingTenantProvision.php` | fillable + `billing_cycle` enum cast |
| `database/factories/…/PendingTenantProvisionFactory.php` | new columns and states |

### Phase 2 — inline checkout (cards)

**New — backend**

| Path | Purpose |
|---|---|
| `app/Services/Billing/Checkout/InlineCheckoutGateway.php` | persists plan/cycle on the pending row, creates the SetupIntent, returns `InlineCheckout` |
| `app/Actions/Billing/Checkout/CreateInlineSubscription.php` | SetupIntent → Cashier subscription; propagates `IncompletePayment` |
| `app/Actions/Billing/Checkout/SettleCheckout.php` | provision-now vs await-payment gate |
| `app/Actions/Billing/Checkout/CompleteRedirectCheckout.php` | return-route handler (`setup_intent_client_secret` → verify → settle) |
| `app/Actions/Billing/Checkout/ResolveSetupIntent.php` | fetch + ownership check; shared by the step and the return route so the check cannot drift |
| `app/Http/Requests/Billing/CheckoutReturnRequest.php` | validates and authorises the return route, mirroring `CheckoutSuccessRequest`'s fail-closed posture |
| `app/Exceptions/Billing/CheckoutSessionExpired.php` | `DomainException` — missing or foreign reservation |
| `app/Exceptions/Billing/SetupIntentNotConfirmed.php` | `DomainException` — returned from the bank without a usable payment method |

**New — UI/UX**

| Path | Purpose |
|---|---|
| `app/Livewire/Tenant/Registration/Steps/Payment.php` | the fourth wizard step |
| `resources/views/livewire/tenant/registration/wizard/steps/payment.blade.php` | step body: summary + Element + errors |
| `resources/views/components/billing/order-summary.blade.php` | plan, cycle, price, trial, domain, total due today. Reused on plan-change screens |
| `resources/views/components/billing/payment-element.blade.php` | the `wire:ignore` mount container plus its Alpine wiring, isolated so no other view can accidentally re-render it |
| `resources/views/components/billing/payment-error.blade.php` | Stripe decline/auth errors, distinct from Livewire validation errors — different source, different recovery, should not look identical |
| `resources/views/components/billing/trial-notice.blade.php` | "free until <date>, then €X/month" — the single most-disputed line in any signup flow, so it gets one component and one source of truth |
| `resources/views/components/billing/secure-badge.blade.php` | Stripe/PCI reassurance next to the submit button. Conversion-relevant, and the reason people distrust non-hosted checkouts |
| `resources/views/components/billing/awaiting-payment-card.blade.php` | the days-long waiting state, used on both `⚡mine` and the post-submit screen |
| `resources/js/stripe-checkout.js` | Stripe.js loader, Element mount, `confirmSetup`/`confirmPayment`, `$wire` bridge |
| `resources/js/stripe-appearance.js` | builds the Element `appearance` from Tailwind's CSS custom properties; re-themes on dark-mode toggle |
| `lang/en/billing.php` | all checkout-facing copy, including a decline-code → human-sentence map. Stripe's raw `error.message` is not customer-grade English |

**Changed — UI**

| Path | Change |
|---|---|
| `app/Livewire/Tenant/Registration/Registration.php` | add `Payment::class` to `steps()`; the stepper picks up the fourth step automatically |
| `app/Livewire/Tenant/Registration/Steps/Plan.php` | drop the `redirectRoute('checkout.subscription')` tail; call `StartSubscriptionCheckout` and `nextStep()` |
| `resources/views/livewire/tenant/registration/wizard/steps/plan.blade.php` | button label "Continue to payment", not "Subscribe" — payment is now the next step, not this one |
| `resources/views/components/registration/navigation.blade.php` | the payment step's submit is owned by Alpine (it must await Stripe before the server round-trip), so this needs a slot or a bypass rather than a `wire:click` |
| `resources/views/pages/tenant/⚡mine.blade.php` | "payment settling" badge on an otherwise-normal ready tenant — a banner, not a fourth blocking state |
| `resources/views/components/ui/stepper.blade.php` | verify it renders four steps without layout breakage |
| `app/Enums/TenantProvisionStatus.php` | add `AwaitingPayment` |
| `app/Http/Controllers/Billing/WebhookController.php` | `invoice.payment_succeeded` → `SettleCheckout` |
| `config/billing.php` | bind `CheckoutGateway::class => InlineCheckoutGateway::class` |
| `routes/web.php` | add `GET checkout/subscription/return` |
| `resources/js/central.js` | register the checkout entry point |
| `package.json` | `@stripe/stripe-js` |
| `.env.example` | note that `STRIPE_KEY` is deliberately browser-exposed |

### Phase 3 — lifecycle (suspension, release, dunning)

**New — backend**

| Path | Purpose |
|---|---|
| `app/Actions/Tenancy/SuspendTenant.php` | mark suspended on `past_due`/`unpaid`/`incomplete_expired`/`deleted` |
| `app/Actions/Tenancy/RestoreTenant.php` | the inverse, on recovery |
| `app/Http/Middleware/EnsureTenantSubscriptionActive.php` | the access gate that does not exist today |
| `app/Contracts/Billing/UnpaidTenantQuota.php` + default implementation | cap on concurrent unpaid tenants per user, checked in `StartSubscriptionCheckout` |
| `app/Enums/Tenancy/TenantStatus.php` | active / suspended, if not folded onto a timestamp column |
| `database/migrations/*_add_suspended_at_to_tenants.php` | **must add a real column** — `Tenant::getCustomColumns()` has to name it or it silently lands in the `data` blob and no query can see it (`.claude/rules/tenant-provisioning.md`) |
| `app/Notifications/Billing/PaymentFailed.php` | dunning notice with a billing-portal link |
| `app/Notifications/Billing/TenantSuspended.php` | access revoked, data retained, here is how to fix it |
| `app/Notifications/Billing/PaymentConfirmed.php` | async payment settled; the banner clears |
| `app/Events/Billing/PaymentSettled.php` | broadcast so `⚡mine` updates live, matching `TenantProvisioned` |

**New — UI/UX**

| Path | Purpose |
|---|---|
| `resources/views/pages/tenant/⚡suspended.blade.php` | where the middleware sends a suspended tenant's owner: what happened, what it costs, one button to the billing portal |
| `resources/views/components/billing/payment-status-banner.blade.php` | persistent in-panel warning during the grace period, before suspension bites |
| `resources/views/components/billing/dunning-alert.blade.php` | retry-schedule detail for the owner |
| `resources/views/mail/billing/*.blade.php` | bodies for the four notifications above |
| `lang/en/billing.php` | extended with lifecycle copy |

**Changed**

| Path | Change |
|---|---|
| `app/Console/Commands/PruneOrphanedTenantDatabases.php` | also drop tenants suspended and never paid for >30 days |
| `app/Http/Controllers/Billing/WebhookController.php` | `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted` |
| `app/Providers/TenantAdminPanelProvider.php` | register `EnsureTenantSubscriptionActive` |
| `app/Filament/Admin/Resources/…` | surface `AwaitingPayment` and suspended tenants — support needs to see a stuck signup without a database console |

### Phase 4 — remove the hosted path

**Deleted**: `app/Services/Billing/Checkout/StripeCheckoutGateway.php`,
`app/Actions/Billing/Checkout/CompleteCheckout.php`,
`app/Actions/Billing/Checkout/CancelCheckout.php`,
`app/Http/Requests/Billing/CheckoutSuccessRequest.php`, and the
`checkout.tenant.success` / `checkout.tenant.failed` routes.

**Changed**: `TenantRegistrationData::toStripeMetadata()` shrinks to the reservation
pointer; `WebhookController` reads the pending row instead of decoding full metadata.

`LocalCheckoutGateway` stays — dev shortcut, and the proof the contract is swappable.

### UX decisions embedded in that manifest

- **Stripe errors are not validation errors.** A decline is the customer's bank
  refusing, recoverable by trying another method; a validation error is our form
  complaining. Same red box for both trains people to ignore the one that matters.
  Hence a separate component and a decline-code → sentence map in `lang/en/billing.php`.
- **The trial line gets its own component** because "free until when, then how much"
  is what customers dispute, and it currently exists nowhere.
- **`AwaitingPayment` is a badge on a working tenant, not a waiting room.** It can last
  days, so it says which method is settling and that we will email when it clears — but
  the tenant is usable throughout. Nothing blocks on it.
- **Suspension has a destination.** A middleware that blocks with a 403 produces a
  support ticket; one that routes to `⚡suspended` with a billing-portal button
  produces a payment.
- **The submit button moves out of the shared wizard navigation** for this step only —
  it has to await Stripe's confirmation before the Livewire round-trip, which
  `registration/navigation.blade.php` cannot express as a plain `wire:click`.

## Tests

- `CheckoutIntentTest` — each gateway returns the right variant; `StartSubscriptionCheckout`
  still reserves the domain and enforces `PlanPolicy`.
- `PaymentStepTest` — renders the summary for the reserved plan; foreign reservation
  refused; missing reservation raises `CheckoutSessionExpired`.
- `CreateInlineSubscriptionTest` — stubbed at the `newSubscription` seam; metadata,
  trial days and additional prices reach the builder; `IncompletePayment` propagates
  rather than being swallowed.
- `SettleCheckoutTest` — provisions exactly once whether the payment settled, is still
  processing, or the plan carries a trial; a `processing` payment yields a usable
  tenant carrying the `AwaitingPayment` badge.
- `UnpaidTenantQuotaTest` — a user at the cap cannot start a second unsettled checkout;
  a settled tenant does not count against it.
- `SuspendedTenantTest` — `invoice.payment_failed`, `past_due`, `unpaid`,
  `incomplete_expired` and `customer.subscription.deleted` each suspend the tenant; the
  panel refuses access and routes to `⚡suspended`; the data survives; recovering the
  subscription restores access. Includes the trial-expiry case, which is the one that
  is broken today.
- `CompleteRedirectCheckoutTest` — redirect-back provisions once; a SetupIntent
  belonging to another customer is refused. **This is the test that keeps the
  multi-method promise honest**; it must exist before any non-card method is enabled.
- `ConcurrentCheckoutTest` — two `subscribe()` calls for one domain create one
  subscription.
- One end-to-end test against Stripe test mode (`pm_card_visa`,
  `pm_card_threeDSecure2Required`), excluded by default — network calls in the standard
  suite would be a regression given `.claude/rules/testing.md`.

`additional_prices` from `PlanMetadata` carries over verbatim from
`StripeCheckoutGateway`, **including** the `is_array` guard —
`treatPhpDocTypesAsCertain: false` is why that guard exists
(`.claude/rules/static-analysis.md`); do not simplify it.

## Phases

1. **Contract + data model.** `CheckoutIntent`, migration, `StartSubscriptionCheckout`
   returns an intent, both existing gateways conform. No user-visible change; the
   hosted flow still works. Independently shippable.
2. **Inline checkout, cards only.** Wizard step, Element, `CreateInlineSubscription`,
   `SettleCheckout`, return route, `AwaitingPayment` badge, unpaid-tenant cap. Dashboard
   stays cards-only — smallest blast radius, no async settlement in production yet.
3. **Lifecycle: suspension.** `SuspendTenant`/`RestoreTenant`, the tenant-panel access
   gate, `past_due`/`unpaid`/`incomplete_expired`/`deleted` handling, dunning copy,
   30-day prune of suspended-never-paid tenants. Gate: must land before
   iDEAL/Bancontact/SEPA are enabled.
4. **Delete the hosted path** once no in-flight hosted sessions remain, and shrink the
   Stripe metadata to the reservation pointer.

Phases 1–2 are the feature. Phase 4 is the cleanup that makes `TenantRegistrationData`
refactorable again.

**Phase 3 is not really part of this project.** The access gate closes a hole that is
already open today on trials — a tenant whose trial ended and whose card failed keeps
working — so it does not depend on phases 1–2 and would be better as its own branch,
shipped first if anything. It is listed here because provisioning-before-settlement
depends on it: without an access gate, "provision immediately, suspend on failure" is
just "provision immediately".
