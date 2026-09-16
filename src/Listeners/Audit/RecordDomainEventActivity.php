<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Audit;

use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Invitations\InvitationAccepted;
use Nvade\Numerosis\Events\Tenancy\MemberJoined;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Events\Tenancy\MemberRoleChanged;
use Nvade\Numerosis\Events\Tenancy\TenantClosed;
use Nvade\Numerosis\Events\Tenancy\TenantOwnershipTransferred;
use Nvade\Numerosis\Events\Tenancy\TenantReopened;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * An attribute diff answers what changed; an auditor asks what happened. Each
 * entry is written against the tenant it concerns, so the central log can be
 * read per tenant without joining anything.
 */
class RecordDomainEventActivity
{
    public function handle(object $event): void
    {
        [$description, $properties] = $this->describe($event);

        $tenant = Numerosis::model(Tenant::class)::query()
            ->whereKey($this->tenantId($event))
            ->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        activity()
            ->performedOn($tenant)
            ->withProperties($properties)
            ->log($description);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function describe(object $event): array
    {
        return match (true) {
            $event instanceof MemberJoined => ['Member joined', [
                'global_user_id' => $event->globalUserId,
                'role' => $event->role->value,
                'invited_by' => $event->invitedBy,
            ]],
            $event instanceof MemberRemoved => ['Member removed', [
                'global_user_id' => $event->globalUserId,
                'role' => $event->role->value,
            ]],
            $event instanceof MemberRoleChanged => ['Member role changed', [
                'global_user_id' => $event->globalUserId,
                'from' => $event->from->value,
                'to' => $event->to->value,
            ]],
            $event instanceof InvitationAccepted => ['Invitation accepted', [
                'invitation_id' => $event->invitationId,
                'invited_by_user_id' => $event->invitedByUserId,
                'seconds_unaccepted' => $event->secondsUnaccepted,
            ]],
            $event instanceof TenantOwnershipTransferred => ['Ownership transferred', [
                'from_global_user_id' => $event->fromGlobalUserId,
                'to_global_user_id' => $event->toGlobalUserId,
            ]],
            $event instanceof TenantSuspended => ['Tenant suspended', []],
            $event instanceof TenantRestored => ['Tenant restored', []],
            $event instanceof TenantClosed => ['Tenant closed', [
                'owner_global_id' => $event->ownerGlobalId,
                'closed_at' => $event->closedAt->toIso8601String(),
            ]],
            $event instanceof TenantReopened => ['Tenant reopened', [
                'owner_global_id' => $event->ownerGlobalId,
                'subscription_resumed' => $event->subscriptionResumed,
            ]],
            default => ['Unrecorded event: '.$event::class, []],
        };
    }

    private function tenantId(object $event): ?string
    {
        return match (true) {
            $event instanceof InvitationAccepted => $event->invitation->tenant_id,
            $event instanceof TenantSuspended, $event instanceof TenantRestored => $event->tenantId,
            $event instanceof MemberJoined,
            $event instanceof MemberRemoved,
            $event instanceof MemberRoleChanged,
            $event instanceof TenantOwnershipTransferred,
            $event instanceof TenantClosed,
            $event instanceof TenantReopened => $event->tenantId,
            default => null,
        };
    }
}
