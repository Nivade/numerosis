<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Listeners\Billing\SendPaymentFailedNotification;
use Nvade\Numerosis\Listeners\Billing\SendTenantSuspendedNotification;
use Nvade\Numerosis\Tests\TestCase;

/**
 * All three listeners are auto-discovered, so disabling the feature cannot
 * un-discover them — the assertion here is that each still resolves and
 * runs, but the early return inside handle() stops the notification.
 */
class BillingNotificationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_confirmed_sends_nothing_when_disabled(): void
    {
        FeatureRegistry::forceForTesting([]);
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        (new SendPaymentConfirmedNotification)->handle(new PaymentSettled($tenant, $owner->id, (string) $tenant->getTenantKey()));

        Notification::assertNothingSent();
    }

    public function test_payment_failed_sends_nothing_when_disabled(): void
    {
        FeatureRegistry::forceForTesting([]);
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        (new SendPaymentFailedNotification)->handle(new PaymentFailed($tenant, (string) $tenant->getTenantKey()));

        Notification::assertNothingSent();
    }

    public function test_tenant_suspended_sends_nothing_when_disabled(): void
    {
        FeatureRegistry::forceForTesting([]);
        Notification::fake();
        Tenant::unsetEventDispatcher();

        $owner = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner']);

        (new SendTenantSuspendedNotification)->handle(new TenantSuspended($tenant, (string) $tenant->getTenantKey()));

        Notification::assertNothingSent();
    }
}
