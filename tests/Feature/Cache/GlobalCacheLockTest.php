<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Cache;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The checkout redirect and the Stripe webhook serialize against each other on
 * one lock name. Taken through the `Cache` facade that only holds while both
 * callers are outside tenancy: inside it, the facade is stancl's prefixing
 * manager, so the same name is two locks and the symptom is a duplicated
 * subscription row under load.
 */
class GlobalCacheLockTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_a_global_lock_taken_in_tenant_context_blocks_a_central_caller(): void
    {
        $this->pinGlobalCache();

        $tenant = Tenant::create(['id' => 'lock-'.uniqid()]);

        $tenant->run(function (): void {
            $this->assertTrue(GlobalCache::lock('reconcile-subscription:sub_1', 10)->get());
        });

        $this->assertFalse(
            GlobalCache::lock('reconcile-subscription:sub_1', 10)->get(),
            'The central caller acquired a lock the tenant-context caller already held.'
        );
    }

    /** The behaviour the accessor exists to avoid, asserted so the reason stays visible. */
    public function test_the_cache_facade_splits_the_same_lock_name_in_two(): void
    {
        $this->pinGlobalCache();

        $tenant = Tenant::create(['id' => 'lock-facade-'.uniqid()]);

        $tenant->run(function (): void {
            $this->assertTrue(Cache::lock('reconcile-subscription:sub_2', 10)->get());
        });

        $this->assertTrue(Cache::lock('reconcile-subscription:sub_2', 10)->get());
    }
}
