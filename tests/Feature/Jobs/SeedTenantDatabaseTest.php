<?php

declare(strict_types=1);

use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;

uses(RefreshDatabase::class);

it('throws when tenants:seed exits non-zero', function () {
    $tenant = Tenant::forceCreate(['id' => 'test-tenant-'.uniqid()]);

    // Not Artisan::shouldReceive(): Facade::createMock() mocks the class of
    // whatever is currently bound, and under Testbench that is
    // Orchestra\Testbench\Console\Kernel, which is `final` — Mockery refuses
    // it with "marked final and its methods cannot be replaced". The facade's
    // accessor is the *interface*, so replacing the binding is both possible
    // and closer to what the job actually depends on.
    //
    // A hand-written stub rather than Mockery::mock(ConsoleKernel::class):
    // shouldReceive() is typed as a union including HigherOrderMessage, so
    // chaining ->once() off it is a level-9 method.notFound and would need
    // two new baseline entries to say nothing.
    $kernel = new class implements ConsoleKernel
    {
        public int $calls = 0;

        public int $outputs = 0;

        public function bootstrap() {}

        public function handle($input, $output = null)
        {
            return 0;
        }

        /**
         * @param  array<string, mixed>  $parameters
         */
        public function call($command, array $parameters = [], $outputBuffer = null)
        {
            $this->calls++;

            return 1;
        }

        /**
         * @param  array<string, mixed>  $parameters
         */
        public function queue($command, array $parameters = []): never
        {
            throw new RuntimeException('The job under test never queues a command.');
        }

        /**
         * @return array<string, Symfony\Component\Console\Command\Command>
         */
        public function all()
        {
            return [];
        }

        public function output()
        {
            $this->outputs++;

            return 'seeder blew up';
        }

        public function terminate($input, $status) {}
    };

    app()->instance(ConsoleKernel::class, $kernel);

    $job = new SeedTenantDatabase($tenant);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'seeder blew up');

    expect($kernel->calls)->toBe(1)
        ->and($kernel->outputs)->toBe(1);
});

it('marks the pending provision as failed when the job fails', function () {
    $domain = 'test-tenant-'.uniqid();
    $tenant = Tenant::forceCreate(['id' => $domain]);

    PendingTenantProvision::create([
        'domain' => $domain,
        'company_name' => 'Acme',
        'global_id' => 'user-global-id',
        'status' => TenantProvisionStatus::Provisioning,
    ]);

    $job = new SeedTenantDatabase($tenant);
    $job->failed(new RuntimeException('tenants:seed failed for tenant '.$domain));

    $pending = PendingTenantProvision::where('domain', $domain)->firstOrFail();

    expect($pending->status)->toBe(TenantProvisionStatus::Failed)
        ->and($pending->error)->toContain('tenants:seed failed');
});
