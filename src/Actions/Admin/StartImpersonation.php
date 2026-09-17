<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Admin;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\Admin\ImpersonationUnavailable;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Database\Models\ImpersonationToken;

/**
 * The link carries no signature, since the token is already a 128-character
 * single-use secret with its own TTL. Signing it would need a URL built for
 * another host, and path mode has no `URL::defaults(['tenant' => …])` for that.
 *
 * @method static string run(Tenant $tenant, string $targetGlobalId, CentralUser $staff)
 */
class StartImpersonation
{
    use AsAction;

    public function handle(Tenant $tenant, string $targetGlobalId, CentralUser $staff): string
    {
        $targetId = $this->tenantUserId($tenant, $targetGlobalId);

        $token = ImpersonationToken::create([
            'tenant_id' => $tenant->getTenantKey(),
            'user_id' => $targetId,
            'auth_guard' => Context::Tenant->guard(),
            'redirect_url' => $tenant->baseUrl(),
        ]);

        ImpersonationSession::create([
            'token' => $token->token,
            'tenant_id' => (string) $tenant->getTenantKey(),
            'staff_global_id' => $staff->global_id,
            'target_global_id' => $targetGlobalId,
        ]);

        return $tenant->baseUrl().'/impersonate/'.$token->token;
    }

    /**
     * The tenant guard logs in by primary key, and that key only exists inside
     * the tenant's own database.
     *
     * @throws ImpersonationUnavailable when the member has no tenant-side user yet
     */
    private function tenantUserId(Tenant $tenant, string $targetGlobalId): string
    {
        $id = $tenant->runHere(function () use ($targetGlobalId): ?string {
            $user = Numerosis::model(TenantUser::class)::query()
                ->where('global_id', $targetGlobalId)
                ->first();

            return $user instanceof TenantUser ? (string) $user->id : null;
        });

        throw_unless(
            is_string($id),
            ImpersonationUnavailable::class,
            "No tenant user in [{$tenant->getTenantKey()}] carries global id [{$targetGlobalId}]."
        );

        return $id;
    }
}
