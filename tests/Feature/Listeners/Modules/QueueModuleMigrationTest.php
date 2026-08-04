<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Modules;

use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Events\Modules\ModulePurchased;
use Nvade\Numerosis\Listeners\Modules\QueueModuleMigration;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Tests\TestCase;

class QueueModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_migration_for_the_purchased_module(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->make(['id' => 'acme']);

        (new QueueModuleMigration)->handle(new ModulePurchased($tenant, 'alerts'));

        MigrateModules::assertPushed();
    }
}
