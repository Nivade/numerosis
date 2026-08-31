<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\TenantAdmin\Pages;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Profile\Pages\General;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Profile\Pages\Security;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Profile\Pages\SocialAccounts;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenancyConfigKeys::set('central_domains', ['localhost']);
    }

    public function test_can_render_profile_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            $component = Livewire::test(General::class, ['record' => $user]);
            $component->assertStatus(200)
                // Check sidebar items are visible
                ->assertSee('General')
                ->assertSee('Security')
                ->assertSee('Social Accounts')
                ->assertSee('Delete Account')
                // Check default (general) section content is visible
                ->assertSee('Profile Information');
        });
    }

    /**
     * The profile cluster's own navigation, not the removed sidebar plugin —
     * this asserts the links render, which survives that plugin's removal.
     */
    public function test_can_see_all_cluster_navigation_items(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            $component = Livewire::test(General::class, ['record' => $user]);
            $component->assertStatus(200)
                ->assertSee('General')
                ->assertSee('Security')
                ->assertSee('Social Accounts')
                ->assertSee('Delete Account');
        });
    }

    public function test_can_update_profile_information(): void
    {
        $tenant = Tenant::factory()->create([
            'id' => 'test-tenant-'.uniqid(),
        ]);
        $user = CentralUser::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(General::class, ['record' => $user])
                ->set('data.name', 'New Name')
                ->set('data.email', 'new@example.com')
                ->call('save')
                ->assertNotified();

            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'name' => 'New Name',
                'email' => 'new@example.com',
            ], 'central');
        });
    }

    public function test_can_update_password(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(Security::class, ['record' => $user])
                ->set('data.current_password', 'old-password')
                ->set('data.password', 'new-password')
                ->set('data.password_confirmation', 'new-password')
                ->call('save')
                ->assertNotified();

            $user->refresh();
            $this->assertTrue(Hash::check('new-password', $user->password));
        });
    }

    public function test_password_requires_current_password(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(Security::class, ['record' => $user])
                ->set('data.current_password', 'wrong-password')
                ->set('data.password', 'new-password')
                ->set('data.password_confirmation', 'new-password')
                ->call('save')
                ->assertHasFormErrors(['current_password']);
        });
    }

    public function test_password_requires_confirmation(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(Security::class, ['record' => $user])
                ->set('data.current_password', 'old-password')
                ->set('data.password', 'new-password')
                ->set('data.password_confirmation', 'different-password')
                ->call('save')
                ->assertHasFormErrors(['password']);
        });
    }

    public function test_shows_email_verification_status_when_unverified(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create([
            'email_verified_at' => null,
        ]);
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(General::class, ['record' => $user])
                ->assertStatus(200)
                ->assertSee('Your email address is unverified');
        });
    }

    public function test_can_disconnect_social_account(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Connect a social account first
        $user->socialiteLogins()->create([
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        // Authenticate with both guards (required for multi-tenancy)
        Auth::guard('web')->login($user);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(SocialAccounts::class, ['record' => $user])
                ->callAction('disconnectSocialAccount', arguments: ['provider' => 'google'])
                ->assertNotified();
        });

        // Verify the social account was disconnected (check in central database)
        $this->assertDatabaseMissing('socialite_logins', [
            'user_id' => $user->id,
            'provider' => 'google',
        ]);
    }
}
