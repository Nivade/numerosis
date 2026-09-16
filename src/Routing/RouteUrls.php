<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Routing;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

/**
 * URLs built as strings for the two cases `route()` cannot serve: a link from
 * a tenant host back to the central one, and any tenant-group route name under
 * the path identification mode, where the group is prefixed `{tenant}` and
 * nothing registers a default for that parameter.
 */
final class RouteUrls
{
    public static function central(string $path = ''): string
    {
        return Request::getScheme().'://'
            .Config::string('numerosis.domains.central')
            .'/'.ltrim($path, '/');
    }

    public static function staffTenantDetail(string $tenantId): string
    {
        return self::central(
            trim(Config::string('numerosis.routes.staff_prefix', 'staff'), '/').'/tenants/'.$tenantId
        );
    }
}
