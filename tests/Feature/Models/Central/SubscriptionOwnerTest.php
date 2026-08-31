<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\CentralUser;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `subscriptions.subscribable_*` must round-trip for **both** billables.
 *
 * Nothing asserted this before, and the gap is why a wrong fixture survived:
 * `Subscription::$with` eager-loads `subscribable`, and a mis-keyed row
 * resolves to `null` rather than failing, so a test that only checks the row
 * was re-pointed at a tenant passes either way.
 *
 * The key is the owner's **primary** key — `users.id` for a central user,
 * `tenants.id` for a tenant — because that is what Cashier's own
 * `subscriptions()->create()` writes through the `morphMany`, and
 * `Subscription::subscribable()` is a plain `morphTo()` whose owner key
 * defaults to each type's `getKeyName()`. `global_id` is this package's
 * cross-database *identity*, not this column's key; putting one here is
 * silently wrong, and intermittently loud: `subscribable_id` is a string
 * column while `CentralUser::getKeyType()` is `'int'`, so eager loading goes
 * through `whereIntegerInRaw`, which casts every value. Harmless for `"1"`;
 * for a UUID shaped like `3e106911-…` (a valid PHP float-string, roughly 1 in
 * 250 UUIDs) it raises `The float-string … is not representable as an int`.
 */
class SubscriptionOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_central_user_subscription_resolves_its_owner(): void
    {
        $user = CentralUser::factory()->create();

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $user->getKey(),
            'subscribable_type' => CentralUser::class,
        ]);

        $subscription->refresh();

        $this->assertNotNull($subscription->subscribable, 'The morph resolved to null — subscribable_id does not match the owner key.');
        $this->assertTrue($user->is($subscription->subscribable));
        $this->assertSame(1, $user->subscriptions()->count());
    }

    public function test_a_tenant_subscription_resolves_its_owner(): void
    {
        $tenant = Tenant::factory()->create();

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => Tenant::class,
        ]);

        $subscription->refresh();

        $this->assertNotNull($subscription->subscribable, 'The morph resolved to null — subscribable_id does not match the owner key.');
        $this->assertTrue($tenant->is($subscription->subscribable));
        $this->assertSame(1, $tenant->subscriptions()->count());
    }
}
