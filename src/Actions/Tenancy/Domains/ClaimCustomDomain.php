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
 * Uniqueness is asserted before a token exists, so a hostname another tenant
 * already holds never gets one minted for it. A token for a hostname that can
 * never be claimed sends a customer after instructions to nowhere.
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
            'last_checked_at' => null,
        ])->save();

        return $domain;
    }

    private static function token(): string
    {
        return 'numerosis-verify-'.Str::lower(Str::random(32));
    }
}
