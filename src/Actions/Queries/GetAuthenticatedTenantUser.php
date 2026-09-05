<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;

/**
 * The authenticated tenant user, or null.
 *
 * Names the tenant guard explicitly, never trusting the ambient default,
 * which any code calling `Auth::shouldUse()` can move mid-request. Use this
 * for anything that writes to a tenant table.
 *
 * @method static ?TenantUser run()
 */
class GetAuthenticatedTenantUser
{
    use AsAction;

    public function handle(): ?TenantUser
    {
        $user = GetAuthenticatedUser::run(Context::Tenant->guard());

        return $user instanceof TenantUser ? $user : null;
    }
}
