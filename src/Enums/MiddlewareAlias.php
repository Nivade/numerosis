<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

/**
 * The alias each entry in `MiddlewareRegistrar::aliases()` registers under.
 * An enum case reference resolves without the container, so this stays safe
 * inside `aliases()`'s literals-only constraint. See that class's docblock.
 */
enum MiddlewareAlias: string
{
    case TenancyAuth = 'tenancy.auth';
    case PasswordConfirmIfSet = 'password.confirm.if-set';
    case TenancySubscription = 'tenancy.subscription';
    case TenancyIdentification = 'tenancy.identification';
    case TenancyRoute = 'tenancy.route';
    case TenancySession = 'tenancy.session';
    case TenancyMembership = 'tenancy.membership';
    case TenancyTwoFactor = 'tenancy.two_factor';
    case Impersonation = 'numerosis.impersonation';
    case Entitlement = 'numerosis.entitlement';
    case ApiToken = 'numerosis.api-token';
    case ApiAbilities = 'numerosis.api-abilities';
}
