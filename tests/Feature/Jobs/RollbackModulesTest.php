<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Actions\Modules\RollbackModules;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

class RollbackModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_back_a_single_module(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'alerts');

        RollbackModules::dispatchSync($tenant, 'alerts');

        $tenant->run(function () {
            $this->assertFalse(
                DB::table('migrations')->where('migration', 'like', '%set_up_alerts_module%')->exists()
            );
        });
    }

    public function test_a_failed_rollback_throws(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenants:rollback-module failed');

        RollbackModules::dispatchSync($tenant, 'does-not-exist');
    }
}
