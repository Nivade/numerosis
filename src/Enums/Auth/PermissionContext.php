<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

enum PermissionContext: string
{
    case Features = 'features';
    case Permissions = 'permissions';
    case Roles = 'roles';
    case Tenants = 'tenants';
    case Subscriptions = 'subscriptions';
    case PaymentPlans = 'payment_plans';
    case Users = 'users';
    case Invitations = 'invitations';
}
