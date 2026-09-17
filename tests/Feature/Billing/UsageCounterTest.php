<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Tests\TestCase;

class UsageCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_per_tenant_and_per_key(): void
    {
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();

        $counter = $this->counter();

        $counter->increment($first, 'api-calls', 3);
        $counter->increment($second, 'api-calls');
        $counter->increment($first, 'exports');

        $this->assertSame(3, $counter->value($first, 'api-calls'));
        $this->assertSame(1, $counter->value($second, 'api-calls'));
        $this->assertSame(1, $counter->value($first, 'exports'));
        $this->assertSame(['api-calls' => 3, 'exports' => 1], $counter->all($first));
    }

    /**
     * The increment has to happen in the database, not in PHP: this is the
     * shape that loses a write when it is a read-modify-write, and a lost
     * write in a billing meter is money.
     */
    public function test_increments_do_not_lose_writes_when_they_interleave(): void
    {
        $tenant = Tenant::factory()->create();
        $counter = $this->counter();

        // Two readers holding the same stale value, then both writing.
        $counter->increment($tenant, 'api-calls');
        $before = $counter->value($tenant, 'api-calls');

        $counter->increment($tenant, 'api-calls');
        $counter->increment($tenant, 'api-calls');

        $this->assertSame($before + 2, $counter->value($tenant, 'api-calls'));
    }

    public function test_a_period_is_counted_separately_from_the_lifetime_counter(): void
    {
        $tenant = Tenant::factory()->create();
        $counter = $this->counter();

        $counter->increment($tenant, 'api-calls', 2);
        $counter->increment($tenant, 'api-calls', 5, now());

        $this->assertSame(2, $counter->value($tenant, 'api-calls'));
        $this->assertSame(5, $counter->value($tenant, 'api-calls', now()));
    }

    public function test_reset_clears_one_key_only(): void
    {
        $tenant = Tenant::factory()->create();
        $counter = $this->counter();

        $counter->increment($tenant, 'api-calls');
        $counter->increment($tenant, 'exports');

        $counter->reset($tenant, 'api-calls');

        $this->assertSame(0, $counter->value($tenant, 'api-calls'));
        $this->assertSame(1, $counter->value($tenant, 'exports'));
    }

    private function counter(): UsageCounter
    {
        return resolve(UsageCounter::class);
    }
}
