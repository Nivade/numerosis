<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Auth\TenantUserModel;
use Nvade\Numerosis\Models\User;
use Illuminate\Support\Facades\Config;

class UserModelResolver
{
    /**
     * @return class-string<User&CentralUserModel>
     */
    public static function centralUserModel(): string
    {
        /** @var class-string<User&CentralUserModel> */
        return Config::string('tenancy.central_user_model');
    }

    /**
     * @return class-string<User&TenantUserModel>
     */
    public static function tenantUserModel(): string
    {
        /** @var class-string<User&TenantUserModel> */
        return Config::string('tenancy.tenant_user_model');
    }
}
