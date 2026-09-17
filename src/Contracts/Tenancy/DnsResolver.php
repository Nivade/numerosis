<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * The one seam between domain verification and the network. Swap it for a
 * resolver that bypasses a local cache, or for one that asks an authoritative
 * server directly; tests bind a fake and never touch DNS.
 */
interface DnsResolver
{
    /**
     * Every TXT value at the host, one entry per record.
     *
     * @return list<string>
     */
    public function txt(string $host): array;

    /**
     * The hostnames a CNAME chain at this host points at, lowercased and
     * without the trailing dot.
     *
     * @return list<string>
     */
    public function cname(string $host): array;

    /**
     * The IPv4 and IPv6 addresses the host resolves to.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
