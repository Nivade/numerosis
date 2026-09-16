<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `AuthenticateSession` sits in the `tenant` group behind
 * `EnsureSessionMatchesTenant`. It reads the tenant guard, so ordering is what
 * decides whether it compares the stamp of the person this session belongs to
 * on *this* tenant, or the one it held on the tenant visited before.
 */
class TenantSessionStampTest extends TestCase
{
    use RefreshDatabase;

    private const string AUTHENTICATED_TENANT_PATH = '/account-suspended';

    public function test_one_session_moving_between_two_tenants_stays_authenticated_on_both(): void
    {
        $user = $this->centralUser();

        $first = $this->tenantFor($user);
        $second = $this->tenantFor($user);

        $this->actingAs($user, 'web')
            ->get('http://'.$this->tenantDomain($first->id).self::AUTHENTICATED_TENANT_PATH)
            ->assertOk();

        $this->actingAs($user, 'web')
            ->get('http://'.$this->tenantDomain($second->id).self::AUTHENTICATED_TENANT_PATH)
            ->assertOk();

        $second->run(function () use ($user): void {
            $this->assertSame($user->global_id, Auth::guard('tenant')->user()?->global_id);
        });
    }

    /**
     * The id-collision case: both tenant rows are primary key 1 in their own
     * database, so a stamp compared before `EnsureSessionMatchesTenant` has
     * dropped the previous tenant's guard state is compared against a
     * different person's password hash.
     */
    public function test_a_second_users_tenant_row_reusing_the_same_primary_key_is_not_carried_over(): void
    {
        $user = $this->centralUser();
        $colleague = $this->centralUser();

        $shared = $this->tenantFor($user);
        $theirs = $this->tenantFor($colleague);

        $this->actingAs($user, 'web')
            ->get('http://'.$this->tenantDomain($shared->id).self::AUTHENTICATED_TENANT_PATH)
            ->assertOk();

        // One app serves both requests, and the tenant guard caches the user
        // it resolved on the first one; a real second request has no such
        // instance to fall back on.
        Auth::forgetGuards();

        // No membership on the second tenant, so the promotion refuses and the
        // carried-over tenant session must not authenticate them instead.
        $this->actingAs($user, 'web')
            ->get('http://'.$this->tenantDomain($theirs->id).self::AUTHENTICATED_TENANT_PATH)
            ->assertRedirect();
    }

    private function centralUser(): CentralUser
    {
        return CentralUser::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Session User',
            'email' => 'session-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
    }

    private function tenantFor(CentralUser $user): Tenant
    {
        $tenant = $this->createTenantWithDomain('test-'.uniqid());

        // The observer creates the tenant-side row for a provisioned tenant,
        // so attaching is the whole setup.
        $user->tenants()->attach($tenant, ['role' => 'admin', 'joined_at' => now()]);

        return $tenant;
    }
}
