<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Tests\TestCase;

class SyncTenantToStripeTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_dispatched_when_tenant_is_saved(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
        ]);

        $tenant->update(['name' => 'Updated Tenant']);

        SyncTenantToStripe::assertPushed(fn ($action, $params) => $params[0]->id === $tenant->id);
    }

    public function test_job_is_queued_on_stripe_queue(): void
    {
        Queue::fake();

        Tenant::factory()->create();

        SyncTenantToStripe::assertPushedOn('stripe');
    }

    public function test_job_does_not_sync_tenant_without_stripe_id(): void
    {
        $tenant = Tenant::factory()->create([
            'stripe_id' => null,
        ]);

        $this->expectNotToPerformAssertions();

        // Should not throw exception and should return early
        SyncTenantToStripe::run($tenant);
    }
}
