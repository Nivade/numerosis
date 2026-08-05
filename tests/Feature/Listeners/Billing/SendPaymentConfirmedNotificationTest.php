<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
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
}
