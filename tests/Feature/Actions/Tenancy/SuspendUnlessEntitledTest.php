<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Actions\Tenancy\SuspendUnlessEntitled;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Extracted from `WebhookController::suspendUnlessStillEntitled()`, which was
 * reachable only through a Stripe webhook POST. The rule it encodes —
 * enumerate what still grants access, never what just ended — is what stops a
 * tenant holding several subscriptions being locked out of a workspace it is
 * still paying for.
 */
class SuspendUnlessEntitledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_suspends_when_nothing_valid_remains(): void
    {
        $tenant = Tenant::factory()->create();

        SuspendUnlessEntitled::run($tenant);

        $this->assertTrue($tenant->refresh()->isSuspended());
    }

    public function test_it_leaves_a_tenant_alone_while_one_subscription_is_still_valid(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->create([
            'stripe_id' => 'sub_cancelled',
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDay(),
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        Subscription::factory()->create([
            'stripe_id' => 'sub_still_paying',
            'stripe_status' => 'active',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        SuspendUnlessEntitled::run($tenant);

        $this->assertFalse($tenant->refresh()->isSuspended());
    }

    public function test_it_suspends_once_every_subscription_has_ended(): void
    {
        $tenant = Tenant::factory()->create();

        Subscription::factory()->create([
            'stripe_id' => 'sub_ended',
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDay(),
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
        ]);

        SuspendUnlessEntitled::run($tenant);

        $this->assertTrue($tenant->refresh()->isSuspended());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        SyncTenantToStripe::mock()->shouldReceive('handle', 'configureJob')->andReturnNull();
    }
}
