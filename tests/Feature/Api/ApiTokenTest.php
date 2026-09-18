<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Api;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Auth\Api\CreateApiToken;
use Nvade\Numerosis\Actions\Auth\Api\RevokeApiToken;
use Nvade\Numerosis\Actions\Queries\GetApiAbilities;
use Nvade\Numerosis\Actions\Tenancy\RemoveMember;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Api\ReadApiFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant\User as BaseTenantUser;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Tokens live in the tenant database and answer for one workspace. The
 * assertions worth having are the refusals: another tenant's data, a write, an
 * expired key, and a key whose membership is gone.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([ReadApiFeature::class]);

        parent::setUp();
    }

    public function test_a_token_reads_its_own_tenant(): void
    {
        [$tenant, $domain, $user] = $this->tenantWithMember('alpha');

        $plaintext = $this->tokenFor($tenant, $user, [
            PermissionContext::Tenants->abilityFor(PermissionAction::View),
        ]);

        $this->getJson('http://'.$domain.'/api/v1/tenant', $this->authorize($plaintext))
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id);
    }

    /** Asserted against the endpoint, not against the middleware in isolation. */
    public function test_a_token_cannot_read_another_tenant(): void
    {
        [$alpha, , $alphaUser] = $this->tenantWithMember('alpha');
        [, $betaDomain] = $this->tenantWithMember('beta');

        $plaintext = $this->tokenFor($alpha, $alphaUser, [
            PermissionContext::Tenants->abilityFor(PermissionAction::View),
        ]);

        $this->getJson('http://'.$betaDomain.'/api/v1/tenant', $this->authorize($plaintext))
            ->assertUnauthorized();
    }

    public function test_a_read_token_cannot_reach_an_endpoint_it_was_not_granted(): void
    {
        [$tenant, $domain, $user] = $this->tenantWithMember('alpha');

        $plaintext = $this->tokenFor($tenant, $user, [
            PermissionContext::Tenants->abilityFor(PermissionAction::View),
        ]);

        $this->getJson('http://'.$domain.'/api/v1/members', $this->authorize($plaintext))
            ->assertForbidden();
    }

    /** A write ability no endpoint honours would read as a promise. */
    public function test_a_write_ability_is_never_granted(): void
    {
        [$tenant, , $user] = $this->tenantWithMember('alpha');

        $granted = $tenant->run(function () use ($user): array {
            $abilities = CreateApiToken::run(
                $user,
                'writer',
                ['users.viewAny', 'users.delete', 'tenants.update'],
            )->accessToken->getAttribute('abilities');

            /** @var list<string> $abilities */
            $abilities = is_array($abilities) ? array_values($abilities) : [];

            return $abilities;
        });

        $this->assertSame(['users.viewAny'], $granted);
    }

    public function test_an_expired_token_is_refused_as_expired_rather_than_forbidden(): void
    {
        [$tenant, $domain, $user] = $this->tenantWithMember('alpha');

        $plaintext = $this->tokenFor($tenant, $user, [
            PermissionContext::Tenants->abilityFor(PermissionAction::View),
        ], expiredDaysAgo: 1);

        $this->getJson('http://'.$domain.'/api/v1/tenant', $this->authorize($plaintext))
            ->assertUnauthorized();
    }

    public function test_a_token_used_outside_its_allowlist_is_refused(): void
    {
        [$tenant, $domain, $user] = $this->tenantWithMember('alpha');

        $plaintext = $this->tokenFor(
            $tenant,
            $user,
            [PermissionContext::Tenants->abilityFor(PermissionAction::View)],
            ips: ['198.51.100.9'],
        );

        $this->getJson('http://'.$domain.'/api/v1/tenant', $this->authorize($plaintext))
            ->assertForbidden()
            ->assertJsonPath('error', 'address_not_allowed');
    }

    public function test_removing_a_membership_revokes_its_tokens(): void
    {
        [$tenant, $domain] = $this->tenantWithMember('alpha');

        // A member rather than the owner: an owner's membership cannot be
        // removed at all, which is a different rule.
        $member = $this->memberOf($tenant, MembershipRole::Member);

        $plaintext = $this->tokenFor($tenant, $member, [
            PermissionContext::Tenants->abilityFor(PermissionAction::View),
        ]);

        $this->getJson('http://'.$domain.'/api/v1/tenant', $this->authorize($plaintext))->assertOk();

        RemoveMember::run(Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('global_user_id', $member->global_id)
            ->firstOrFail());

        $this->assertSame(0, $tenant->run(fn (): int => ApiToken::query()->count()));

        // The guard memoizes its user for the life of the application, and one
        // test makes two requests through the same one.
        auth()->forgetGuards();

        $this->getJson('http://'.$domain.'/api/v1/tenant', $this->authorize($plaintext))
            ->assertUnauthorized();
    }

    public function test_revoking_a_token_is_scoped_to_its_owner(): void
    {
        [$tenant, , $user] = $this->tenantWithMember('alpha');
        $other = $this->memberOf($tenant, MembershipRole::Member);

        $tenant->run(function () use ($user, $other): void {
            $token = CreateApiToken::run($user, 'mine', ['tenants.view'])->accessToken;
            $id = $token->getKey();

            $this->assertIsInt($id);
            $this->assertFalse(RevokeApiToken::run($other, $id));
            $this->assertTrue(RevokeApiToken::run($user, $id));
        });
    }

    /**
     * No tenant screen shows what the workspace pays, so the token is the only
     * way to ask and the role has to be what answers.
     */
    public function test_a_plain_member_cannot_read_billing_through_a_token(): void
    {
        $id = 'member'.substr(uniqid(), -8);
        $tenant = $this->createTenantWithDomain($id, 'Member Tenant');
        $domain = $this->tenantDomain($id);
        $member = $this->memberOf($tenant, MembershipRole::Member);

        $ability = PermissionContext::Subscriptions->abilityFor(PermissionAction::View);

        /** @var list<string> $offered */
        $offered = $tenant->run(fn (): array => GetApiAbilities::run($member));

        $this->assertNotContains($ability, $offered);

        $plaintext = $this->tokenFor($tenant, $member, [$ability]);

        $this->getJson('http://'.$domain.'/api/v1/subscription', $this->authorize($plaintext))
            ->assertForbidden();
    }

    /** The owner of the same workspace reaches it. */
    public function test_an_owner_reads_billing_through_a_token(): void
    {
        [$tenant, $domain, $owner] = $this->tenantWithMember('owner');

        $plaintext = $this->tokenFor($tenant, $owner, [
            PermissionContext::Subscriptions->abilityFor(PermissionAction::View),
        ]);

        $this->getJson('http://'.$domain.'/api/v1/subscription', $this->authorize($plaintext))
            ->assertOk();
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $ips
     */
    private function tokenFor(
        Tenant $tenant,
        BaseTenantUser $user,
        array $abilities,
        int $expiredDaysAgo = 0,
        array $ips = [],
    ): string {
        $plaintext = $tenant->run(function () use ($user, $abilities, $expiredDaysAgo, $ips): string {
            $token = CreateApiToken::run($user, 'test', $abilities, null, $ips);

            if ($expiredDaysAgo > 0) {
                $token->accessToken->forceFill(['expires_at' => now()->subDays($expiredDaysAgo)])->save();
            }

            return $token->plainTextToken;
        });

        $this->assertIsString($plaintext);

        return $plaintext;
    }

    /**
     * @return array<string, string>
     */
    private function authorize(string $plaintext): array
    {
        return ['Authorization' => 'Bearer '.$plaintext, 'Accept' => 'application/json'];
    }

    /**
     * @return array{0: Tenant, 1: string, 2: BaseTenantUser}
     */
    private function tenantWithMember(string $prefix): array
    {
        $id = $prefix.substr(uniqid(), -8);
        $tenant = $this->createTenantWithDomain($id, ucfirst($prefix).' Tenant');

        return [$tenant, $this->tenantDomain($id), $this->memberOf($tenant, MembershipRole::Owner)];
    }

    private function memberOf(Tenant $tenant, MembershipRole $role): BaseTenantUser
    {
        tenancy()->end();

        $central = CentralUser::factory()->create();

        $tenant->users()->attach($central->global_id, ['role' => $role->value, 'joined_at' => now()]);

        /** @var BaseTenantUser $member */
        $member = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        return $member;
    }
}
