<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\SubscriptionCancelled;
use Nvade\Numerosis\Events\Billing\SubscriptionPlanChanged;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Notifications\Billing\PaymentFailed;
use Nvade\Numerosis\Notifications\Billing\TenantSuspended;
use Nvade\Numerosis\Tests\Concerns\DisablesWebhookSignature;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers custom-checkout.md's SuspendedTenantTest spec: each of
 * invoice.payment_failed's downstream past_due/unpaid, incomplete_expired,
 * and customer.subscription.deleted suspends the tenant; recovering the
 * subscription restores it. See EnsureTenantSubscriptionActiveTest for the
 * panel-refuses-access half and the trial-expiry case.
 */
class WebhookControllerLifecycleTest extends TestCase
{
    use DisablesWebhookSignature;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant::save() is no longer event-suppressed (see
        // tenantWithStripeCustomer() below — dev-master's DatabaseTenancyBootstrapper
        // now eagerly checks the tenant database exists, so CreateDatabase
        // must actually run), which means the real `TenantSaved` ->
        // SyncTenantToStripeOnSave -> SyncTenantToStripe chain fires for any
        // tenant with a stripe_id. `Bus::fake()` doesn't reach it — laravel-actions
        // dispatches a `JobDecorator` wrapper, not `SyncTenantToStripe`
        // itself, so a class-keyed queue fake never matches. Use the
        // package's own fake instead, which mocks `handle()` directly.
        // `configureJob()` also has to be stubbed — `JobDecorator` calls it
        // unconditionally on every dispatch, mock or not.
        SyncTenantToStripe::mock()->shouldReceive('handle', 'configureJob')->andReturnNull();
    }

