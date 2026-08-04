<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * This product's marketing content pages: terms, privacy, about, features.
 * None of this is framework — a consumer building on this codebase as a
 * package almost certainly wants their own marketing pages, not these.
 * Remove this class from config('numerosis.features') and none of the four
 * routes register.
 *
 * Deliberately does NOT cover 'home' ('/'), despite the plan this class
 * implements originally listing it here too.
 * Nvade\Numerosis\Http\Controllers\Socialite\Login::tenantDashboardUrl() builds an OAuth
 * tenant-redirect URL by swapping that route's host (see its own
 * docblock), CompleteRedirectCheckout falls back to it on a checkout error,
 * and TenantAdminPanelProvider::register() documents 'home' as the one
 * route name guaranteed to exist on the central domain regardless of panel
 * registration — none of those are marketing concerns, and all three would
 * break if 'home' stopped existing. routes/web.php registers it
 * unconditionally instead. Every call site that resolves the name still
 * reads Nvade\Numerosis\Support\Routes\RouteNames::home() rather than a literal string,
 * so the route can be renamed in config without hunting call sites — that
 * part of Phase 8 in .claude/plans/opt-in-feature-classes.md still applies,
 * it just isn't paired with a toggle.
 *
 * bootstrap() is empty — routes/web.php asks Features::enabled() at
 * registration time, which is boot time; nothing left to do here.
 */
class MarketingPagesFeature implements NamedFeature
{
    public const NAME = 'ui.marketing';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
