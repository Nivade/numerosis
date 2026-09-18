<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Observability;

use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Observability\HealthEndpointFeature;
use Nvade\Numerosis\Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Routes are built at boot, so the feature has to be on before the
        // application under test exists.
        FeatureRegistry::forceForTesting([HealthEndpointFeature::class]);

        parent::setUp();

        // The document is cached for a few seconds in production; a test
        // asserting on two different states of the world cannot be.
        Config::set('numerosis.cache.ttl.health_report');
    }

    public function test_it_answers_with_counts_while_the_central_database_is_up(): void
    {
        $this->get($this->healthPath())
            ->assertOk()
            ->assertJsonPath('central_database', true)
            ->assertJsonStructure([
                'central_database',
                'queue' => ['depth', 'oldest_job_seconds', 'failed_jobs'],
                'provisioning' => ['failed_last_hour', 'stalled', 'in_flight'],
                'scheduler_last_run_seconds',
            ]);
    }

    public function test_it_counts_the_last_hour_of_failures_and_the_stalled_chains(): void
    {
        TenantProvision::factory()->failed()->create(['slug' => 'recentfailure']);
        TenantProvision::factory()->failed()->create(['slug' => 'oldfailure', 'failed_at' => now()->subDay()]);
        TenantProvision::factory()->provisioning()->create([
            'slug' => 'stuck',
            'provisioning_started_at' => now()->subHour(),
        ]);

        $this->get($this->healthPath())
            ->assertOk()
            ->assertJsonPath('provisioning.failed_last_hour', 1)
            ->assertJsonPath('provisioning.stalled', 1)
            ->assertJsonPath('provisioning.in_flight', 1);
    }

    /**
     * The endpoint is unauthenticated, so a slug or a tenant name in it is a
     * disclosure to anyone who finds the URL.
     */
    public function test_it_names_no_tenant(): void
    {
        TenantProvision::factory()->failed()->create([
            'slug' => 'confidentialslug',
            'name' => 'Confidential Holdings',
        ]);

        $response = $this->get($this->healthPath())->assertOk();

        $this->assertStringNotContainsString('confidentialslug', $response->getContent() ?: '');
        $this->assertStringNotContainsString('Confidential Holdings', $response->getContent() ?: '');
    }

    /**
     * Degraded, not exploded: a monitor reads the status code, and a 500 from
     * the health check itself reports the wrong outage.
     */
    public function test_it_degrades_rather_than_throwing_when_the_central_connection_is_down(): void
    {
        $central = Config::string('numerosis.tenancy.central_connection');
        $database = Config::get("database.connections.{$central}.database");

        Config::set("database.connections.{$central}.database", 'numerosis_no_such_database');
        DB::purge($central);

        try {
            $this->get($this->healthPath())->assertServiceUnavailable()
                ->assertJsonPath('central_database', false)
                ->assertJsonPath('provisioning.failed_last_hour', 0);
        } finally {
            // Teardown deletes central rows through this connection, so a
            // broken one outlives the assertion it was broken for.
            Config::set("database.connections.{$central}.database", $database);
            DB::purge($central);
        }
    }

    public function test_a_heartbeat_stale_past_the_threshold_reports_unhealthy(): void
    {
        Config::set('numerosis.health.scheduler_stale_after_seconds', 60);

        GlobalCache::store()->put(CacheKeys::schedulerHeartbeat(), now()->subMinutes(5)->getTimestamp());

        $this->get($this->healthPath())
            ->assertServiceUnavailable()
            ->assertJsonPath('central_database', true);
    }

    public function test_a_heartbeat_never_written_is_unknown_not_unhealthy(): void
    {
        Config::set('numerosis.health.scheduler_stale_after_seconds', 60);

        $this->get($this->healthPath())
            ->assertOk()
            ->assertJsonPath('scheduler_last_run_seconds', null);
    }

    private function healthPath(): string
    {
        return '/'.trim(Config::string('numerosis.routes.health_path'), '/');
    }
}
