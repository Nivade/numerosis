<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Actions\Tenancy\ImpersonateTenantUser;
use Nvade\Numerosis\Exceptions\Tenancy\TenantHasNoOwner;
use Nvade\Numerosis\Tests\TestCase;

class ImpersonateTenantUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: CentralUser, 2: string}
     */
    private function provisionedTenantWithOwner(): array
    {
        $tenant = Tenant::factory()->create();
        $domain = $this->tenantDomain($tenant->id);
        $tenant->domains()->create(['id' => $tenant->id, 'domain' => $domain]);
        $tenant->update(['provisioned_at' => now()]);

        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner->global_id, ['role' => 'owner', 'joined_at' => now()]);

        $tenant->run(function () use ($owner): void {
            TenantUser::create([
                'global_id' => $owner->global_id,
                'name' => $owner->name,
                'email' => $owner->email,
            ]);
        });

        return [$tenant, $owner, $domain];
    }

    public function test_it_redirects_to_the_tenants_impersonate_route(): void
    {
        [$tenant, , $domain] = $this->provisionedTenantWithOwner();

        $response = ImpersonateTenantUser::run($tenant);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('http://'.$domain.'/impersonate/', $response->getTargetUrl());
    }

    public function test_it_throws_when_the_tenant_has_no_owner(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(TenantHasNoOwner::class);

        ImpersonateTenantUser::run($tenant);
    }

    public function test_following_the_link_logs_the_owner_in_on_the_tenant_domain(): void
    {
        [$tenant, $owner] = $this->provisionedTenantWithOwner();

        $url = ImpersonateTenantUser::run($tenant)->getTargetUrl();

        $this->get($url)->assertRedirect();

        $tenant->run(function () use ($owner): void {
            $user = Auth::guard('tenant')->user();

            $this->assertInstanceOf(TenantUser::class, $user);
            $this->assertEquals($owner->global_id, $user->global_id);
        });
    }

    public function test_the_token_cannot_be_reused(): void
    {
        [$tenant] = $this->provisionedTenantWithOwner();

        $url = ImpersonateTenantUser::run($tenant)->getTargetUrl();

        $this->get($url)->assertRedirect();
        $this->get($url)->assertNotFound();
    }
}
