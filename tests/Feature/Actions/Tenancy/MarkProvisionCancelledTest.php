<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\PendingTenantProvision;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionCancelled;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningCancelled;
use Nvade\Numerosis\Tests\TestCase;

class MarkProvisionCancelledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_the_reservation_and_dispatches_the_event(): void
    {
        Event::fake([TenantProvisioningCancelled::class]);

        $pending = PendingTenantProvision::factory()->create(['domain' => 'acme']);

        MarkProvisionCancelled::run('acme');

        $this->assertDatabaseMissing('pending_tenant_provisions', ['domain' => 'acme']);

        Event::assertDispatched(
            fn (TenantProvisioningCancelled $e) => $e->globalId === $pending->global_id
        );
    }

    public function test_it_throws_when_no_reservation_exists(): void
    {
        $this->expectException(DomainException::class);

        MarkProvisionCancelled::run('nobody');
    }
}
