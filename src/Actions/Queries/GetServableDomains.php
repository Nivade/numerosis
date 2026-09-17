<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The one verified-domain query every TLS presenter reads. Filters on tenant
 * state as well as domain state: a suspended or closed tenant whose hostname
 * still answers over HTTPS is a data-exposure bug, not a cosmetic one.
 *
 * @method static list<string> run()
 */
class GetServableDomains
{
    use AsAction;

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        $seconds = Config::integer('numerosis.tenancy.custom_domains.tls.cache_seconds', 60);

        /** @var list<string> $domains */
        $domains = GlobalCache::store()->remember(
            CacheKeys::servableDomains(),
            $seconds,
            static fn (): array => self::query(),
        );

        return $domains;
    }

    /** One hostname, answered from its own cache entry: the ask endpoint runs per new SNI. */
    public static function includes(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));
        $seconds = Config::integer('numerosis.tenancy.custom_domains.tls.cache_seconds', 60);

        return (bool) GlobalCache::store()->remember(
            CacheKeys::servableDomain($hostname),
            $seconds,
            static fn (): bool => self::servableQuery()
                ->where('domains.domain', $hostname)
                ->exists(),
        );
    }

    /**
     * @return list<string>
     */
    private static function query(): array
    {
        /** @var list<string> $domains */
        $domains = self::servableQuery()
            ->orderBy('domains.domain')
            ->pluck('domains.domain')
            ->all();

        return $domains;
    }

    /**
     * @return Builder<Domain>
     */
    private static function servableQuery(): Builder
    {
        $tenantTable = (new (Numerosis::model(Tenant::class))())->getTable();

        return Numerosis::model(Domain::class)::query()
            ->servable()
            ->whereExists(function ($query) use ($tenantTable): void {
                $query->select('id')
                    ->from($tenantTable)
                    ->whereColumn($tenantTable.'.id', 'domains.tenant_id')
                    ->whereNull($tenantTable.'.suspended_at')
                    ->whereNull($tenantTable.'.closed_at');
            });
    }
}
