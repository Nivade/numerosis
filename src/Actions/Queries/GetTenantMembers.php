<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Numerosis;

/**
 * @method static Collection<int, Membership> run(string $tenantId)
 */
class GetTenantMembers
{
    use AsAction;

    /**
     * @return Collection<int, Membership>
     */
    public function handle(string $tenantId): Collection
    {
        /** @var Collection<int, Membership> $members */
        $members = Numerosis::model(Membership::class)::query()
            ->where('tenant_id', $tenantId)
            ->with(['user:id,global_id,name,email', 'inviter:id,global_id,name'])
            ->oldest('joined_at')
            ->orderBy('id')
            ->get();

        return $members;
    }
}
