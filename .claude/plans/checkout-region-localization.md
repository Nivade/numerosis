# Checkout Region-Based Payment Method Localization

**Status: ❌ Not executed.** `torann/geoip` not installed, no
`ResolveCheckoutRegion` action, no `config/billing/payment_methods.php`. Plan
never left the ground — likely still pending the geoip dependency approval
called out in §1.

## Goal

At checkout, show only available + most-popular payment methods for the
customer's region, instead of every Stripe-Dashboard-enabled method for
everyone. Detected region should set defaults before the customer types
anything (not just react to what they type into the Address Element).

## Current state (researched)

- Checkout uses Stripe's **Payment Element** (`elements.create('payment', ...)`
  in `resources/js/stripe-checkout.js:42-58`), not a manually enumerated
  method list.
- Server creates the SetupIntent with a blanket
  `automatic_payment_methods: { enabled: true }`
  (`app/Services/Billing/Checkout/InlineCheckoutGateway.php:59-62`). A
  deliberate comment there warns against pinning `payment_method_types` at
  the intent level — that stays untouched by this plan.
- No country/region signal exists anywhere pre-payment. `CompanyInfo` step
  and `TenantRegistrationData`/`TenantProvisionData` carry no country field.
  The only country Stripe ever sees is whatever the customer types into the
  Address Element, live, after the SetupIntent already exists — too late to
  influence defaults or ordering.
- No config currently exists for payment-method curation. `config/billing.php`
  covers plans/quotas/provisioning only.
- Cashier v16 / stripe-php v17.6 — current enough for Payment Element,
  Address Element, `paymentMethodOrder`, no version work needed.

## Approach

Curate **client-side ordering only**, on top of Stripe's own eligibility
filtering. Server-side `automatic_payment_methods` stays as-is — Stripe
already decides which methods are actually eligible (currency, amount,
account country); this plan only decides *default country* and *display
order*, both client concerns.

### 1. IP-geolocation dependency (needs separate approval before `composer require`)

Proposed: **`torann/geoip`** with the MaxMind GeoLite2 database driver.

- Local DB lookup per request — no external API call per checkout, no added
  latency, no third-party request leaking checkout traffic.
- Well-maintained, standard choice for Laravel; supports swapping drivers
  later (MaxMind / ip2location / a HTTP API) without touching call sites.
- Needs a scheduled command to refresh the GeoLite2 database periodically
  (MaxMind rotates license keys / update cadence) — add a
  `geoip:update` entry to the scheduler if this driver is chosen.

Flag explicitly for approval: this is a new Composer dependency plus a
binary geo database asset, which the project conventions require sign-off on
before installing.

### 2. Region detection action

New `App\Actions\Billing\Checkout\ResolveCheckoutRegion`:

- Input: the request (or just the IP).
- Resolves ISO country code via `torann/geoip`.
- Falls back to `null`/unknown on lookup failure (private IP, local dev,
  DB miss) — callers must treat unknown as "use default order", not an error.
- No caching needed beyond geoip's own — this runs once per checkout page
  load, not hot path.

### 3. Curated method-order config

New `config/billing/payment_methods.php`, shaped like the existing
`config/billing.php` conventions:

```php
return [
    'default_order' => ['card', 'link'],
    'regions' => [
        'NL' => ['ideal', 'card', 'bancontact', 'sepa_debit', 'link'],
        'BE' => ['bancontact', 'card', 'ideal', 'sepa_debit', 'link'],
        'DE' => ['card', 'sepa_debit', 'giropay', 'link'],
        // ... seeded with Stripe's own documented regional popularity;
        // I'll draft the initial table since there's no existing preference,
        // and it's easy to adjust — a config array, not a code change.
    ],
];
```

Nothing here restricts what Stripe is willing to show — a method absent
from the region's list still appears (Stripe decides eligibility), just
unordered/appended after the curated ones. This avoids the config silently
hiding a legitimately-eligible method nobody remembered to add to the list.

### 4. Wire region into the wizard

`Payment` Livewire step (payment step of the registration wizard):

- On mount, calls `ResolveCheckoutRegion`, resolves the order array from
  config (region hit → curated list, miss → `default_order`).
- Exposes both as `#[Locked]` public properties, same pattern as
  `$pendingDomain` (`.claude/rules/billing-checkout.md` — anything
  client-visible in this wizard needs the same lock discipline).

### 5. Client side

- `resources/views/components/billing/payment-element.blade.php`: pass the
  resolved order + country through as data attributes (or Alpine
  `x-data` params, matching how `clientSecret` already gets there).
- `stripe-checkout.js`: add `paymentMethodOrder: resolvedOrder` to the
  `elements.create('payment', {...})` call.
- `elements.create('address', { mode: 'billing', defaultValues: { address:
  { country: detectedCountry } } })` so the Address Element starts on the
  right country instead of blank/US, rather than only reacting after the
  customer picks one.

### 6. Tests

- Pest feature test on `ResolveCheckoutRegion`: header/IP → country,
  fallback on lookup miss.
- Pest test that `Payment` step exposes the correct order array for a given
  resolved country (hit and miss-fallback cases).
- No Dusk/browser test needed unless actual Element rendering needs
  verification — the client wiring is a thin pass-through, main risk lives
  server-side in the resolution + config lookup.

## Explicitly out of scope

- Restricting (not just ordering) methods per region — would require
  touching the intent-level `payment_method_types`, the exact thing the
  existing code comment warns against.
- Locale/language localization of checkout copy — separate concern, not
  addressed here despite the "localize" wording in the ask.
- Any change to `automatic_payment_methods` server-side behavior.

## Open items for whoever picks this up

- Final regional method-order table (section 3) is a first draft, not a
  business decision already made — expect to revise it.
- Confirm `torann/geoip` + MaxMind is acceptable before running
  `composer require`; the scheduled DB-refresh command needs to land in the
  same change.
