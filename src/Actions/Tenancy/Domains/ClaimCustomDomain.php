<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy\Domains;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Claims a hostname for a tenant, unverified. Uniqueness is asserted before a
 * token exists, so a domain another tenant already holds never gets one minted
 * for it — a token handed out for a hostname that can never be claimed is a
 * customer following instructions to nowhere.
 *
 * The token is stable across retries; `regenerate: true` is the explicit
 * request that replaces it.
 *
 * @method static Domain run(Tenant $tenant, string $hostname, bool $regenerate = false)
 */
class ClaimCustomDomain
{
    use AsAction;

    public function __construct(private readonly TenantDomainPolicy $domains) {}

    public function handle(Tenant $tenant, string $hostname, bool $regenerate = false): Domain
    {
        $hostname = strtolower(trim($hostname));
        $domainClass = Numerosis::model(Domain::class);

        /** @var Domain|null $existing */
        $existing = $domainClass::query()->where('domain', $hostname)->first();

        if ($existing instanceof Domain && $existing->tenant_id === (string) $tenant->getTenantKey()) {
            return $regenerate ? $this->reissue($existing) : $existing;
        }

        $this->domains->assertCustomDomainAvailable($hostname);

        /** @var Domain $domain */
        $domain = $domainClass::query()->create([
            'id' => (string) Str::uuid(),
            'domain' => $hostname,
            'tenant_id' => (string) $tenant->getTenantKey(),
            'status' => DomainStatus::Pending,
            'verification_token' => self::token(),
        ]);

        return $domain;
    }

    private function reissue(Domain $domain): Domain
    {
        $domain->forceFill([
            'verification_token' => self::token(),
            'status' => DomainStatus::Pending,
            'verified_at' => null,
            'verification_failed_at' => null,
            'last_checked_at' => null,
        ])->save();

        return $domain;
    }

    private static function token(): string
    {
        return 'numerosis-verify-'.Str::lower(Str::random(32));
    }
}
