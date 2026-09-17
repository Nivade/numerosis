<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Nvade\Numerosis\Actions\Queries\GetServableDomains;
use Nvade\Numerosis\Actions\Tenancy\Domains\RecordDomainVerification;
use Nvade\Numerosis\Data\Tenancy\DomainVerificationResult;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The ask endpoint is public and runs per new SNI, so what it answers and what
 * it costs both matter. The riskiest assertion is the first: a suspended or
 * closed tenant whose hostname still answers over HTTPS is a data-exposure bug.
 */
class TlsPresentersTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Both routes are registered at boot from config that reads env inside
        // config/numerosis.php, so the switches have to be on before the
        // application under test exists.
        putenv('NUMEROSIS_TLS_ASK_ENDPOINT=1');
        putenv('NUMEROSIS_TLS_ROUTERS_ENDPOINT=1');
        $_ENV['NUMEROSIS_TLS_ASK_ENDPOINT'] = '1';
        $_ENV['NUMEROSIS_TLS_ROUTERS_ENDPOINT'] = '1';

        parent::setUp();

        Tenant::unsetEventDispatcher();

        $this->pinGlobalCache();
    }

    protected function tearDown(): void
    {
        putenv('NUMEROSIS_TLS_ASK_ENDPOINT');
        putenv('NUMEROSIS_TLS_ROUTERS_ENDPOINT');
        unset($_ENV['NUMEROSIS_TLS_ASK_ENDPOINT'], $_ENV['NUMEROSIS_TLS_ROUTERS_ENDPOINT']);

        parent::tearDown();
    }

    public function test_the_ask_endpoint_answers_for_a_serving_domain_only(): void
    {
        $this->domain('live.example.com', DomainStatus::Active);
        $this->domain('proven.example.com', DomainStatus::Verified);
        $this->domain('waiting.example.com', DomainStatus::Pending);
        $this->domain('broken.example.com', DomainStatus::Failed);
        $this->domain('gone.example.com', DomainStatus::Revoked);

        $this->ask('live.example.com')->assertOk();
        $this->ask('proven.example.com')->assertOk();
        $this->ask('waiting.example.com')->assertNotFound();
        $this->ask('broken.example.com')->assertNotFound();
        $this->ask('gone.example.com')->assertNotFound();
        $this->ask('somebody-elses.example.com')->assertNotFound();
        $this->ask('')->assertNotFound();
    }

    public function test_a_suspended_or_closed_tenant_is_not_served(): void
    {
        $suspended = $this->domain('suspended.example.com', DomainStatus::Active);
        $closed = $this->domain('closed.example.com', DomainStatus::Active);

        $suspended->tenant->forceFill(['suspended_at' => now()])->save();
        $closed->tenant->forceFill(['closed_at' => now()])->save();

        $this->ask('suspended.example.com')->assertNotFound();
        $this->ask('closed.example.com')->assertNotFound();
        $this->assertSame([], GetServableDomains::run());
    }

    /** Caddy asks once per new SNI, so the answer must not be a query each time. */
    public function test_the_answer_is_cached_and_invalidated_on_verification(): void
    {
        $domain = $this->domain('cached.example.com', DomainStatus::Pending);

        $this->ask('cached.example.com')->assertNotFound();

        // Straight to the column, so nothing but the cache could still be
        // answering "no" afterwards.
        $domain->forceFill(['status' => DomainStatus::Active])->save();

        $this->ask('cached.example.com')->assertNotFound();

        RecordDomainVerification::run($domain, DomainVerificationResult::proven(true));

        $this->ask('cached.example.com')->assertOk();
    }

    public function test_revocation_invalidates_the_cached_answer(): void
    {
        $domain = $this->domain('revoking.example.com', DomainStatus::Active);

        $this->ask('revoking.example.com')->assertOk();

        RecordDomainVerification::run($domain, DomainVerificationResult::claimedElsewhere());

        $this->ask('revoking.example.com')->assertNotFound();
    }

    public function test_the_routers_endpoint_is_traefik_configuration_with_each_domain_once(): void
    {
        $this->domain('one.example.com', DomainStatus::Active);
        $this->domain('two.example.com', DomainStatus::Verified);
        $this->domain('hidden.example.com', DomainStatus::Pending);

        Config::set('numerosis.tenancy.custom_domains.tls.service', 'numerosis-app');
        Config::set('numerosis.tenancy.custom_domains.tls.cert_resolver', 'acme');

        $response = $this->getJson(Config::string('numerosis.tenancy.custom_domains.tls.routers_path'))->assertOk();

        /** @var array<string, array{rule: string, service: string, tls: array{certResolver: string}}> $routers */
        $routers = $response->json('http.routers');

        $this->assertCount(2, $routers);

        $rules = array_column($routers, 'rule');

        $this->assertContains('Host(`one.example.com`)', $rules);
        $this->assertContains('Host(`two.example.com`)', $rules);
        $this->assertSame($rules, array_unique($rules));

        foreach ($routers as $router) {
            $this->assertSame('numerosis-app', $router['service']);
            $this->assertSame('acme', $router['tls']['certResolver']);
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function ask(string $domain): TestResponse
    {
        $path = Config::string('numerosis.tenancy.custom_domains.tls.ask_path');

        return $this->get($path.'?domain='.urlencode($domain));
    }

    private function domain(string $hostname, DomainStatus $status): Domain
    {
        /** @var BaseTenant $tenant */
        $tenant = Tenant::factory()->create();

        /** @var Domain $domain */
        $domain = Domain::query()->create([
            'id' => $hostname,
            'domain' => $hostname,
            'tenant_id' => (string) $tenant->getTenantKey(),
            'status' => $status,
            'verification_token' => 'numerosis-verify-token',
            'verified_at' => $status->isServable() ? now() : null,
        ]);

        return $domain;
    }
}
