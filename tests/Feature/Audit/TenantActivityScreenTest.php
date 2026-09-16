<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Audit;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The entries are central rows read on a tenant route, which carries no tenant
 * scope of its own — `ReadActivityLog::forTenant()` is where the scoping is,
 * and the last test is what would fail if it were dropped.
 */
class TenantActivityScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_reads_the_teams_activity(): void
    {
        [$tenant, $domain] = $this->tenant();

        // Not a suspension: `tenancy.subscription` redirects a suspended
        // tenant off every product route, this screen included.
        $actor = $this->member($tenant, MembershipRole::Admin);

        $this->actingAsTenantUser($actor);

        $this->get('http://'.$domain.'/team/activity')
            ->assertOk()
            ->assertSee('Member joined');
    }

    public function test_a_plain_member_is_refused(): void
    {
        [, $domain] = $this->tenant();
        $tenant = $this->createTenantWithDomain('audit'.substr(uniqid(), -8), 'Audit Tenant');

        $actor = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($actor);

        $this->get('http://'.$this->tenantDomain($tenant->id).'/team/activity')
            ->assertForbidden();

        $this->assertNotSame('', $domain);
    }

    public function test_another_tenants_entries_are_not_listed(): void
    {
        [$tenant, $domain] = $this->tenant();
        $other = $this->createTenantWithDomain('audit'.substr(uniqid(), -8), 'Other Tenant');

        $other->forceFill(['name' => 'A distinctive other name'])->save();
        SuspendTenant::run($other);

        $actor = $this->member($tenant, MembershipRole::Admin);

        $this->actingAsTenantUser($actor);

        $this->get('http://'.$domain.'/team/activity')
            ->assertOk()
            ->assertDontSee('Tenant suspended');
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function tenant(): array
    {
        $id = 'audit'.substr(uniqid(), -8);

        return [$this->createTenantWithDomain($id, 'Audit Tenant'), $this->tenantDomain($id)];
    }

    private function member(Tenant $tenant, MembershipRole $role): TenantUser
    {
        tenancy()->end();

        $central = CentralUser::factory()->create();

        $tenant->users()->attach($central->global_id, ['role' => $role->value, 'joined_at' => now()]);

        /** @var TenantUser $member */
        $member = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        return $member;
    }
}
