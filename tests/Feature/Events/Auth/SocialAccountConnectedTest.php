<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Events\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Events\Auth\SocialAccountConnected;
use Nvade\Numerosis\Tests\TestCase;

class SocialAccountConnectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_is_dispatched_when_social_account_is_connected(): void
    {
        Event::fake();

        $user = CentralUser::factory()->create();

        ConnectSocialAccount::run($user, 'github', 'github-123');

        Event::assertDispatched(SocialAccountConnected::class, function ($event) use ($user) {
            return $event->user->id === $user->id
                && $event->socialLogin->provider === 'github'
                && $event->socialLogin->provider_id === 'github-123';
        });
    }

    public function test_event_is_not_dispatched_when_reconnecting_existing_account(): void
    {
        Event::fake();

        $user = CentralUser::factory()->create();

        // First connection
        ConnectSocialAccount::run($user, 'github', 'github-123');

        Event::assertDispatched(SocialAccountConnected::class);

        // Clear the events
        Event::fake();

        // Second connection with same provider
        ConnectSocialAccount::run($user, 'github', 'github-123');

        Event::assertNotDispatched(SocialAccountConnected::class);
    }
}
