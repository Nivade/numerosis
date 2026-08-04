<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Auth;

use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\SocialiteLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class ConnectSocialAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_social_login_for_a_user(): void
    {
        $user = CentralUser::factory()->create();

        $socialLogin = ConnectSocialAccount::run($user, 'github', 'github-123');

        $this->assertInstanceOf(SocialiteLogin::class, $socialLogin);
        $this->assertEquals($user->id, $socialLogin->user_id);
        $this->assertEquals('github', $socialLogin->provider);
        $this->assertEquals('github-123', $socialLogin->provider_id);

        // Explicit 'central' connection: SocialiteLogin uses CentralConnection
        // and commits immediately, but RefreshDatabase's transaction on the
        // default connection took its snapshot before that commit — the
        // default connection can't see it mid-test without naming the
        // connection here.
        $this->assertDatabaseHas('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => 'github-123',
        ], 'central');
    }

    public function test_it_does_not_create_duplicate_social_logins(): void
    {
        $user = CentralUser::factory()->create();

        // First connection
        ConnectSocialAccount::run($user, 'github', 'github-123');

        // Second connection with same provider
        ConnectSocialAccount::run($user, 'github', 'github-123');

        // Should only have one record
        $this->assertCount(1, SocialiteLogin::where('user_id', $user->id)->get());
    }

    public function test_it_allows_multiple_providers_for_same_user(): void
    {
        $user = CentralUser::factory()->create();

        ConnectSocialAccount::run($user, 'github', 'github-123');
        ConnectSocialAccount::run($user, 'google', 'google-456');

        $this->assertCount(2, SocialiteLogin::where('user_id', $user->id)->get());

        $this->assertDatabaseHas('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'github',
        ], 'central');

        $this->assertDatabaseHas('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'google',
        ], 'central');
    }

    public function test_it_returns_existing_social_login_if_already_connected(): void
    {
        $user = CentralUser::factory()->create();

        $firstConnection = ConnectSocialAccount::run($user, 'github', 'github-123');
        $secondConnection = ConnectSocialAccount::run($user, 'github', 'github-123');

        $this->assertEquals($firstConnection->id, $secondConnection->id);
    }
}
