<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Numerosis;

/**
 * @method static Collection<int, Domain> run(string $tenantId)
 */
class GetTenantDomains
{
    use AsAction;

    /**
     * @return Collection<int, Domain>
     */
    public function handle(string $tenantId): Collection
    {
        return Numerosis::model(Domain::class)::query()
            ->where('tenant_id', $tenantId)
            ->servable()
            ->orderBy('domain')
            ->get();
    }
}
