<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * This product's account UI: the settings group (profile/password/appearance),
 * /tenants/mine, /billing-portal, /user/invoice/{invoice}. Like
 * MarketingPagesFeature, this is product surface a package consumer is
 * expected to replace, not framework.
 *
 * settings/password nests under PasswordResetFeature independently — both
 * must be enabled for that one route to register. tenants.create
 * (RegistrationWizardFeature) and the checkout routes are deliberately
 * outside this feature; they have their own switch or none at all.
 *
 * 'tenants.mine' has ~10 external callers, all of which now read
 * Nvade\Numerosis\Support\Routes\RouteNames::tenantsMine() rather than the literal
 * string — see that class's docblock and Phase 8 in
 * .claude/plans/opt-in-feature-classes.md.
 */
class AccountPagesFeature implements NamedFeature
{
    public const NAME = 'ui.account';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
