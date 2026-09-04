<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionInProgress;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningStarted;
use Nvade\Numerosis\Tests\TestCase;

class MarkProvisionInProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_tenant_provisioning_started_with_the_right_payload(): void
    {
        Event::fake([TenantProvisioningStarted::class]);

        $user = CentralUser::factory()->create();

        MarkProvisionInProgress::run(new TenantRegistrationData(
            company_name: 'Started Co',
            domain: 'started-co',
            global_id: $user->global_id,
        ));

        Event::assertDispatched(fn (TenantProvisioningStarted $e): bool => $e->domain === 'started-co' && $e->globalId === $user->global_id);
    }
}
