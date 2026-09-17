<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Notifications;

/**
 * Every notification this package sends, with its default channels and whether a
 * user may turn the mail off.
 *
 * `mayDisableMail()` is the spine of the preference design and lives here rather
 * than on the preferences screen: a user who has muted everything must still be
 * told their card failed or their password changed, and a rule kept in the UI is
 * a rule the next notification silently opts out of.
 */
enum NotificationType: string
{
    case PaymentFailed = 'payment_failed';
    case PaymentConfirmed = 'payment_confirmed';
    case TenantSuspended = 'tenant_suspended';
    case TenantRestored = 'tenant_restored';
    case InvitationReceived = 'invitation_received';
    case MemberJoined = 'member_joined';
    case OwnershipNominated = 'ownership_nominated';
    case ProvisioningFailed = 'provisioning_failed';
    case DataExportReady = 'data_export_ready';
    case SecurityAlert = 'security_alert';

    /** Transactional or consequential mail nobody may switch off. */
    public function mayDisableMail(): bool
    {
        return match ($this) {
            self::PaymentFailed,
            self::TenantSuspended,
            self::ProvisioningFailed,
            self::SecurityAlert => false,
            default => true,
        };
    }

    public function mailByDefault(): bool
    {
        return $this !== self::MemberJoined;
    }

    public function databaseByDefault(): bool
    {
        return true;
    }

    /** Types that can burst, and so are worth offering as a digest. */
    public function digestible(): bool
    {
        return $this === self::MemberJoined;
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentFailed => 'Payment failed',
            self::PaymentConfirmed => 'Payment confirmed',
            self::TenantSuspended => 'Workspace suspended',
            self::TenantRestored => 'Workspace restored',
            self::InvitationReceived => 'Invitation received',
            self::MemberJoined => 'Someone joined a workspace',
            self::OwnershipNominated => 'Ownership offered to you',
            self::ProvisioningFailed => 'Workspace setup failed',
            self::DataExportReady => 'Data export ready',
            self::SecurityAlert => 'Security alert',
        };
    }
}
