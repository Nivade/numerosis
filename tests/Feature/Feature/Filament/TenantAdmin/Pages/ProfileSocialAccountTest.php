<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Feature\Filament\TenantAdmin\Pages;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\ConnectSocialAccount;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages\SocialAccounts;
use Nvade\Numerosis\Tests\TestCase;

class ProfileSocialAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_disconnect_social_account_without_error(): void
    {
        // Create tenant, user, and connect social account
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Connect a social account
        ConnectSocialAccount::run($user, 'google', 'google-123');

        $this->assertDatabaseHas('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'google',
        ], 'central');

        // Test disconnection in tenant context
        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            // Also authenticate as web guard (central) since the component uses it
            $this->actingAs($user, 'web');

            Livewire::test(SocialAccounts::class)
                ->callAction('disconnectSocialAccount', arguments: ['provider' => 'google'])
                ->assertNotified();
        });

        // Verify disconnection happened
        $this->assertDatabaseMissing('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'google',
        ]);
    }

    public function test_social_accounts_section_renders_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Connect a social account
        ConnectSocialAccount::run($user, 'discord', 'discord-456');

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);
            $this->actingAs($user, 'web');

            // Just verify the component can render and has the action
            $component = Livewire::test(SocialAccounts::class);

            // Verify the page has the action for disconnecting
            $component->assertActionExists('disconnectSocialAccount');
        });
    }
}
