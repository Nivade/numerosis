<?php

declare(strict_types=1);

use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
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
use Nvade\Numerosis\Services\Billing\Modules\EloquentModuleCatalog;
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
    | Billable Models
    |--------------------------------------------------------------------------
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
    |--------------------------------------------------------------------------
    | Webhook Path
    |--------------------------------------------------------------------------
    */

    'webhook_path' => env('BILLING_WEBHOOK_PATH', 'billing/webhook'),

    /*
    |--------------------------------------------------------------------------
    | Default Trial Length
    |--------------------------------------------------------------------------
    |
    | Used by PlanOrDefaultTrialResolver when a plan does not declare its own
    | trial_days.
    |
    */

    'trial_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Backs ConfigPaymentPlanRepository, the zero-migration quickstart plan
    | source — bind it under 'implementations' above to use it. The default
    | EloquentPaymentPlanRepository reads from the payment_plans table
    | instead (seeded from these same values by PaymentPlanSeeder).
    |
    */

    'plans' => [
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'short_description' => 'Perfect for small teams getting started.',
            'monthly_id' => env('STRIPE_STARTER_MONTHLY_PLAN', ''),
            'yearly_id' => env('STRIPE_STARTER_YEARLY_PLAN', ''),
            'yearly_incentive' => 'Save 20%',
            'trial_days' => 14,
            'features' => [
                'Up to 5 team members',
                'Basic features',
                'Email support',
                '--Priority support',
                '--Advanced analytics',
                '--Custom integrations',
            ],
            'options' => [
                'max_users' => 5,
                'max_storage_gb' => 10,
                'priority_support' => false,
                'custom_domain' => false,
                'api_access' => false,
            ],
            'archived' => false,
        ],
        [
            'name' => 'Professional',
            'slug' => 'professional',
            'short_description' => 'For growing teams that need more power.',
            'monthly_id' => env('STRIPE_PROFESSIONAL_MONTHLY_PLAN', ''),
            'yearly_id' => env('STRIPE_PROFESSIONAL_YEARLY_PLAN', ''),
            'monthly_incentive' => 'Most Popular',
            'yearly_incentive' => 'Save 20%',
            'trial_days' => 0,
            'features' => [
                'Up to 20 team members',
                'All basic features',
                'Priority email support',
                'Advanced analytics',
                'Custom domain',
                '--Custom integrations',
            ],
            'options' => [
                'max_users' => 20,
                'max_storage_gb' => 50,
                'priority_support' => true,
                'custom_domain' => true,
                'api_access' => true,
            ],
            'archived' => false,
        ],
        [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'short_description' => 'Unlimited power for large organizations.',
            'monthly_id' => env('STRIPE_ENTERPRISE_MONTHLY_PLAN', ''),
            'yearly_id' => env('STRIPE_ENTERPRISE_YEARLY_PLAN', ''),
            'yearly_incentive' => 'Save 25%',
            'trial_days' => 0,
            'features' => [
                'Unlimited team members',
                'All professional features',
                '24/7 priority support',
                'Advanced analytics & reporting',
                'Custom domain',
                'Custom integrations',
                'Dedicated account manager',
                'SLA guarantee',
            ],
            'options' => [
                'max_users' => null, // Unlimited
                'max_storage_gb' => 500,
                'priority_support' => true,
                'custom_domain' => true,
                'api_access' => true,
                'dedicated_support' => true,
                'sla' => true,
            ],
            'archived' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract Bindings
    |--------------------------------------------------------------------------
    |
    | Swap any of these for your own implementation by changing the class
    | here — no provider edit required. PlanPolicy, TrialResolver,
    | BillableResolver and MoneyFormatter also accept a closure override via
    | Billing::resolve*Using(), checked before this binding.
    |
    */

    'implementations' => [
        CheckoutGateway::class => InlineCheckoutGateway::class,
        PaymentPlanRepository::class => EloquentPaymentPlanRepository::class,
        ModuleCatalog::class => EloquentModuleCatalog::class,
        SubscriptionRepository::class => EloquentSubscriptionRepository::class,
        BillableResolver::class => TenantOrUserBillableResolver::class,
        PlanPolicy::class => SeatLimitPlanPolicy::class,
        TrialResolver::class => PlanOrDefaultTrialResolver::class,
        MoneyFormatter::class => CashierMoneyFormatter::class,
        UnpaidTenantQuota::class => DefaultUnpaidTenantQuota::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Unpaid Tenant Cap
    |--------------------------------------------------------------------------
    |
    | Provisioning happens before settlement (trials collect zero money
    | upfront), so this caps how many concurrently-unpaid tenants a single
    | user can own before StartSubscriptionCheckout refuses to start another.
    |
    */

    'unpaid_tenant_cap' => env('BILLING_UNPAID_TENANT_CAP', 2),

    /*
    |--------------------------------------------------------------------------
    | Stripe Customer Sync
    |--------------------------------------------------------------------------
    |
    | Disable if your application syncs tenants to Stripe itself and does not
    | want SyncTenantToStripe firing a second write on every TenantSaved.
    |
    */

    'sync' => [
        'stripe_customer' => true,
    ],

];
