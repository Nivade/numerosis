<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Numerosis;

/**
 * One page of a tenant's members, for the API. The screen reads them all
 * through {@see GetTenantMembers}; an integration cannot be trusted to have a
 * small workspace.
 *
 * @method static LengthAwarePaginator<int, Membership> run(string $tenantId, int $perPage = 50)
 */
class GetTenantMembersPage
{
    use AsAction;

    public const int MAX_PER_PAGE = 200;

    /**
     * @return LengthAwarePaginator<int, Membership>
     */
    public function handle(string $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Membership> $page */
        $page = Numerosis::model(Membership::class)::query()
            ->where('tenant_id', $tenantId)
            ->with('user:id,global_id,name,email')
            ->oldest('joined_at')
            ->orderBy('id')
            ->paginate(max(1, min($perPage, self::MAX_PER_PAGE)));

        return $page;
    }
}
