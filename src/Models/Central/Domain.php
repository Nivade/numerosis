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
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Observers\Tenancy\DomainObserver;
use Stancl\Tenancy\Database\Concerns\InvalidatesTenantsResolverCache;

/**
 * @property string $id
 * @property string $domain
 * @property string $tenant_id
 * @property DomainStatus $status
 * @property string|null $verification_token
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $failing_since
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
            'failing_since' => 'datetime',
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
     * Combines `servable()` with the tenant not being suspended or closed. A
     * hostname still answering DNS for a shut-down workspace must not serve.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function servableAcrossTenants(Builder $query): void
    {
        $tenantTable = (new (Numerosis::model(Tenant::class))())->getTable();

        $query->servable()->whereExists(function ($q) use ($tenantTable): void {
            $q->select('id')
                ->from($tenantTable)
                ->whereColumn($tenantTable.'.id', 'domains.tenant_id')
                ->whereNull($tenantTable.'.suspended_at')
                ->whereNull($tenantTable.'.closed_at');
        });
    }

    /** The ask endpoint runs per new SNI, so this hostname gets its own cache entry. */
    public static function isHostnameServable(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));
        $seconds = Config::integer('numerosis.tenancy.custom_domains.tls.cache_seconds', 60);

        return (bool) GlobalCache::store()->remember(
            CacheKeys::servableDomain($hostname),
            $seconds,
            static fn (): bool => Numerosis::model(self::class)::query()
                ->servableAcrossTenants()
                ->where('domains.domain', $hostname)
                ->exists(),
        );
    }

    /**
     * Ordered by how long ago each was looked at, so a sweep with a limit takes
     * the most overdue rather than whatever the driver returns first. Filters
     * to the base interval, the shortest any domain can be due at; a caller
     * still has to test recheckIntervalMinutes() per row, since a failing
     * domain's real interval can be longer.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function dueForCheck(Builder $query): void
    {
        $baseMinutes = Config::integer('numerosis.tenancy.custom_domains.recheck_minutes', 60);

        // Active rows are re-checked too: a domain whose DNS was pulled has to
        // stop being served, and nothing else would notice.
        $query->where('status', '!=', DomainStatus::Revoked->value)
            ->where(fn (Builder $q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subMinutes($baseMinutes)))
            ->orderByRaw('last_checked_at is not null, last_checked_at asc');
    }

    /**
     * The interval doubles for every recheck_backoff_period_hours a domain has
     * been failing, capped at recheck_backoff_cap_minutes. A domain that has
     * never failed, or has recovered, uses the base interval.
     */
    public function recheckIntervalMinutes(): int
    {
        $base = Config::integer('numerosis.tenancy.custom_domains.recheck_minutes', 60);

        if ($this->failing_since === null) {
            return $base;
        }

        $periodHours = Config::integer('numerosis.tenancy.custom_domains.recheck_backoff_period_hours', 12);
        $capMinutes = Config::integer('numerosis.tenancy.custom_domains.recheck_backoff_cap_minutes', 1440);
        $doublings = intdiv(max(0, (int) $this->failing_since->diffInHours(now())), $periodHours);

        return min($capMinutes, $base * 2 ** $doublings);
    }

    public function isDueForRecheck(): bool
    {
        return $this->last_checked_at === null
            || $this->last_checked_at->lt(now()->subMinutes($this->recheckIntervalMinutes()));
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
