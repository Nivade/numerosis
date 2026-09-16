<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Nvade\Numerosis\Contracts\Auth\SessionRegistry;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Numerosis;

/**
 * Strips one tenant from the removed member's sessions. The whole row must
 * survive: the same session authenticates their central account and every
 * other tenant they belong to, and deleting it logs them out of all of them.
 */
class EndSessionsForRemovedMember
{
    public function __construct(private readonly SessionRegistry $registry) {}

    public function handle(MemberRemoved $event): void
    {
        /** @var class-string<CentralUser> $model */
        $model = Numerosis::model(CentralUser::class);

        $userId = $model::query()->where('global_id', $event->globalUserId)->value('id');

        if (! is_int($userId) && ! is_string($userId)) {
            return;
        }

        $this->registry->forgetTenantAccess(Context::Central->guard(), $userId, $event->tenantId);
    }
}
