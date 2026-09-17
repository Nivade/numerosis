<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Both TLS endpoints are off by default: they are public, and a deployment whose
 * proxy does not ask should expose nothing at all.
 */
class TlsPresentersDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_neither_endpoint_is_registered_by_default(): void
    {
        $this->assertFalse(Config::boolean('numerosis.tenancy.custom_domains.tls.ask'));
        $this->assertFalse(Config::boolean('numerosis.tenancy.custom_domains.tls.routers'));

        $this->get(Config::string('numerosis.tenancy.custom_domains.tls.ask_path').'?domain=live.example.com')
            ->assertNotFound();

        $this->getJson(Config::string('numerosis.tenancy.custom_domains.tls.routers_path'))
            ->assertNotFound();
    }
}
