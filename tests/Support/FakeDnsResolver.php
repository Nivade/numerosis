<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\DnsResolver;

/**
 * DNS as a test decides it, one zone at a time. Nothing here touches the
 * network, which is the point of the contract.
 */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $txt = [];

    /** @var array<string, list<string>> */
    private array $cname = [];

    /** @var array<string, list<string>> */
    private array $addresses = [];

    public function withTxt(string $host, string ...$values): self
    {
        $this->txt[strtolower($host)] = array_values($values);

        return $this;
    }

    public function withCname(string $host, string ...$targets): self
    {
        $this->cname[strtolower($host)] = array_values(array_map(strtolower(...), $targets));

        return $this;
    }

    public function withAddresses(string $host, string ...$addresses): self
    {
        $this->addresses[strtolower($host)] = array_values($addresses);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        return $this->txt[strtolower($host)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function cname(string $host): array
    {
        return $this->cname[strtolower($host)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        return $this->addresses[strtolower($host)] ?? [];
    }
}
