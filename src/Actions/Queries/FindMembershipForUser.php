<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Membership;

/**
 * @method static ?Membership run(string $tenantId, ?string $globalUserId)
 */
class FindMembershipForUser
{
    use AsAction;

    public function handle(string $tenantId, ?string $globalUserId): ?Membership
    {
        if ($globalUserId === null) {
            return null;
        }

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('global_user_id', $globalUserId)
            ->first();
    }
}
