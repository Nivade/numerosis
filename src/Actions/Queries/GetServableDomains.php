<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Models\Central\Domain;
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
            static fn (): array => Numerosis::model(Domain::class)::query()
                ->servableAcrossTenants()
                ->orderBy('domains.domain')
                ->pluck('domains.domain')
                ->all(),
        );

        return $domains;
    }
}
