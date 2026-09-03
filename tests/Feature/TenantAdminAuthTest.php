<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
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

        // 1. Create a tenant. forceCreate, as production does: `id` is not
        // fillable, so Tenant::create() drops it and UUIDGenerator assigns a
        // uuid instead — the subdomain then no longer matches the tenant and
        // identification 404s.
        $tenant = Tenant::forceCreate(['id' => $id, 'name' => 'Test Tenant']);
        $tenant->domains()->create([
            'id' => $id,
            'domain' => $domain,
        ]);

        // 2. Create a central user
        $centralUser = CentralUser::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        // 3. Associate central user with tenant (Membership)
        $centralUser->tenants()->attach($tenant, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        // 4. Create the corresponding tenant user in the tenant's database.
        // The global_id must match the central user's: the promotion goes
        // through User::canAccessTenant(), which looks the membership up by
        // global_id and refuses when it finds none.
        $tenant->run(function () use ($centralUser) {
            TenantUser::create([
                'global_id' => $centralUser->global_id,
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        });

        // 5. Authenticate central user on 'web' guard
        Auth::guard('web')->login($centralUser);

        // 6. Reach an authenticated tenant route (this triggers the SSO in
        // the tenancy.auth middleware)
        $response = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH);

        // 7. Assertions
        $response->assertOk();

        // Verify that the user is now also logged in via the 'tenant' guard as a Tenant\User
        $tenant->run(function () use ($centralUser) {
            $this->assertTrue(Auth::guard('tenant')->check());
            $this->assertInstanceOf(TenantUser::class, Auth::guard('tenant')->user());
            $this->assertEquals($centralUser->global_id, Auth::guard('tenant')->user()->global_id);
        });

        // 8. Second request should NOT re-trigger login (we can check this by spying on Auth guard if needed, but for now we just check it still works)
        $response2 = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH);
        $response2->assertOk();
    }

    public function test_central_user_without_access_to_tenant_is_not_promoted_onto_the_tenant_guard(): void
    {
        $id = 'test-'.uniqid();
        $domain = $this->tenantDomain($id);

        // 1. Create a tenant
        $tenant = Tenant::forceCreate(['id' => $id, 'name' => 'Other Tenant']);
        $tenant->domains()->create([
            'id' => $id,
            'domain' => $domain,
        ]);

        // 2. Create a central user (NOT associated with the tenant)
        $centralUser = CentralUser::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Other User',
            'email' => 'other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        // 3. Try to reach an authenticated tenant route
        $response = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.self::AUTHENTICATED_TENANT_PATH);

        // 4. No promotion happened, so the tenant guard is still empty and
        // the middleware throws AuthenticationException, which redirects.
        $response->assertRedirect();

        $tenant->run(function (): void {
            $this->assertFalse(Auth::guard('tenant')->check());
        });
    }
}
