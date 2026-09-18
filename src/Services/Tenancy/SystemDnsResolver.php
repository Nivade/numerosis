<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\DnsResolver;

/**
 * PHP's own resolver, which answers from whatever the host's stub resolver
 * decides, so a record added seconds ago may not be visible yet. That is why
 * a failed check reads as "not yet" instead of "no", and why this sits behind
 * a contract a host can replace with an authoritative lookup.
 */
class SystemDnsResolver implements DnsResolver
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        $values = [];

        foreach ($this->records($host, DNS_TXT) as $record) {
            $entries = $record['entries'] ?? null;
            $txt = $record['txt'] ?? null;

            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (is_string($entry)) {
                        $values[] = trim($entry, '"');
                    }
                }

                continue;
            }

            if (is_string($txt)) {
                $values[] = trim($txt, '"');
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function cname(string $host): array
    {
        $targets = [];

        foreach ($this->records($host, DNS_CNAME) as $record) {
            $target = $record['target'] ?? null;

            if (is_string($target)) {
                $targets[] = self::normalize($target);
            }
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $addresses = [];

        foreach ($this->records($host, DNS_A | DNS_AAAA) as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                $value = $record[$key] ?? null;

                if (is_string($value)) {
                    $addresses[] = $value;
                }
            }
        }

        return $addresses;
    }

    /**
     * `dns_get_record()` emits a warning and returns false for NXDOMAIN, which
     * is a normal answer here instead of a fault.
     *
     * @return list<array<string, mixed>>
     */
    private function records(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);

        return $records === false ? [] : $records;
    }

    private static function normalize(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }
}
