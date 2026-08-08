<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;

/**
 * See .claude/rules/auth-guards.md.
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
