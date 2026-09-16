<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Tenancy;

use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Actions\Tenancy\SyncTenantUserForMembership;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Tenancy\MemberJoined;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Events\Tenancy\MemberRoleChanged;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;

class MembershipObserver
{
    use ForgetsCacheKey;

    /** Ownership moves on an update, not only on create, so `saved` is the hook. */
    public function saved(Membership $membership): void
    {
        $this->forgetMembershipCaches($membership);
    }

    public function created(Membership $membership): void
    {
        // Deferred on the membership's own connection, never
        // `DB::afterCommit()`, which reads the default one and would fire
        // immediately while the central transaction can still roll back.
        $membership->getConnection()->afterCommit(function () use ($membership): void {
            SyncTenantUserForMembership::run($membership);
        });

        // An attach() with no pivot attributes leaves `role` null on the
        // in-memory model, even though the column defaults to 'member'.
        // Refresh so MemberJoined carries what was actually written.
        $membership->refresh();

        event(new MemberJoined(
            $membership->tenant_id,
            $membership->global_user_id,
            $membership->role,
            $membership->invited_by,
        ));
    }

    public function updated(Membership $membership): void
    {
        if (! $membership->wasChanged('role')) {
            return;
        }

        // Cast, so the original is the enum; a row written before the cast
        // existed would not be.
        $from = $membership->getOriginal('role');

        if (! $from instanceof MembershipRole) {
            return;
        }

        event(new MemberRoleChanged(
            $membership->tenant_id,
            $membership->global_user_id,
            $from,
            $membership->role,
        ));
    }

    public function deleted(Membership $membership): void
    {
        $this->forgetMembershipCaches($membership);

        event(new MemberRemoved(
            $membership->tenant_id,
            $membership->global_user_id,
            $membership->role,
        ));
    }

    private function forgetMembershipCaches(Membership $membership): void
    {
        ForgetUserTenants::run($membership->global_user_id);
        $this->forgetCache(CacheKeys::tenantOwnerGlobalId($membership->tenant_id));
    }
}
