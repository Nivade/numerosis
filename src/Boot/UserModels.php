<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Auth\TenantUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;

/**
 * The two user models a host configures, read back by context. `HostConfig`
 * writes these keys; this is the only thing that reads them.
 */
class UserModels
{
    /**
     * @return class-string<User&CentralUserModel>
     */
    public static function central(): string
    {
        /** @var class-string<User&CentralUserModel> */
        return Config::string('tenancy.central_user_model');
    }

    /**
     * @return class-string<User&TenantUserModel>
     */
    public static function tenant(): string
    {
        /** @var class-string<User&TenantUserModel> */
        return Config::string('tenancy.tenant_user_model');
    }

    /**
     * @return class-string<User>
     */
    public static function for(Context $context): string
    {
        return $context === Context::Tenant ? self::tenant() : self::central();
    }

    /**
     * @return class-string<User>
     */
    public static function current(): string
    {
        return self::for(tenancy()->initialized ? Context::Tenant : Context::Central);
    }
}
