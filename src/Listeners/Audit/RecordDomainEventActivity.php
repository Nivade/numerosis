<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Audit;

use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Admin\ImpersonationEnded;
use Nvade\Numerosis\Events\Admin\ImpersonationStarted;
use Nvade\Numerosis\Events\Auth\SuspiciousLoginDetected;
use Nvade\Numerosis\Events\Auth\TwoFactorAuthenticationCleared;
use Nvade\Numerosis\Events\Auth\UserAnonymized;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Billing\UsageDivergenceDetected;
use Nvade\Numerosis\Events\Invitations\InvitationAccepted;
use Nvade\Numerosis\Events\Tenancy\DomainRevoked;
use Nvade\Numerosis\Events\Tenancy\DomainVerified;
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
 * An attribute diff answers what changed; an auditor asks what happened. An
 * entry naming a tenant is written against it, so the central log can be read
 * per tenant without joining anything.
 */
class RecordDomainEventActivity
{
    public function handle(object $event): void
    {
        ['description' => $description, 'properties' => $properties, 'tenant' => $tenantId, 'causer' => $causer] = $this->describe($event);

        $activity = activity();

        $tenant = $tenantId === null ? null : Numerosis::model(Tenant::class)::query()->whereKey($tenantId)->first();

        if ($tenant instanceof Tenant) {
            $activity->performedOn($tenant);
        }

        if ($causer !== null) {
            $activity->causedBy(FindUserByGlobalId::run($causer, Context::Central));
        }

        $activity->withProperties($properties)->log($description);
    }

    /**
     * @return array{description: string, properties: array<string, mixed>, tenant: string|null, causer: string|null}
     */
    private function describe(object $event): array
    {
        return match (true) {
            $event instanceof MemberJoined => $this->entry('Member joined', [
                'global_user_id' => $event->globalUserId,
                'role' => $event->role->value,
                'invited_by' => $event->invitedBy,
            ], $event->tenantId),
            $event instanceof MemberRemoved => $this->entry('Member removed', [
                'global_user_id' => $event->globalUserId,
                'role' => $event->role->value,
            ], $event->tenantId),
            $event instanceof MemberRoleChanged => $this->entry('Member role changed', [
                'global_user_id' => $event->globalUserId,
                'from' => $event->from->value,
                'to' => $event->to->value,
            ], $event->tenantId),
            $event instanceof InvitationAccepted => $this->entry('Invitation accepted', [
                'invitation_id' => $event->invitationId,
                'invited_by_user_id' => $event->invitedByUserId,
                'seconds_unaccepted' => $event->secondsUnaccepted,
            ], $event->invitation->tenant_id),
            $event instanceof TenantOwnershipTransferred => $this->entry('Ownership transferred', [
                'from_global_user_id' => $event->fromGlobalUserId,
                'to_global_user_id' => $event->toGlobalUserId,
            ], $event->tenantId),
            $event instanceof TenantSuspended => $this->entry('Tenant suspended', [], $event->tenantId),
            $event instanceof TenantRestored => $this->entry('Tenant restored', [], $event->tenantId),
            $event instanceof TenantClosed => $this->entry('Tenant closed', [
                'owner_global_id' => $event->ownerGlobalId,
                'closed_at' => $event->closedAt->toIso8601String(),
            ], $event->tenantId),
            $event instanceof TenantReopened => $this->entry('Tenant reopened', [
                'owner_global_id' => $event->ownerGlobalId,
                'subscription_resumed' => $event->subscriptionResumed,
            ], $event->tenantId),
            $event instanceof ImpersonationStarted => $this->entry(
                "Impersonation of {$event->targetGlobalId} in {$event->tenantId} started by staff",
                [
                    'tenant_id' => $event->tenantId,
                    'impersonation_session_id' => $event->sessionId,
                    'target_global_id' => $event->targetGlobalId,
                ],
                $event->tenantId,
                $event->staffGlobalId,
            ),
            $event instanceof ImpersonationEnded => $this->entry(
                "Impersonation of {$event->targetGlobalId} in {$event->tenantId} ended ({$event->reason->value})",
                [
                    'tenant_id' => $event->tenantId,
                    'impersonation_session_id' => $event->sessionId,
                    'target_global_id' => $event->targetGlobalId,
                    'reason' => $event->reason->value,
                ],
                $event->tenantId,
                $event->staffGlobalId,
            ),
            $event instanceof TwoFactorAuthenticationCleared => $this->entry(
                "Two-factor authentication for {$event->targetGlobalId} cleared by staff {$event->staffGlobalId}",
                [
                    'staff_global_id' => $event->staffGlobalId,
                    'target_global_id' => $event->targetGlobalId,
                ],
                causer: $event->staffGlobalId,
            ),
            $event instanceof SuspiciousLoginDetected => $this->entry('Suspicious login detected', [
                'email' => $event->email,
                'ip' => $event->ip,
            ], $event->tenantKey),
            $event instanceof UserAnonymized => $this->entry('User anonymized', [
                'global_id' => $event->globalId,
                'tenant_ids' => $event->tenantIds,
            ]),
            $event instanceof DomainVerified => $this->entry('Domain verified', [
                'domain' => $event->domain,
                'pointed_here' => $event->pointedHere,
            ], $event->tenantId),
            $event instanceof DomainRevoked => $this->entry('Domain revoked', [
                'domain' => $event->domain,
                'reason' => $event->reason,
            ], $event->tenantId),
            $event instanceof UsageDivergenceDetected => $this->entry('Usage diverged from Stripe', [
                'event_name' => $event->eventName,
                'period_start' => $event->periodStart,
                'local_total' => $event->localTotal,
                'stripe_total' => $event->stripeTotal,
            ], $event->tenantId),
            default => $this->entry('Unrecorded event: '.$event::class, []),
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{description: string, properties: array<string, mixed>, tenant: string|null, causer: string|null}
     */
    private function entry(string $description, array $properties, ?string $tenant = null, ?string $causer = null): array
    {
        return ['description' => $description, 'properties' => $properties, 'tenant' => $tenant, 'causer' => $causer];
    }
}
