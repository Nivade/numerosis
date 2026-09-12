<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Numerosis;

/**
 * @method static Collection<int, Invitation> run(string $tenantId)
 */
class GetPendingInvitationsForTenant
{
    use AsAction;

    /**
     * @return Collection<int, Invitation>
     */
    public function handle(string $tenantId): Collection
    {
        // invitedBy is eager loaded for InvitationPolicy::delete()'s ownership
        // fallback, which is a per-row exists() query on the non-admin path.
        return Numerosis::model(Invitation::class)::query()
            ->pending()
            ->where('tenant_id', $tenantId)
            ->with('invitedBy:id,global_id')
            ->latest()
            ->get();
    }
}
