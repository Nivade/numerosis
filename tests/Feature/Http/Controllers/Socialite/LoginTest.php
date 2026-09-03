<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Socialite;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\Invitation;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Nvade\Numerosis\Contracts\Auth\SocialAccountRepository;
use Nvade\Numerosis\Support\Routes\RouteNames;
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
            ->assertRedirect(tenant_route((string) $tenant->id, RouteNames::home()));
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
            ->assertRedirect(tenant_route((string) $tenant->id, RouteNames::home()));

        $tenantUserExists = $tenant->run(
            fn (): bool => TenantUser::where('global_id', $centralUser->global_id)->exists()
        );

        $this->assertTrue($tenantUserExists);
    }

    /**
     * A consumer rebinds SocialAccountRepository to change how OAuth
     * identities are stored/looked up (a different table shape, an
     * external identity provider) without forking this controller. Proves
     * the binding is real: an existing-user lookup satisfied entirely by
     * the override must never fall through to `firstOrCreate` a new user.
     */
    public function test_a_consumer_can_override_how_social_accounts_are_looked_up(): void
    {
        $existingUser = CentralUser::factory()->create(['email' => 'existing@example.com']);

        $spy = new readonly class($existingUser) implements SocialAccountRepository
        {
            public function __construct(private CentralUser $user) {}

            public function findUserByProviderAndId(string $provider, string $providerId): ?CentralUser
            {
                return $provider === 'google' && $providerId === 'overridden-id' ? $this->user : null;
            }
        };

        $this->app->instance(SocialAccountRepository::class, $spy);

        $socialiteUser = (new SocialiteUser)->map([
            'id' => 'overridden-id',
            'email' => 'someone-else@example.com',
            'name' => 'Someone Else',
        ]);

        Socialite::fake('google', $socialiteUser);

        $this->get(route('oauth.callback', ['driver' => 'google']));

        $this->assertSame(1, CentralUser::count(), 'A new user was created despite the override resolving an existing one.');
    }
}
