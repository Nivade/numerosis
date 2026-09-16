<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `SecurityHeaders` is appended to the `web` group, which the `tenant` group
 * nests, so both sides of tenancy are one registration. A middleware test that
 * only instantiates the class would stay green with nothing applying it to a
 * route — the reason these assert against real responses.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_central_response_carries_the_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    /**
     * Without `includeSubDomains` every tenant host in subdomain
     * identification mode is silently exempt from HSTS.
     */
    public function test_strict_transport_security_covers_subdomains(): void
    {
        $this->get('/')->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_a_tenant_response_carries_the_headers(): void
    {
        $id = 'headers'.substr(uniqid(), -8);

        $this->createTenantWithDomain($id, 'Headers Tenant');

        $this->get('http://'.$this->tenantDomain($id).'/')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Stripe's webhook answers `text/html`, so the exempt list is what keeps a
     * CSP off it rather than a content-type check.
     */
    public function test_the_stripe_webhook_gains_no_headers(): void
    {
        $response = $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            ['type' => 'invoice.created', 'data' => ['object' => []]],
        );

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_policy_ships_report_only_with_the_third_party_origins(): void
    {
        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('https://js.stripe.com', $policy);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $policy);
        $this->assertStringContainsString('https://fonts.bunny.net', $policy);

        // Livewire injects an inline script and Alpine evaluates expressions.
        $this->assertStringContainsString("'unsafe-eval'", $policy);
    }

    public function test_report_only_can_be_turned_into_an_enforcing_policy(): void
    {
        Config::set('numerosis.security.headers.content_security_policy.report_only', false);

        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_flag_disables_every_header(): void
    {
        Config::set('numerosis.security.headers.enabled', false);

        $this->get('/')->assertHeaderMissing('X-Content-Type-Options');
    }
}
