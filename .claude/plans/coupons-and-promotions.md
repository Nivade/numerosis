# Coupons, promotion codes and discounts

**Status: not executed. Written 2026-09-16.** Wave 4 of
`saas-readiness-roadmap.md`.

## The gap

No reference to a coupon, a promotion code or a discount exists anywhere in
the tree. Checkout is built — inline, redirect and a local-development gateway
(`src/Services/Billing/InlineCheckoutGateway.php`,
`src/Actions/Billing/Checkout/*`) — and none of it can apply a discount.

That blocks launch pricing, annual-versus-monthly incentives, win-back offers
on cancellation, and every partner deal. It is the cheapest revenue feature in
the roadmap because Stripe owns the hard part: coupons and promotion codes are
Stripe objects, and Cashier already carries them through checkout and
subscription creation.

## Scope

**Stripe owns the definitions.** This package does not build a coupon editor,
does not store discount rules, and does not compute discounted amounts itself.
Coupons and promotion codes are created in the Stripe dashboard; the package
applies them, validates them, displays them and records which tenant used
what.

That boundary is the whole design. Re-implementing discount arithmetic
locally guarantees a number on a screen that disagrees with the invoice.

## Phases

### 1. Promotion code on the checkout path

A code field on the checkout step (`Livewire\Billing\Checkout` and the
registration wizard's `Steps\Payment`), validated against Stripe before the
subscription is created, with the discounted total shown before confirmation.

The wizard bypasses `StartCheckoutRequest`, so validation has to be applied in
both paths or the wizard silently ignores codes. That asymmetry already exists
and has already caused one class of bug.

### 2. Validation and its failure modes

A code can be unknown, expired, at its redemption limit, restricted to a
customer or product, or first-time-purchase-only. Each needs a distinct
message; "invalid code" for all five is the support-ticket generator. Validate
through Stripe, cache nothing — redemption limits move under you.

### 3. Application at subscription creation

Carry the code into the Cashier subscription builder rather than applying it
afterwards. The `subscribable_id` is the owner's primary key, and the discount
attaches to the subscription being created for that billable.

### 4. Record locally

A central `applied_promotions` row: tenant, subscription, code, Stripe coupon
id, applied at, and the discount as Stripe reported it. Not the source of
truth — the audit trail, and the thing the staff panel and revenue reporting
read without calling Stripe per row.

### 5. Display where it matters

Active discount on the billing screen with its expiry, on the plan cards while
one is applied, and on the staff panel's tenant detail. A discount that
silently expires and raises the next invoice is a chargeback.

### 6. Cancellation offer

The one place a discount earns its keep automatically: on the close-tenant
flow from `tenant-close-and-recovery.md`, offer a retention code before
confirming. Behind a config flag, off by default, because a host without a
retention coupon configured must not see a broken offer.

## Tests

- A valid code reduces the total shown before confirmation and the
  subscription is created with the discount attached.
- Each failure mode returns its own message: unknown, expired, redemption
  limit reached, product-restricted, customer-restricted.
- The registration wizard applies codes identically to the direct checkout
  path — the same assertion run through both entry points.
- A code applied during a trial does not consume its redemption until the
  first charge, matching Stripe's behaviour rather than the package's guess.
- `applied_promotions` records exactly one row per application and is not
  written when validation fails.
- Discount appears on the billing screen with its expiry, and disappears when
  Stripe reports it ended.
- Plan swap preserves an active discount where Stripe does, and the test
  asserts against Stripe's fixture rather than an assumption.

## Risks

- **Local arithmetic drift.** Any place the package computes a discounted
  number itself will eventually disagree with the invoice. Display what Stripe
  returns.
- **Percentage-off with metered usage.** If `usage-metering.md` has landed,
  coupons interact with usage lines in ways worth testing together rather than
  assuming.
- **Codes in URLs.** Accepting `?promo=` from a marketing link is the obvious
  next request and is an open redirect for pricing: validate server-side and
  never trust the query string beyond pre-filling the field.
