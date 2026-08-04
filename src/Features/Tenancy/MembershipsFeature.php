<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Tenant membership UI: the tenant panel's Team cluster / Users resource
 * (`Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Users\UserResource`),
 * where an owner manages who belongs to the tenant. Remove this class from
 * config('numerosis.features') and a consumer that manages membership
 * entirely through their own admin surface (or not at all) no longer sees
 * this resource in the tenant panel.
 *
 * This does not gate InvitationsFeature — inviting someone and managing who
 * is already a member are separate concerns with separate switches, same as
 * PasswordResetFeature staying independent of AccountPagesFeature.
 * Membership rows themselves (Nvade\Numerosis\Models\Central\Membership) are written by
 * provisioning (AddTenantOwner) and invitation-accept regardless of this
 * toggle; this only gates the resource that lists/edits them.
 *
 * bootstrap() is empty, following ModuleSystemFeature's ModulesMarketplace
 * pattern: the resource and its pages check
 * Features::enabled(self::NAME) from their own canAccess() /
 * shouldRegisterNavigation() overrides, not from a route registered here.
 */
class MembershipsFeature implements NamedFeature
{
    public const NAME = 'tenancy.memberships';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
