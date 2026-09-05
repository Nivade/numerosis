<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The `tenancy.auth` middleware's reason for existing: a central user who
 * belongs to a tenant is signed in on the *tenant* guard on the way through,
 * rather than bounced to a login screen for an account they already hold.
 *
 * Drives `/account-suspended` because it is the one core route inside the
 * authenticated tenant group; the tenant root is deliberately public.
 */
class TenantAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHENTICATED_TENANT_PATH = '/account-suspended';

    public function test_central_user_reaching_an_authenticated_tenant_route_is_signed_in_on_the_tenant_guard(): void
    {
        $id = 'test-'.uniqid();
        $domain = $this->tenantDomain($id);

        $tenant = $this->createTenantWithDomain($id);

        $centralUser = CentralUser::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $centralUser->tenants()->attach($tenant, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        // The global_id must match the central user's: the promotion goes
        // through User::canAccessTenant(), which looks the membership up by
        // global_id and refuses when it finds none.
        $this->createTenantUser($tenant, [
            'global_id' => $centralUser->global_id,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Auth::guard('web')->login($centralUser);

        $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH)
            ->assertOk();

        $tenant->run(function () use ($centralUser) {
            $this->assertTrue(Auth::guard('tenant')->check());
            $this->assertInstanceOf(TenantUser::class, Auth::guard('tenant')->user());
            $this->assertEquals($centralUser->global_id, Auth::guard('tenant')->user()->global_id);
        });

        // A second request must not re-trigger the promotion.
        $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH)
            ->assertOk();
    }

    public function test_central_user_without_access_to_tenant_is_not_promoted_onto_the_tenant_guard(): void
    {
        $id = 'test-'.uniqid();
        $domain = $this->tenantDomain($id);

        $tenant = $this->createTenantWithDomain($id, 'Other Tenant');

        $centralUser = CentralUser::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Other User',
            'email' => 'other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        // No promotion happens, so the tenant guard is still empty and the
        // middleware throws AuthenticationException, which redirects.
        $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH)
            ->assertRedirect();

        $tenant->run(function (): void {
            $this->assertFalse(Auth::guard('tenant')->check());
        });
    }
}
