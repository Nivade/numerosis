<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Tests\TestCase;

class TenantAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_user_can_access_tenant_admin_panel_as_tenant_user(): void
    {
        $id = 'test-'.uniqid();
        $domain = $this->tenantDomain($id);

        // 1. Create a tenant. forceCreate, as production does: `id` is not
        // fillable, so Tenant::create() drops it and UUIDGenerator assigns a
        // uuid instead — the subdomain then no longer matches the panel's
        // {tenant} route parameter and Filament answers 404.
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
        // The global_id must match the central user's: Filament resolves the
        // panel tenant through User::canAccessTenant(), which looks the
        // membership up by global_id, and answers 404 when it finds none.
        $tenant->run(function () use ($centralUser) {
            // Create modules table if it doesn't exist
            \Illuminate\Support\Facades\Schema::dropIfExists('modules');
            \Illuminate\Support\Facades\Schema::create('modules', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('description')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });

            TenantUser::create([
                'global_id' => $centralUser->global_id,
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        });

        // 5. Authenticate central user on 'web' guard
        Auth::guard('web')->login($centralUser);

        // 6. Access the tenant admin panel (this should trigger the SSO in Authenticate middleware)
        $response = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.'/');

        // 7. Assertions
        $response->assertStatus(200);

        // Verify that the user is now also logged in via the 'tenant' guard as a Tenant\User
        $tenant->run(function () use ($centralUser) {
            $this->assertTrue(Auth::guard('tenant')->check());
            $this->assertInstanceOf(TenantUser::class, Auth::guard('tenant')->user());
            $this->assertEquals($centralUser->global_id, Auth::guard('tenant')->user()->global_id);
        });

        // 8. Second request should NOT re-trigger login (we can check this by spying on Auth guard if needed, but for now we just check it still works)
        $response2 = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.'/');
        $response2->assertStatus(200);
    }

    public function test_central_user_without_access_to_tenant_cannot_access_panel(): void
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

        // 3. Try to access the tenant admin panel
        $response = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.'/');

        // 4. Assertion (should be redirected or 403, depending on Authenticate middleware)
        // Authenticate middleware throws AuthenticationException which redirects to login by default
        $response->assertRedirect();
    }
}
