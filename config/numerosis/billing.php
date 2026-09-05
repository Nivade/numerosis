<?php

declare(strict_types=1);

use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\MoneyFormatter;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Services\Billing\Checkout\InlineCheckoutGateway;
use Nvade\Numerosis\Services\Billing\Plans\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Services\Billing\Resolvers\CashierMoneyFormatter;
use Nvade\Numerosis\Services\Billing\Resolvers\DefaultUnpaidTenantQuota;
use Nvade\Numerosis\Services\Billing\Resolvers\PlanOrDefaultTrialResolver;
use Nvade\Numerosis\Services\Billing\Resolvers\SeatLimitPlanPolicy;
use Nvade\Numerosis\Services\Billing\Resolvers\TenantOrUserBillableResolver;
use Nvade\Numerosis\Services\Billing\Subscriptions\EloquentSubscriptionRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Nested under its own key rather than merged flat: this array's 'models'
    | (Cashier customer/subscription model bindings) is a different shape
    | from the top-level 'models' key above (per-model class overrides), and
    | 'implementations' exists in both this section and 'tenancy' below —
    | flattening either would collide.
    |
    */

    'billing' => [

        /*
        |----------------------------------------------------------------------
        | Billable Models
        |----------------------------------------------------------------------
        |
        | The Cashier customer/subscription models this application uses. Swap
        | these to point Cashier at your own subclasses without touching the
        | service provider.
        |
        */

        'models' => [
            'tenant' => Tenant::class,
            'subscription' => Subscription::class,
            'subscription_item' => SubscriptionItem::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Webhook Path
        |----------------------------------------------------------------------
        */

        'webhook_path' => env('BILLING_WEBHOOK_PATH', 'billing/webhook'),

        /*
        |----------------------------------------------------------------------
        | Default Trial Length
        |----------------------------------------------------------------------
        |
        | Used by PlanOrDefaultTrialResolver when a plan does not declare its
        | own trial_days.
        |
        */

        'trial_days' => 14,

        /*
        |----------------------------------------------------------------------
        | Contract Bindings
        |----------------------------------------------------------------------
        |
        | Swap any of these for your own implementation by changing the class
        | here — no provider edit required. PlanPolicy, TrialResolver,
        | BillableResolver and MoneyFormatter also accept a closure override
        | via Billing::resolve*Using(), checked before this binding.
        |
        */

        'implementations' => [
            CheckoutGateway::class => InlineCheckoutGateway::class,
            PaymentPlanRepository::class => EloquentPaymentPlanRepository::class,
            SubscriptionRepository::class => EloquentSubscriptionRepository::class,
            BillableResolver::class => TenantOrUserBillableResolver::class,
            PlanPolicy::class => SeatLimitPlanPolicy::class,
            TrialResolver::class => PlanOrDefaultTrialResolver::class,
            MoneyFormatter::class => CashierMoneyFormatter::class,
            UnpaidTenantQuota::class => DefaultUnpaidTenantQuota::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Unpaid Tenant Cap
        |----------------------------------------------------------------------
        |
        | Provisioning happens before settlement (trials collect zero money
        | upfront), so this caps how many concurrently-unpaid tenants a single
        | user can own before StartSubscriptionCheckout refuses to start
        | another.
        |
        */

        'unpaid_tenant_cap' => env('BILLING_UNPAID_TENANT_CAP', 2),

        /*
        |----------------------------------------------------------------------
        | Stripe Customer Sync
        |----------------------------------------------------------------------
        |
        | Disable if your application syncs tenants to Stripe itself and does
        | not want SyncTenantToStripe firing a second write on every
        | TenantSaved.
        |
        */

        'sync' => [
            'stripe_customer' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Checkout Payment Method Order
        |----------------------------------------------------------------------
        |
        | Display order only — Stripe still decides which methods are
        | actually eligible (currency, amount, account country), this only
        | reorders what it was already going to show. A method absent from a
        | region's list still appears, just after the curated ones; nothing
        | here restricts eligibility, only ResolveCheckoutRegion's country
        | lookup feeds the pick. See .claude/plans/archive/checkout-region-localization.md.
        |
        */

        'payment_methods' => [
            'default_order' => ['card', 'link'],
            'regions' => [
                'NL' => ['ideal', 'card', 'bancontact', 'sepa_debit', 'link'],
                'BE' => ['bancontact', 'card', 'ideal', 'sepa_debit', 'link'],
                'DE' => ['card', 'sepa_debit', 'giropay', 'link'],
                'AT' => ['card', 'sepa_debit', 'eps', 'link'],
                'FR' => ['card', 'sepa_debit', 'link'],
                'ES' => ['card', 'sepa_debit', 'link'],
                'IT' => ['card', 'sepa_debit', 'link'],
                'GB' => ['card', 'link'],
                'US' => ['card', 'link'],
                'PL' => ['card', 'blik', 'link'],
            ],
        ],

    ],

];
