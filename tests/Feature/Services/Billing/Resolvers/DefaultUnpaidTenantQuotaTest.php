<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing\Resolvers;

use Nvade\Numerosis\Exceptions\Billing\TooManyUnpaidTenants;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Services\Billing\Resolvers\DefaultUnpaidTenantQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class DefaultUnpaidTenantQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_at_the_cap_cannot_start_another_unsettled_checkout(): void
    {
        Tenant::unsetEventDispatcher();
        config(['numerosis-billing.unpaid_tenant_cap' => 2]);

        $user = CentralUser::factory()->create();

        for ($i = 0; $i < 2; $i++) {
            $tenant = Tenant::factory()->create();
            $tenant->users()->attach($user->global_id, ['role' => 'owner']);
        }

        $this->expectException(TooManyUnpaidTenants::class);

        (new DefaultUnpaidTenantQuota)->assertAvailable($user);
    }

    /**
     * A settled tenant does not count against it.
     */
    public function test_a_settled_tenant_does_not_count_against_the_quota(): void
    {
        Tenant::unsetEventDispatcher();
        config(['numerosis-billing.unpaid_tenant_cap' => 1]);

        $user = CentralUser::factory()->create();

        $settled = Tenant::factory()->create();
        $settled->users()->attach($user->global_id, ['role' => 'owner']);
        Subscription::factory()->create([
            'stripe_status' => 'active',
            'subscribable_id' => $settled->id,
            'subscribable_type' => Tenant::class,
        ]);

        // The only owned tenant is settled, so it does not count against a
        // cap of 1 — assertAvailable() must not throw.
        $this->expectNotToPerformAssertions();

        (new DefaultUnpaidTenantQuota)->assertAvailable($user);
    }

    public function test_it_allows_checkout_below_the_cap(): void
    {
        Tenant::unsetEventDispatcher();
        config(['numerosis-billing.unpaid_tenant_cap' => 2]);

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        $this->expectNotToPerformAssertions();

        (new DefaultUnpaidTenantQuota)->assertAvailable($user);
    }
}
