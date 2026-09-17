<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Notifications;

/**
 * `mayDisableMail()` lives on the enum so that every new case has to answer it.
 * A user who has muted everything must still be told their card failed or their
 * password changed.
 */
enum NotificationType: string
{
    case PaymentFailed = 'payment_failed';
    case PaymentConfirmed = 'payment_confirmed';
    case TenantSuspended = 'tenant_suspended';
    case TenantRestored = 'tenant_restored';
    case InvitationReceived = 'invitation_received';
    case OwnershipNominated = 'ownership_nominated';
    case ProvisioningFailed = 'provisioning_failed';
    case DataExportReady = 'data_export_ready';

    /** Transactional or consequential mail nobody may switch off. */
    public function mayDisableMail(): bool
    {
        return match ($this) {
            self::PaymentFailed,
            self::TenantSuspended,
            self::ProvisioningFailed => false,
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PaymentFailed => 'Payment failed',
            self::PaymentConfirmed => 'Payment confirmed',
            self::TenantSuspended => 'Workspace suspended',
            self::TenantRestored => 'Workspace restored',
            self::InvitationReceived => 'Invitation received',
            self::OwnershipNominated => 'Ownership offered to you',
            self::ProvisioningFailed => 'Workspace setup failed',
            self::DataExportReady => 'Data export ready',
        };
    }
}
