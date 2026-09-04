<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `numerosis.auth.guards.central` exists so a host can name the central guard
 * whatever it likes, but `routes/web.php` spelled `auth:web` and three
 * controllers called `Auth::guard('web')` directly. Under a renamed guard the
 * route middleware resolved a guard that held nobody, so
 * `ShowInvitationController` saw every visitor as a guest and looped them
 * through login, and `AcceptInvitationController` read `null` where it had
 * annotated a `CentralUser`.
 *
 * Nothing else in the suite renames it, so nothing else can catch a
 * reintroduced literal.
 */
class RenamedCentralGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = $app->make(Repository::class);

        $config->set('numerosis.auth.guards.central', 'host_central');
        $config->set('auth.guards.host_central', ['driver' => 'session', 'provider' => 'central_users']);
    }

    public function test_settings_still_require_authentication(): void
    {
        $this->get('/settings/profile')->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_reaches_settings(): void
    {
        $user = CentralUser::factory()->create();

        $this->actingAs($user, 'host_central')
            ->get('/settings/profile')
            ->assertOk();
    }

    public function test_the_invitation_landing_page_recognises_the_signed_in_visitor(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => 'renamed-guard-'.uniqid().'@example.com',
        ]);

        $user = CentralUser::factory()->create(['email' => $invitation->email]);

        $url = URL::temporarySignedRoute(
            RouteNames::invitationShow(),
            $invitation->expires_at,
            ['invitation' => $invitation->getRouteKey()],
        );

        $this->actingAs($user, 'host_central')
            ->get($url)
            ->assertOk()
            ->assertSeeText($tenant->name);
    }

    public function test_accepting_an_invitation_works_under_the_renamed_guard(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => 'renamed-accept-'.uniqid().'@example.com',
        ]);

        $user = CentralUser::factory()->create(['email' => $invitation->email]);

        $url = URL::temporarySignedRoute(
            RouteNames::invitationShow(),
            $invitation->expires_at,
            ['invitation' => $invitation->getRouteKey()],
        );

        $this->actingAs($user, 'host_central')->post($url)->assertRedirect();

        $this->assertTrue($user->tenants()->where('tenants.id', $tenant->getKey())->exists());
        $this->assertNotNull($invitation->fresh()?->accepted_at);
    }
}
