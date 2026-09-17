<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Nvade\Numerosis\Actions\Tenancy\SetTenantTwoFactorRequirement;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The factor lives on the central account because that is the provider every
 * login checks credentials against, so the gate resolves the member's central
 * row and the redirect leaves the tenant domain.
 */
class TenantTwoFactorRequirementTest extends TestCase
{
    use RefreshDatabase;

    /** Inside the enrolment gate, unlike `team.index` and the switch itself. */
    private const string GATED_PATH = '/team/invitations';

    public function test_an_unenrolled_member_is_redirected_to_enrolment(): void
    {
        $user = $this->centralUser();
        $tenant = $this->tenantFor($user);

        SetTenantTwoFactorRequirement::run($tenant, true);
        $this->lapseGrace($tenant);

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).self::GATED_PATH)
            ->assertRedirect(route('settings.two-factor'));
    }

    /**
     * The gate sits on the `tenant` middleware group, so a route this package
     * never sees is held too. A host's product routes are the whole point.
     */
    public function test_a_host_route_on_the_tenant_group_is_gated(): void
    {
        $user = $this->centralUser();
        $tenant = $this->tenantFor($user);

        Route::middleware(['tenant', MiddlewareAlias::TenancyAuth->value.':'.Context::Tenant->guard()])
            ->get('/product', fn (): string => 'the product');

        SetTenantTwoFactorRequirement::run($tenant, true);
        $this->lapseGrace($tenant);

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).'/product')
            ->assertRedirect(route('settings.two-factor'));
    }

    /** The switch that turned the requirement on has to stay reachable. */
    public function test_an_unenrolled_member_still_reaches_the_team_screen(): void
    {
        $user = $this->centralUser();
        $tenant = $this->tenantFor($user);

        SetTenantTwoFactorRequirement::run($tenant, true);
        $this->lapseGrace($tenant);

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).'/team')
            ->assertOk();
    }

    public function test_an_enrolled_member_passes(): void
    {
        $user = $this->withConfirmedTwoFactor($this->centralUser());
        $tenant = $this->tenantFor($user);

        SetTenantTwoFactorRequirement::run($tenant, true);
        $this->lapseGrace($tenant);

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).self::GATED_PATH)
            ->assertRedirect('http://'.$this->tenantDomain($tenant->id).'/team');
    }

    public function test_the_grace_period_suppresses_the_requirement_until_it_lapses(): void
    {
        $user = $this->centralUser();
        $tenant = $this->tenantFor($user);

        SetTenantTwoFactorRequirement::run($tenant, true);

        $this->assertTrue($tenant->refresh()->requiresTwoFactor());
        $this->assertFalse($tenant->refresh()->requiresTwoFactorNow());

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).self::GATED_PATH)
            ->assertRedirect('http://'.$this->tenantDomain($tenant->id).'/team');
    }

    /**
     * Email possession is the weaker factor, and an unconfirmed secret proves
     * nothing at all: the tenant asked for a device.
     */
    public function test_an_unconfirmed_secret_does_not_satisfy_the_requirement(): void
    {
        $user = $this->centralUser();
        resolve(EnableTwoFactorAuthentication::class)($user);

        $tenant = $this->tenantFor($user);

        SetTenantTwoFactorRequirement::run($tenant, true);
        $this->lapseGrace($tenant);

        $this->actingAsCentralUser($user)
            ->get('http://'.$this->tenantDomain($tenant->id).self::GATED_PATH)
            ->assertRedirect(route('settings.two-factor'));
    }

    /** Toggling the switch a second time must not restart the clock. */
    public function test_turning_the_requirement_on_twice_keeps_the_original_deadline(): void
    {
        $tenant = $this->tenantFor($this->centralUser());

        SetTenantTwoFactorRequirement::run($tenant, true);
        $first = $tenant->refresh()->requires_two_factor_from;

        $this->travel(2)->days();
        SetTenantTwoFactorRequirement::run($tenant->refresh(), true);

        $this->assertEquals($first, $tenant->refresh()->requires_two_factor_from);
    }

    public function test_turning_it_off_clears_the_deadline(): void
    {
        $tenant = $this->tenantFor($this->centralUser());

        SetTenantTwoFactorRequirement::run($tenant, true);
        SetTenantTwoFactorRequirement::run($tenant->refresh(), false);

        $this->assertFalse($tenant->refresh()->requiresTwoFactor());
        $this->assertNull($tenant->refresh()->requires_two_factor_from);
    }

    public function test_the_owner_turns_the_requirement_on_from_the_team_screen(): void
    {
        $tenant = $this->tenantFor($this->centralUser());
        $domain = $this->tenantDomain($tenant->id);
        [, $owner] = $this->member($tenant, MembershipRole::Owner);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->patch('http://'.$domain.'/team/two-factor', [
                'required' => 1,
                'password' => 'password',
            ])
            ->assertRedirect('http://'.$domain.'/team');

        $this->assertTrue($tenant->refresh()->requiresTwoFactor());
    }

    public function test_a_member_who_is_not_the_owner_cannot_turn_it_on(): void
    {
        $tenant = $this->tenantFor($this->centralUser());
        $domain = $this->tenantDomain($tenant->id);
        [, $member] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($member);

        $this->patch('http://'.$domain.'/team/two-factor', [
            'required' => 1,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertFalse($tenant->refresh()->requiresTwoFactor());
    }

    /**
     * @return array{0: BaseCentralUser, 1: TenantUser}
     */
    private function member(Tenant $tenant, MembershipRole $role): array
    {
        // Tenancy left initialized by an earlier request makes the tenant-side
        // twin `ResourceSyncing` attach a second membership of its own, which
        // the unique index then rejects.
        tenancy()->end();

        $central = CentralUser::factory()->create();

        $tenant->users()->attach($central->global_id, ['role' => $role->value, 'joined_at' => now()]);

        /** @var TenantUser $user */
        $user = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        return [$central, $user];
    }

    private function centralUser(): BaseCentralUser
    {
        /** @var BaseCentralUser $user */
        $user = CentralUser::factory()->create();

        return $user;
    }

    private function tenantFor(BaseCentralUser $user): Tenant
    {
        $tenant = $this->createTenantWithDomain('twofactor'.substr(uniqid(), -8));

        $user->tenants()->attach($tenant, ['role' => 'admin', 'joined_at' => now()]);

        return $tenant;
    }

    /** The grace period is what keeps the switch from locking a team out. */
    private function lapseGrace(Tenant $tenant): void
    {
        $tenant->forceFill(['requires_two_factor_from' => now()->subMinute()])->save();
    }
}
