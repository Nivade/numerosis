<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as NotificationBase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Models\Central\Tenant as PackageTenant;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;
use Nvade\Numerosis\Tests\TestCase;

class SendPaymentConfirmedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_the_tenant_owner(): void
    {
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        (new SendPaymentConfirmedNotification)->handle(new PaymentSettled($tenant, $owner->id));

        Notification::assertSentTo($owner, PaymentConfirmed::class);
    }

    /**
     * A consumer rebinds NotifiesTenantOwner to change who counts as "the
     * tenant's owner" (e.g. multiple notified admins) without forking this
     * listener. Proves the binding is real, not decorative: the default
     * `$tenant->owner()?->notify()` path must not fire once overridden.
     */
    public function test_a_consumer_can_override_how_the_tenant_owner_is_notified(): void
    {
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        $spy = new class implements NotifiesTenantOwner
        {
            public bool $called = false;

            public function notify(PackageTenant $tenant, NotificationBase $notification): void
            {
                $this->called = true;
            }
        };

        app()->instance(NotifiesTenantOwner::class, $spy);

        (new SendPaymentConfirmedNotification)->handle(new PaymentSettled($tenant, $owner->id));

        $this->assertTrue($spy->called);
        Notification::assertNothingSent();
    }
}
