<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Support;

use Nvade\Numerosis\Support\Domains;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `config/numerosis.php`'s `domains` block calls these while the config
 * repository is still being built, so a throw here takes the application down
 * before any error handler exists. Every case below therefore asserts a
 * *value*, never an exception — including the degenerate inputs.
 *
 * These replaced `app.domain` / `app.host` / `app.central.*`, five keys this
 * package used to require inside Laravel's own config/app.php, which could
 * carry no package default at all.
 */
class DomainsTest extends TestCase
{
    /**
     * @var array{APP_URL: string|false}
     */
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = ['APP_URL' => getenv('APP_URL')];
    }

    protected function tearDown(): void
    {
        $original = $this->originalEnv['APP_URL'];

        if ($original === false) {
            putenv('APP_URL');
            unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        } else {
            $this->setAppUrl($original);
        }

        parent::tearDown();
    }

    public function test_it_reads_the_host_of_app_url_verbatim(): void
    {
        $this->setAppUrl('https://app.example.com');

        $this->assertSame('app.example.com', Domains::hostFromAppUrl());
    }

    public function test_it_strips_the_central_subdomain_to_find_the_apex(): void
    {
        $this->setAppUrl('https://app.example.com');

        $this->assertSame('example.com', Domains::apexFromAppUrl());
    }

    public function test_a_two_label_host_is_already_the_apex(): void
    {
        $this->setAppUrl('https://example.com');

        $this->assertSame('example.com', Domains::apexFromAppUrl());
    }

    /**
     * The documented limit of the heuristic, asserted so it is a known
     * property rather than a surprise: an apex-served two-part public suffix
     * is reduced one label too far, and such a host must set
     * NUMEROSIS_APEX_DOMAIN explicitly.
     */
    public function test_it_over_strips_an_apex_served_two_part_public_suffix(): void
    {
        $this->setAppUrl('https://example.co.uk');

        $this->assertSame('co.uk', Domains::apexFromAppUrl());
    }

    public function test_localhost_is_never_subdivided(): void
    {
        $this->setAppUrl('http://localhost');

        $this->assertSame('localhost', Domains::hostFromAppUrl());
        $this->assertSame('localhost', Domains::apexFromAppUrl());
    }

    /**
     * Dropping the first label of `127.0.0.1` yields `0.0.1`, which is worse
     * than useless because it still looks like a domain.
     */
    public function test_an_ip_address_is_never_subdivided(): void
    {
        $this->setAppUrl('http://127.0.0.1:8000');

        $this->assertSame('127.0.0.1', Domains::apexFromAppUrl());
    }

    public function test_an_unset_or_unparseable_app_url_yields_localhost_rather_than_throwing(): void
    {
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

        $this->assertSame('localhost', Domains::hostFromAppUrl());

        $this->setAppUrl('not a url');

        $this->assertSame('localhost', Domains::hostFromAppUrl());
        $this->assertSame('localhost', Domains::apexFromAppUrl());
    }

    /**
     * `env()` reads through `$_ENV`/`$_SERVER` as well as `getenv()`, and
     * Testbench populates all three — setting only one leaves the others
     * answering with the old value.
     */
    private function setAppUrl(string $url): void
    {
        putenv("APP_URL={$url}");
        $_ENV['APP_URL'] = $url;
        $_SERVER['APP_URL'] = $url;
    }
}
