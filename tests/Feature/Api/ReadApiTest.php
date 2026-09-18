<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Api;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Auth\Api\CreateApiToken;
use Nvade\Numerosis\Actions\Queries\GetApiAbilities;
use Nvade\Numerosis\Actions\Queries\GetTenantMembersPage;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Api\ReadApiFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Tenant\User as BaseTenantUser;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Payload shape is the contract. Every field is declared on a `Data` object, so
 * these assertions are what stops a column added to a model later from turning
 * up in a customer's integration.
 */
class ReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([ReadApiFeature::class]);

        parent::setUp();
    }

    public function test_the_tenant_payload_carries_only_declared_fields(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        /** @var array<string, mixed> $payload */
        $payload = $this->getJson('http://'.$domain.'/api/v1/tenant', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['closed', 'created_at', 'id', 'name', 'suspended'],
            $this->sortedKeys($payload),
        );
    }

    public function test_the_members_payload_names_the_role_and_nothing_private(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        /** @var list<array<string, mixed>> $members */
        $members = $this->getJson('http://'.$domain.'/api/v1/members', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $members);
        $this->assertSame(
            ['email', 'global_id', 'joined_at', 'name', 'role'],
            $this->sortedKeys($members[0]),
        );
        $this->assertSame(MembershipRole::Owner->value, $members[0]['role']);
    }

    /** An integration's workspace can be large; the page size is ours to cap. */
    public function test_the_members_endpoint_pages_and_caps_the_page_size(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        foreach (range(1, 3) as $index) {
            $extra = CentralUser::factory()->create();
            $tenant->users()->attach($extra->global_id, [
                'role' => MembershipRole::Member->value,
                'joined_at' => now()->addMinutes($index),
            ]);
        }

        /** @var list<array<string, mixed>> $first */
        $first = $this->getJson('http://'.$domain.'/api/v1/members?per_page=2', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->json('data');

        $this->assertCount(2, $first);

        $this->getJson('http://'.$domain.'/api/v1/members?per_page=9999', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->assertJsonPath('meta.per_page', GetTenantMembersPage::MAX_PER_PAGE);
    }

    public function test_the_subscription_payload_reports_status_and_usage_without_stripe_ids(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        $plan = PaymentPlan::factory()->create([
            'slug' => 'pro',
            'metadata' => ['options' => ['meters' => [[
                'key' => 'api-calls',
                'event_name' => 'api_calls',
                'included' => 100,
            ]]]],
        ]);

        Tenant::unsetEventDispatcher();

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
        ]);

        // GetBillingPeriod only reads the period Stripe stamped on the item;
        // nothing invents one from `created_at` any more.
        SubscriptionItem::factory()->create([
            'subscription_id' => $subscription->id,
            'stripe_price' => $subscription->stripe_price,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->addMonthNoOverflow()->startOfMonth(),
        ]);

        /** @var array{subscription: array<string, mixed>, usage: list<array<string, mixed>>} $payload */
        $payload = $this->getJson('http://'.$domain.'/api/v1/subscription', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->json('data');

        $this->assertSame(['active', 'ends_at', 'plan', 'status', 'trial_ends_at'], $this->sortedKeys($payload['subscription']));
        $this->assertSame('pro', $payload['subscription']['plan']);
        $this->assertStringNotContainsString('stripe_id', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame('api-calls', $payload['usage'][0]['key']);
    }

    public function test_the_domains_payload_lists_only_this_tenants_servable_domains(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        $tenant->domains()->create(['id' => $tenant->id.'-pending', 'domain' => 'pending.example.com', 'status' => DomainStatus::Pending]);
        $tenant->domains()->create(['id' => $tenant->id.'-verified', 'domain' => 'custom.example.com', 'status' => DomainStatus::Verified]);

        [$other] = $this->workspace();
        $other->domains()->create(['id' => $other->id.'-verified', 'domain' => 'other.example.com', 'status' => DomainStatus::Verified]);

        /** @var list<array<string, mixed>> $domains */
        $domains = $this->getJson('http://'.$domain.'/api/v1/domains', $this->tokenHeaders($tenant, $user))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [$domain, 'custom.example.com'],
            array_column($domains, 'domain'),
        );
    }

    public function test_a_token_without_the_domains_ability_is_refused(): void
    {
        [$tenant, $domain, $user] = $this->workspace();

        $plaintext = $tenant->run(fn (): string => CreateApiToken::run(
            $user,
            'no-domains',
            [PermissionContext::Tenants->abilityFor(PermissionAction::View)],
        )->plainTextToken);

        $this->assertIsString($plaintext);

        $this->getJson('http://'.$domain.'/api/v1/domains', [
            'Authorization' => 'Bearer '.$plaintext,
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_an_unauthenticated_call_is_refused_rather_than_answered(): void
    {
        [, $domain] = $this->workspace();

        $this->getJson('http://'.$domain.'/api/v1/tenant', ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    /** Per token, so one tenant's runaway script cannot spend the fleet's budget. */
    public function test_the_rate_limit_is_spent_per_token(): void
    {
        Config::set('numerosis.api.rate_limit', 2);

        [$tenant, $domain, $user] = $this->workspace();
        $other = $this->memberOf($tenant, MembershipRole::Member);

        $first = $this->tokenHeaders($tenant, $user);
        $second = $this->tokenHeaders($tenant, $other);

        $this->getJson('http://'.$domain.'/api/v1/tenant', $first)->assertOk();
        auth()->forgetGuards();
        $this->getJson('http://'.$domain.'/api/v1/tenant', $first)->assertOk();
        auth()->forgetGuards();
        $this->getJson('http://'.$domain.'/api/v1/tenant', $first)->assertTooManyRequests();

        auth()->forgetGuards();

        $this->getJson('http://'.$domain.'/api/v1/tenant', $second)->assertOk();
    }

    /**
     * @param  array<array-key, mixed>|null  $payload
     * @return list<string>
     */
    private function sortedKeys(?array $payload): array
    {
        $keys = array_keys($payload ?? []);
        sort($keys);

        /** @var list<string> $keys */
        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function tokenHeaders(Tenant $tenant, BaseTenantUser $user): array
    {
        $plaintext = $tenant->run(fn (): string => CreateApiToken::run(
            $user,
            'reader',
            GetApiAbilities::run(),
        )->plainTextToken);

        $this->assertIsString($plaintext);

        return ['Authorization' => 'Bearer '.$plaintext, 'Accept' => 'application/json'];
    }

    /**
     * @return array{0: Tenant, 1: string, 2: BaseTenantUser}
     */
    private function workspace(): array
    {
        $id = 'api'.substr(uniqid(), -8);
        $tenant = $this->createTenantWithDomain($id, 'Api Tenant');

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
