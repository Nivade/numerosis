<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Routes;

use Illuminate\Support\Facades\Config;

/**
 * Single source of truth for the two route names that are both (a) called
 * from outside their own route file and (b) candidates for feature gating
 * (Phase 8, .claude/plans/opt-in-feature-classes.md). A disabled feature
 * that no longer registers 'home' or 'tenants.mine' would turn every one of
 * these call sites into a RouteNotFoundException if they kept the literal
 * string — this class is what lets the route's own registration and every
 * caller agree on the name without hand-syncing ~20 call sites.
 *
 * Scoped deliberately narrow: this is NOT the full route-name indirection
 * package-extraction.md's Phase 1.3 describes (~84 call sites, every route
 * in the app). Only the two names Phase 8 actually gates are here.
 */
final class RouteNames
{
    public static function home(): string
    {
        return Config::string('numerosis.routes.names.home');
    }

    public static function tenantsMine(): string
    {
        return Config::string('numerosis.routes.names.tenants_mine');
    }

    public static function invitationShow(): string
    {
        return Config::string('numerosis.routes.names.invitation_show');
    }

    public static function checkoutSubscription(): string
    {
        return Config::string('numerosis.routes.names.checkout_subscription');
    }
}
