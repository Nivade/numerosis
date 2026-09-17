<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Observers\Tenancy\DomainObserver;
use Stancl\Tenancy\Database\Concerns\InvalidatesTenantsResolverCache;

/**
 * @property string $id
 * @property string $domain
 * @property string $tenant_id
 * @property DomainStatus $status
 * @property string|null $verification_token
 * @property Carbon|null $last_checked_at
 * @property-read string $url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[ObservedBy(DomainObserver::class)]
#[DateFormat('Y-m-d H:i:s.u')]
class Domain extends \Stancl\Tenancy\Database\Models\Domain
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * Invalidates DomainTenantResolver's cache (see
     * TenancyServiceProvider::register()) when this domain is created,
     * renamed, or deleted.
     */
    use InvalidatesTenantsResolverCache;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    /** The TXT record's host, which is where the token has to appear. */
    public function challengeHost(): string
    {
        return Config::string('numerosis.tenancy.custom_domains.challenge_prefix', '_numerosis-challenge').'.'.$this->domain;
    }

    public function isServable(): bool
    {
        return $this->status->isServable();
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function servable(Builder $query): void
    {
        $query->whereIn('status', [DomainStatus::Verified->value, DomainStatus::Active->value]);
    }

    /**
     * Ordered by how long ago each was looked at, so a sweep with a limit takes
     * the most overdue rather than whatever the driver returns first.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function dueForCheck(Builder $query): void
    {
        // Active rows are re-checked too: a domain whose DNS was pulled has to
        // stop being served, and nothing else would notice.
        $query->where('status', '!=', DomainStatus::Revoked->value)
            ->orderByRaw('last_checked_at is not null, last_checked_at asc');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Request::getScheme().'://'.$this->domain
        );
    }

    /**
     * Only ever reconstructs `{id}.{apex}` under subdomain mode, where
     * `domain` is exactly that concatenation and `getHost()` predates having
     * a stored value to just return. Every other mode's `domain` column
     * already holds the real host.
     *
     * @see \Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain
     */
    public function getHost(): string
    {
        return IdentificationMode::current() === IdentificationMode::Subdomain
            ? $this->id.'.'.Config::string('numerosis.domains.apex')
            : $this->domain;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function default(Builder $query): void
    {
        $query->limit(1);
    }
}
