<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Socialite;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Nvade\Numerosis\Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_provider_renders_a_404_instead_of_a_500(): void
    {
        $response = $this->get(route('oauth.callback', ['driver' => 'not-a-real-provider']));

        $response->assertNotFound();
    }

    public function test_social_login_started_from_a_tenant_returns_to_that_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $centralUser = CentralUser::factory()->create(['email' => 'social@example.com']);

        $socialiteUser = (new SocialiteUser)->map([
            'id' => 'provider-id-123',
            'email' => $centralUser->email,
            'name' => $centralUser->name,
        ]);

        Socialite::fake('google', $socialiteUser);

        $this->withSession(['socialite_context' => ['tenant' => $tenant->id]])
            ->get(route('oauth.callback', ['driver' => 'google']))
            ->assertRedirect(tenant_route((string) $tenant->id, 'filament.tenantAdmin.pages.dashboard', ['tenant' => $tenant]));
    }

    public function test_accepting_an_invitation_via_social_login_provisions_a_working_tenant_account(): void
    {
        $tenant = Tenant::factory()->create();

        $centralUser = CentralUser::factory()->create(['email' => 'invited@example.com']);

        /** @var Invitation $invitation */
        $invitation = $tenant->run(fn (): Invitation => Invitation::factory()->create([
            'email' => $centralUser->email,
            'role' => 'member',
            'expires_at' => now()->addDays(1),
            'accepted_at' => null,
        ]));

        $socialiteUser = (new SocialiteUser)->map([
            'id' => 'provider-id-456',
            'email' => $centralUser->email,
            'name' => $centralUser->name,
        ]);

        Socialite::fake('google', $socialiteUser);

        $this->withSession(['socialite_context' => [
            'intent' => 'accept_invitation',
            'tenant' => $tenant->id,
            'invitation' => $invitation->id,
            'token' => $invitation->token,
        ]])
            ->get(route('oauth.callback', ['driver' => 'google']))
            ->assertRedirect(tenant_route((string) $tenant->id, 'filament.tenantAdmin.pages.dashboard', ['tenant' => $tenant]));

        $tenantUserExists = $tenant->run(
            fn (): bool => TenantUser::where('global_id', $centralUser->global_id)->exists()
        );

        $this->assertTrue($tenantUserExists);
    }
}
