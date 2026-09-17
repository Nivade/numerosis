<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

/**
 * No domain subfolder: this vocabulary spans auth, tenancy and billing.
 * `url.intended` is Laravel's own key, left out on purpose: naming it here
 * would suggest core owns it.
 */
enum SessionKey: string
{
    case LoginEmail = 'login.email';
    case LoginRemember = 'login.remember';
    case PendingInvitation = 'pending_invitation';
    case TenancySessionTenant = 'tenancy.session_tenant';
    case RegistrationWizardState = 'registration.wizard_state';

    /** The open `impersonation_sessions` row's primary key. */
    case ImpersonationSession = 'impersonation.session';

    /**
     * A dotted sub-key of this one, e.g. `pending_invitation.email`. The
     * only place that nesting convention is spelled out.
     */
    public function nested(string $field): string
    {
        return "{$this->value}.{$field}";
    }
}
