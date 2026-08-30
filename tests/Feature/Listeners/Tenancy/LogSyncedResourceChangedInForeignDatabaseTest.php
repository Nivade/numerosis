<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Tenancy;

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Nvade\Numerosis\Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
use Nvade\Numerosis\Tests\TestCase;

class LogSyncedResourceChangedInForeignDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_the_model_and_tenant(): void
    {
        Log::spy();

        $user = TenantUser::factory()->make(['global_id' => 'global-123']);
        $tenant = Tenant::factory()->make(['id' => 'acme']);

        $eventClass = TenancyVersion::syncedResourceChangedInForeignDatabaseEventClass();

        /** @var \Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase|\Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase $event */
        $event = new $eventClass($user, $tenant);

        (new LogSyncedResourceChangedInForeignDatabase)->handle($event);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Synced resource changed in foreign database'
                && $context['central_model'] === $user->getCentralModelName()
                && $context['global_id'] === 'global-123'
                && $context['tenant_id'] === 'acme');
    }
}
