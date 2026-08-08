<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;
use stdClass;

/**
 * Covers the model-override checks only. `--verify-only` is what makes this
 * testable: the default run publishes files and appends to the host's `.env`,
 * neither of which belongs in a test process.
 */
class InstallNumerosisCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests here are about configuration, and would otherwise all
        // fail on verifyCentralDataSeeded() — which is exactly the point of
        // that check, so the two seeded-data tests below turn it off and on
        // deliberately rather than this being papered over.
        $this->seedCentralData();
    }

    public function test_it_passes_when_every_model_override_names_a_real_subclass(): void
    {
        $this->install()->assertSuccessful();
    }

    /**
     * The gap this closes: thin-app's own `db:seed` runs Laravel's skeleton
     * seeder, so the package's seeders were never reached and the central
     * database sat at zero permissions and zero plans while every other check
     * in this command passed.
     */
    public function test_it_fails_when_the_central_permissions_table_is_empty(): void
    {
        DB::connection('central')->table('permissions')->delete();

        $this->install()
            ->expectsOutputToContain('central `permissions` table is empty')
            ->assertFailed();
    }

    public function test_it_fails_when_no_payment_plan_has_been_seeded(): void
    {
        DB::connection('central')->table('payment_plan_features')->delete();
        DB::connection('central')->table('payment_plans')->delete();

        $this->install()
            ->expectsOutputToContain('central `payment_plans` table is empty')
            ->assertFailed();
    }

    /**
     * `numerosis:install --seed` is the fix the failures above point at, so it
     * has to survive being run against an already-seeded database — an install
     * command nobody can re-run is one nobody runs at all.
     */
    public function test_seeding_is_idempotent(): void
    {
        $before = [
            'permissions' => DB::connection('central')->table('permissions')->count(),
            'payment_plans' => DB::connection('central')->table('payment_plans')->count(),
            'features' => DB::connection('central')->table('features')->count(),
            'payment_plan_features' => DB::connection('central')->table('payment_plan_features')->count(),
        ];

        $this->seedCentralData();

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::connection('central')->table($table)->count(),
                "Re-seeding duplicated rows in `{$table}`.",
            );
        }
    }

    /**
     * Not `$this->seed(DatabaseSeeder::class)`: Testbench's `seed()` goes
     * through `artisan('db:seed')`, and in an app with stancl/tenancy
     * installed that name resolves to `Stancl\Tenancy\Commands\Seed`, which
     * throws `The "tenants" option does not exist` — see
     * `InstallNumerosisCommand::seedCentralData()`'s docblock and
     * `.claude/rules/tenant-provisioning.md`. Any test in this package that
     * wants to seed has the same problem.
     */
    private function seedCentralData(): void
    {
        Model::unguarded(function (): void {
            resolve(DatabaseSeeder::class)->setContainer(app())->__invoke();
        });
    }

    public function test_it_fails_when_a_model_override_names_a_class_that_does_not_exist(): void
    {
        config()->set('numerosis.models.'.Tenant::class, 'App\\Models\\Central\\NoSuchTenant');

        $this->install()
            ->expectsOutputToContain('does not exist — check NUMEROSIS_MODEL_TENANT')
            ->assertFailed();
    }

    public function test_it_fails_when_a_model_override_is_not_a_subclass_of_the_package_model(): void
    {
        config()->set('numerosis.models.'.Tenant::class, stdClass::class);

        $this->install()
            ->expectsOutputToContain('does not extend')
            ->assertFailed();
    }

    /**
     * The supported no-stub shape (D8): no override, no published stub, so
     * package code runs on the package's own models and nothing is wrong.
     */
    public function test_it_passes_when_no_override_is_set_and_no_stub_is_published(): void
    {
        config()->set('numerosis.models', []);

        $this->install()->assertSuccessful();
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have been run. Narrowing here keeps every test a single
     * chained call without a baseline entry.
     */
    private function install(): PendingCommand
    {
        $command = $this->artisan('numerosis:install', ['--verify-only' => true]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
