<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Queries\GetServableDomains;
use Nvade\Numerosis\Actions\Tenancy\Domains\ClaimCustomDomain;
use Nvade\Numerosis\Actions\Tenancy\Domains\RecordDomainVerification;
use Nvade\Numerosis\Actions\Tenancy\Domains\VerifyDomainOwnership;
use Nvade\Numerosis\Contracts\Tenancy\DnsResolver;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Events\Tenancy\DomainRevoked;
use Nvade\Numerosis\Events\Tenancy\DomainVerified;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Support\FakeDnsResolver;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Both halves of the proof are reported separately: a TXT record without the
 * CNAME is a domain that verified fine and still 404s.
 */
class CustomDomainVerificationTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::unsetEventDispatcher();

        $this->dns = new FakeDnsResolver;
        app()->instance(DnsResolver::class, $this->dns);

        Config::set('numerosis.tenancy.custom_domains.cname_target', 'proxy.numerosis.test');
        Config::set('numerosis.tenancy.custom_domains.a_records', ['203.0.113.7']);

        $this->pinGlobalCache();
    }

    public function test_a_txt_record_alone_proves_ownership_without_serving(): void
    {
        [, $domain] = $this->claimed('app.example.com');

        $this->dns->withTxt($domain->challengeHost(), (string) $domain->verification_token);

        $result = VerifyDomainOwnership::run($domain);

        $this->assertTrue($result->ownershipProven);
        $this->assertFalse($result->pointedHere);
        $this->assertSame(DomainStatus::Verified, $result->status);
        $this->assertSame('dns_not_pointed', $result->reason);
    }

    public function test_a_cname_alone_leaves_ownership_unproven(): void
    {
        [, $domain] = $this->claimed('app.example.com');

        $this->dns->withCname($domain->domain, 'proxy.numerosis.test');

        $result = VerifyDomainOwnership::run($domain);

        $this->assertFalse($result->ownershipProven);
        $this->assertSame('txt_missing', $result->reason);
    }

    public function test_a_wrong_token_is_reported_as_a_mismatch_rather_than_missing(): void
    {
        [, $domain] = $this->claimed('app.example.com');

        $this->dns->withTxt($domain->challengeHost(), 'numerosis-verify-somebodyelse');

        $this->assertSame('txt_mismatch', VerifyDomainOwnership::run($domain)->reason);
    }

    public function test_both_records_make_the_domain_active(): void
    {
        [, $domain] = $this->claimed('app.example.com');

        $this->pointHere($domain);

        $result = VerifyDomainOwnership::run($domain);

        $this->assertTrue($result->pointedHere);
        $this->assertSame(DomainStatus::Active, $result->status);
        $this->assertNull($result->reason);
    }

    public function test_an_a_record_stands_in_for_a_cname_the_apex_cannot_carry(): void
    {
        [, $domain] = $this->claimed('example.com');

        $this->dns->withTxt($domain->challengeHost(), (string) $domain->verification_token)
            ->withAddresses($domain->domain, '203.0.113.7');

        $this->assertTrue(VerifyDomainOwnership::run($domain)->pointedHere);
    }

    public function test_a_domain_another_tenant_holds_never_gets_a_token(): void
    {
        [, $held] = $this->claimed('app.example.com');

        $other = Tenant::factory()->create();

        try {
            ClaimCustomDomain::run($other, 'app.example.com');
        } catch (ValidationException $e) {
            $this->assertSame('This domain is already taken.', $e->validator->errors()->first('customDomain'));
            $this->assertSame(1, Domain::query()->where('domain', 'app.example.com')->count());
            $this->assertNotNull($held->verification_token);

            return;
        }

        $this->fail('Expected the second claim to be refused.');
    }

    public function test_the_token_survives_a_failed_check_and_changes_only_on_request(): void
    {
        [$tenant, $domain] = $this->claimed('app.example.com');

        $token = $domain->verification_token;

        RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));

        $this->assertSame($token, $domain->refresh()->verification_token);

        ClaimCustomDomain::run($tenant, $domain->domain, regenerate: true);

        $this->assertNotSame($token, $domain->refresh()->verification_token);
        $this->assertSame(DomainStatus::Pending, $domain->status);
    }

    public function test_verification_fires_once_per_transition_rather_than_per_check(): void
    {
        Event::fake([DomainVerified::class]);

        [, $domain] = $this->claimed('app.example.com');
        $this->pointHere($domain);

        RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));
        RecordDomainVerification::run($domain->refresh(), VerifyDomainOwnership::run($domain));

        Event::assertDispatchedTimes(DomainVerified::class, 1);
        Event::assertDispatched(fn (DomainVerified $e): bool => $e->domain === 'app.example.com' && $e->pointedHere);
    }

    public function test_a_domain_whose_dns_was_removed_stops_being_served_and_is_announced(): void
    {
        Event::fake([DomainRevoked::class]);

        [, $domain] = $this->claimed('app.example.com');
        $this->pointHere($domain);

        RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));

        $this->assertTrue($domain->refresh()->isServable());
        $this->assertContains('app.example.com', GetServableDomains::run());

        $this->dns = new FakeDnsResolver;
        app()->instance(DnsResolver::class, $this->dns);

        RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain->refresh()));

        $this->assertFalse($domain->refresh()->isServable());
        $this->assertNotContains('app.example.com', GetServableDomains::run());

        Event::assertDispatchedTimes(DomainRevoked::class, 1);
    }

    public function test_a_claim_past_its_window_is_marked_failed(): void
    {
        Config::set('numerosis.tenancy.custom_domains.verification_window_hours', 1);

        [, $domain] = $this->claimed('app.example.com');

        $domain->forceFill(['created_at' => now()->subDays(2)])->save();

        RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));

        $this->assertSame(DomainStatus::Failed, $domain->refresh()->status);
        $this->assertNotNull($domain->verification_failed_at);
    }

    /**
     * @return array{0: BaseTenant, 1: Domain}
     */
    private function claimed(string $hostname): array
    {
        $tenant = Tenant::factory()->create();
        $domain = ClaimCustomDomain::run($tenant, $hostname);

        $this->assertSame(DomainStatus::Pending, $domain->status);

        return [$tenant, $domain];
    }

    private function pointHere(Domain $domain): void
    {
        $this->dns->withTxt($domain->challengeHost(), (string) $domain->verification_token)
            ->withCname($domain->domain, 'proxy.numerosis.test');
    }
}
