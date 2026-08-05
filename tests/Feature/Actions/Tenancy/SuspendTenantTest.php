<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Notifications\Billing\TenantSuspended;
use Nvade\Numerosis\Tests\TestCase;

class SuspendTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_the_tenant_suspended_and_notifies_the_owner(): void
    {
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        SuspendTenant::run($tenant);

        $tenant->refresh();
        $this->assertTrue($tenant->isSuspended());
        $this->assertNotNull($tenant->suspended_at);

        Notification::assertSentTo($owner, TenantSuspended::class);
    }

    /**
     * A webhook retry, or two events landing for the same subscription,
     * must not clobber the original suspended_at or re-notify the owner.
     */
    public function test_it_is_idempotent(): void
    {
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        SuspendTenant::run($tenant);
        $tenant->refresh();
        $firstSuspendedAt = $tenant->suspended_at;
        $this->assertNotNull($firstSuspendedAt);

        $this->travel(1)->hours();
        SuspendTenant::run($tenant);

        $tenant->refresh();
        $secondSuspendedAt = $tenant->suspended_at;
        $this->assertNotNull($secondSuspendedAt);
        $this->assertTrue($firstSuspendedAt->equalTo($secondSuspendedAt));

        Notification::assertSentToTimes($owner, TenantSuspended::class, 1);
    }

    /**
     * Suspension is not deletion — the tenant row, and everything it owns,
     * survives.
     */
    public function test_data_survives_suspension(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create();

        SuspendTenant::run($tenant);

        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
