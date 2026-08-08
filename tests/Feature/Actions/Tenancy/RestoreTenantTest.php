<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Tests\TestCase;

class RestoreTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owner notification is sent by Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification
     * in response to this event — see SendPaymentConfirmedNotificationTest.
     */
    public function test_it_clears_suspension_and_broadcasts(): void
    {
        Event::fake([PaymentSettled::class]);
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['suspended_at' => now()]);
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        RestoreTenant::run($tenant);

        $tenant->refresh();
        $this->assertFalse($tenant->isSuspended());
        $this->assertNull($tenant->suspended_at);

        Event::assertDispatched(fn (PaymentSettled $e) => $e->tenant->id === $tenant->id && $e->ownerId === $owner->id);
    }

    /**
     * A subscription.updated webhook fires on every renewal, not just
     * recovery — restoring a tenant that was never suspended must not spam
     * the owner with a "payment confirmed" email each time.
     */
    public function test_it_is_a_no_op_for_a_tenant_that_was_never_suspended(): void
    {
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        RestoreTenant::run($tenant);

        Notification::assertNothingSent();
    }
}
