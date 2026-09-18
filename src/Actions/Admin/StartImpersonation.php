<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
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

        return $this->sign($tenant->baseUrl().'/impersonate/'.$token->token);
    }

    /**
     * Signed by hand instead of `URL::temporarySignedRoute()`. The link is
     * minted on the central domain and spent on the tenant's, and path mode has
     * no `URL::defaults(['tenant' => …])` to build that URL from a route name.
     */
    private function sign(string $url): string
    {
        $expires = CarbonImmutable::now()
            ->addSeconds(Config::integer('numerosis.tenancy.impersonation.token_seconds', 60))
            ->getTimestamp();

        $signable = $url.'?expires='.$expires;

        return $signable.'&signature='.hash_hmac('sha256', $signable, $this->signingKey());
    }

    /** The primary of the keys `Request::hasValidSignature()` verifies against. */
    private function signingKey(): string
    {
        return Config::string('app.key');
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
