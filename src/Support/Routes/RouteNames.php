<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Routes;

use Illuminate\Support\Facades\Config;

/**
 * The route names the package links to from outside the route files that
 * declare them; rename one through `numerosis.routes.names.*` and every caller
 * follows. Only routes whose feature can be switched off, or that a host is
 * likely to want to own, are indirected this way. Every other route is
 * referenced by its literal name.
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

    public static function invitationAccept(): string
    {
        return Config::string('numerosis.routes.names.invitation_accept');
    }

    public static function checkoutSubscription(): string
    {
        return Config::string('numerosis.routes.names.checkout_subscription');
    }
}
