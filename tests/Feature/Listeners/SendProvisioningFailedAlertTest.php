<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners;

use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Listeners\Tenancy\SendProvisioningFailedAlert;
use Nvade\Numerosis\Notifications\Tenancy\ProvisioningFailed;
use Nvade\Numerosis\Tests\TestCase;

class SendProvisioningFailedAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('numerosis.notifications.operator', 'ops@example.com');
        Config::set('numerosis.notifications.throttle_minutes', 15);
    }

    public function test_it_mails_the_configured_operator_with_the_failing_step(): void
    {
        Notification::fake();

        $provision = TenantProvision::factory()->failed()->create([
            'slug' => 'alerted',
            'error' => 'database went away',
        ]);

        $provision->recordStep(MigrateTenantDatabase::class, StepOutcome::Failed, 'database went away', 5);

        new SendProvisioningFailedAlert()->handle(new TenantProvisioningFailed('alerted', null));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            ProvisioningFailed::class,
            fn (ProvisioningFailed $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.com'
                && $notification->slug === 'alerted'
                && $notification->step === MigrateTenantDatabase::class
                && $notification->error === 'database went away',
        );
    }

    /**
     * One bad deploy fails every provision it touches. Two hundred mails
     * describing one cause are worse than one.
     */
    public function test_twenty_failures_in_a_minute_send_one_mail(): void
    {
        Notification::fake();

        foreach (range(1, 20) as $index) {
            new SendProvisioningFailedAlert()->handle(new TenantProvisioningFailed("burst{$index}", null));
        }

        Notification::assertSentTimes(ProvisioningFailed::class, 1);
    }

    public function test_it_sends_nothing_when_no_operator_is_configured(): void
    {
        Notification::fake();

        Config::set('numerosis.notifications.operator');

        new SendProvisioningFailedAlert()->handle(new TenantProvisioningFailed('silent', null));

        Notification::assertNothingSent();
    }
}
