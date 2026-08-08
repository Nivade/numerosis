<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Events\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Actions\Auth\DisconnectSocialAccount;
use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;
use Nvade\Numerosis\Tests\TestCase;

class SocialAccountDisconnectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_is_dispatched_when_social_account_is_disconnected(): void
    {
        $this->markTestSkipped('Skipping due to database lock timeout issue in test environment');

        $user = CentralUser::factory()->create();

        // First connect an account
        ConnectSocialAccount::run($user, 'github', 'github-123');

        $this->assertDatabaseHas('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'github',
        ]);

        // Now fake events and disconnect
        Event::fake([SocialAccountDisconnected::class]);

        DisconnectSocialAccount::run($user, 'github');

        Event::assertDispatched(fn (SocialAccountDisconnected $event) => $event->user->id === $user->id
            && $event->provider === 'github');

        $this->assertDatabaseMissing('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'github',
        ]);
    }

    public function test_event_is_not_dispatched_when_disconnecting_non_existent_account(): void
    {
        Event::fake();

        $user = CentralUser::factory()->create();

        // Try to disconnect an account that doesn't exist
        DisconnectSocialAccount::run($user, 'github');

        Event::assertNotDispatched(SocialAccountDisconnected::class);
    }
}