    /**
     * @return array{tenant: BaseTenant, owner: BaseCentralUser}
     */
    private function tenantWithStripeCustomer(string $customerId): array
    {
        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => $customerId]);
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        return ['tenant' => $tenant, 'owner' => $owner];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionUpdatedPayload(string $subscriptionId, string $customerId, string $status, string $priceId = 'price_test'): array
    {
        return [
            'id' => 'evt_'.$subscriptionId,
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'customer' => $customerId,
                    'status' => $status,
                    'cancel_at_period_end' => false,
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_'.$subscriptionId,
                                'price' => ['id' => $priceId, 'product' => 'prod_test'],
                                'quantity' => 1,
                            ],
                        ],
                    ],
                    'metadata' => [],
                ],
            ],
        ];
    }

    #[DataProvider('suspendingStatuses')]
    public function test_subscription_updated_suspends_the_tenant(string $status): void
    {
        Notification::fake();

        $customerId = 'cus_'.$status;
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithStripeCustomer($customerId);

        Subscription::factory()->create([
            'stripe_id' => 'sub_'.$status,
            'stripe_status' => 'active',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $this->subscriptionUpdatedPayload('sub_'.$status, $customerId, $status))
            ->assertOk();

        $this->assertTrue($tenant->refresh()->isSuspended());

        Notification::assertSentTo($owner, TenantSuspended::class);
    }

    /**
     * @return list<array{string}>
     */
    public static function suspendingStatuses(): array
    {
        return [
            ['past_due'],
            ['unpaid'],
            ['incomplete_expired'],
        ];
    }

    public function test_subscription_deleted_suspends_the_tenant(): void
    {
        Notification::fake();
        Event::fake([SubscriptionCancelled::class]);

        $customerId = 'cus_deleted';
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithStripeCustomer($customerId);

        Subscription::factory()->create([
            'stripe_id' => 'sub_deleted',
            'stripe_status' => 'active',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $periodEnd = now()->addDays(3)->getTimestamp();

        $payload = [
            'id' => 'evt_sub_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_deleted',
                    'customer' => $customerId,
                    'current_period_end' => $periodEnd,
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)->assertOk();

        $this->assertTrue($tenant->refresh()->isSuspended());
        Notification::assertSentTo($owner, TenantSuspended::class);

        Event::assertDispatched(fn (SubscriptionCancelled $e): bool => $e->tenant->id === $tenant->id
            && $e->tenantId === $tenant->id
            && $e->gracePeriodEndsAt?->getTimestamp() === $periodEnd);
    }

    /**
     * Stripe moved `current_period_end` off the subscription onto its items in
     * API version `2025-03-31.basil`, and Cashier v16 pins
     * `2026-08-26.dahlia`, so this is the shape a live account actually sends.
     */
    public function test_subscription_deleted_reads_the_grace_period_off_the_subscription_item(): void
    {
        Notification::fake();
        Event::fake([SubscriptionCancelled::class]);

        $customerId = 'cus_item_period';
        ['tenant' => $tenant] = $this->tenantWithStripeCustomer($customerId);

        $periodEnd = now()->addDays(5)->getTimestamp();

        $payload = [
            'id' => 'evt_item_period',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_item_period',
                    'customer' => $customerId,
                    'items' => ['data' => [['current_period_end' => $periodEnd]]],
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)->assertOk();

        Event::assertDispatched(fn (SubscriptionCancelled $e): bool => $e->tenant->id === $tenant->id
            && $e->gracePeriodEndsAt?->getTimestamp() === $periodEnd);
    }

    /**
     * `customer` is a string on an unexpanded payload and an object on an
     * expanded one. The array-shape docblock declaring `customer?: string` is
     * a claim about untrusted input, not a check, so an expanded payload used
     * to reach a `?string` parameter as an array and 500 — a webhook Stripe
     * then retries indefinitely.
     *
     * Flattened at the front door, so the expanded form resolves the same
     * tenant the id form does rather than merely surviving.
     */
    public function test_an_expanded_customer_object_still_resolves_the_tenant(): void
    {
        Notification::fake();
        Event::fake([SubscriptionCancelled::class]);

        ['tenant' => $tenant] = $this->tenantWithStripeCustomer('cus_expanded');

        $payload = [
            'id' => 'evt_expanded_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_expanded',
                    'customer' => ['id' => 'cus_expanded', 'object' => 'customer'],
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)->assertOk();

        $this->assertTrue($tenant->refresh()->isSuspended());
        Event::assertDispatched(fn (SubscriptionCancelled $e): bool => $e->tenant->id === $tenant->id);
    }

    public function test_an_expanded_customer_object_does_not_500_a_dunning_notice(): void
    {
        Notification::fake();

        ['owner' => $owner] = $this->tenantWithStripeCustomer('cus_expanded_failed');

        $payload = [
            'id' => 'evt_expanded_failed',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_expanded',
                    'customer' => ['id' => 'cus_expanded_failed', 'object' => 'customer'],
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)->assertOk();

        Notification::assertSentTo($owner, PaymentFailed::class);
    }

    public function test_subscription_updated_to_active_restores_a_suspended_tenant(): void
    {
        $customerId = 'cus_recovered';
        ['tenant' => $tenant] = $this->tenantWithStripeCustomer($customerId);
        $tenant->update(['suspended_at' => now()]);

        Subscription::factory()->create([
            'stripe_id' => 'sub_recovered',
            'stripe_status' => 'past_due',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $this->subscriptionUpdatedPayload('sub_recovered', $customerId, 'active'))
            ->assertOk();

        $this->assertFalse($tenant->refresh()->isSuspended());
    }

    /**
     * The one dispatch site for a plan change, wherever the change came from:
     * a host calling `SwapSubscriptionPlan`, or an edit in the Stripe
     * dashboard, both surface as this webhook.
     */
    public function test_subscription_updated_to_a_new_price_dispatches_a_plan_change(): void
    {
        Event::fake([SubscriptionPlanChanged::class]);

        $customerId = 'cus_swapped';
        ['tenant' => $tenant] = $this->tenantWithStripeCustomer($customerId);

        Subscription::factory()->create([
            'stripe_id' => 'sub_swapped',
            'stripe_status' => 'active',
            'stripe_price' => 'price_old',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->subscriptionUpdatedPayload('sub_swapped', $customerId, 'active', 'price_new'),
        )->assertOk();

        Event::assertDispatchedTimes(SubscriptionPlanChanged::class, 1);
        Event::assertDispatched(fn (SubscriptionPlanChanged $e): bool => $e->tenant->id === $tenant->id
            && $e->tenantId === $tenant->id
            && $e->fromPriceId === 'price_old'
            && $e->toPriceId === 'price_new');
    }

    /**
     * Stripe raises `customer.subscription.updated` for a status change, a
     * period rollover, a cancellation flag — most of them leave the price
     * alone, and none of those is a plan change.
     */
    public function test_subscription_updated_without_a_price_change_dispatches_nothing(): void
    {
        Event::fake([SubscriptionPlanChanged::class]);

        $customerId = 'cus_unchanged';
        ['tenant' => $tenant] = $this->tenantWithStripeCustomer($customerId);

        Subscription::factory()->create([
            'stripe_id' => 'sub_unchanged',
            'stripe_status' => 'past_due',
            'stripe_price' => 'price_same',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->subscriptionUpdatedPayload('sub_unchanged', $customerId, 'active', 'price_same'),
        )->assertOk();

        Event::assertNotDispatched(SubscriptionPlanChanged::class);
    }

    /**
     * Reproduces the 2026-08-01 incident: CreateInlineSubscription writes the
     * local `subscriptions` row synchronously and unlocked (Cashier's plain
     * SubscriptionBuilder::create(), not updateOrCreate), while Stripe's
     * customer.subscription.created webhook can arrive and reach Cashier's
     * own updateOrCreate() before that write commits. Cashier's own
     * "already exists?" check
     * (`$user->subscriptions->contains('stripe_id', ...)`) reads a
     * collection loaded fresh in each concurrent request, so both requests
     * see no existing row and both attempt an insert — the loser hits
     * `subscriptions_stripe_id_unique`. Forced here by pre-inserting a row
     * with the same stripe_id under a different subscribable, which is
     * invisible to the relation-scoped `contains()` check but still collides
     * on the DB's global unique index — the same shape of failure the
     * unlocked write produces. The fix must swallow the duplicate as
     * "already synced" rather than 500.
     */
    public function test_subscription_created_is_idempotent_against_a_racing_local_insert(): void
    {
        $customerId = 'cus_racing';
        ['tenant' => $tenant] = $this->tenantWithStripeCustomer($customerId);

        Tenant::unsetEventDispatcher();
        $otherTenant = Tenant::factory()->create();

        Subscription::factory()->create([
            'stripe_id' => 'sub_racing',
            'stripe_status' => 'trialing',
            'subscribable_id' => $otherTenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        $payload = [
            'id' => 'evt_sub_racing',
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_racing',
                    'customer' => $customerId,
                    'status' => 'trialing',
                    'metadata' => [],
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_racing',
                                'price' => ['id' => 'price_test', 'product' => 'prod_test'],
                                'quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)
            ->assertOk();

        $this->assertSame(1, Subscription::where('stripe_id', 'sub_racing')->count());
        $this->assertFalse($tenant->refresh()->subscriptions()->where('stripe_id', 'sub_racing')->exists());
    }

    /**
     * The settling delivery is the one that clears `settled_at`, so a
     * redelivery updates no rows and announces nothing.
     */
    public function test_invoice_payment_succeeded_dispatches_payment_settled_once(): void
    {
        Event::fake([PaymentSettled::class]);

        $customerId = 'cus_settling';
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithStripeCustomer($customerId);

        TenantProvision::factory()->create([
            'stripe_subscription_id' => 'sub_settling',
            'settled_at' => null,
        ]);

        $payload = [
            'id' => 'evt_invoice_settled',
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'id' => 'in_settled',
                    'customer' => $customerId,
                    'subscription' => 'sub_settling',
                ],
            ],
        ];

        $path = Config::string('numerosis.billing.webhook_path', 'billing/webhook');

        $this->postJson($path, $payload)->assertOk();

        Event::assertDispatchedTimes(PaymentSettled::class, 1);
        Event::assertDispatched(fn (PaymentSettled $e): bool => $e->tenant->id === $tenant->id
            && $e->tenantId === $tenant->id
            && $e->ownerId === $owner->id);

        $this->postJson($path, $payload)->assertOk();

        Event::assertDispatchedTimes(PaymentSettled::class, 1);
    }

    public function test_invoice_payment_failed_notifies_without_suspending(): void
    {
        Notification::fake();

        $customerId = 'cus_dunning';
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithStripeCustomer($customerId);

        $payload = [
            'id' => 'evt_invoice_failed',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_failed',
                    'customer' => $customerId,
                ],
            ],
        ];

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $payload)->assertOk();

        $this->assertFalse($tenant->refresh()->isSuspended());
        Notification::assertSentTo($owner, PaymentFailed::class);
    }
}
