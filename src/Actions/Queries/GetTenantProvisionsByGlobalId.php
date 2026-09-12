<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

/**
 * @method static Collection<string, TenantProvision> run(string $globalId)
 */
class GetTenantProvisionsByGlobalId
{
    use AsAction;

    /**
     * @return Collection<string, TenantProvision>
     */
    public function handle(string $globalId): Collection
    {
        return Numerosis::model(TenantProvision::class)::query()
            ->where('global_id', $globalId)
            ->get()
            ->keyBy('slug');
    }
}
