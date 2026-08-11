<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\Tenancy\TenantHasNoOwner;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Stancl\Tenancy\Database\Models\ImpersonationToken;

/**
 * Opens a session as the tenant's owner, for support.
 *
 * Resolves the owner's tenant-side row inside `$tenant->run()` — a plain
 * read with nothing that can throw mid-callback, same category
 * `AddTenantOwner` already runs this way. Writes the `ImpersonationToken`
 * directly rather than through stancl's `tenancy()->impersonate()` macro —
 * the macro is exactly this one `create()` call
 * (`Stancl\Tenancy\Features\UserImpersonation::bootstrap()`), and calling it
 * directly keeps this method's return type checkable; the macro only exists
 * for callers who don't want the `ImpersonationToken` class name in their
 * `use` block. {@see \Nvade\Numerosis\Features\Tenancy\ImpersonationFeature}
 * still registers the feature with stancl, since `UserImpersonation::
 * makeResponse()` (the login-consuming half, reached from
 * `routes/tenant.php`) is stancl's own static method, not reimplemented
 * here. The returned redirect sends the browser to the tenant's own
 * `impersonate/{token}` route, which is what actually logs the session in —
 * this action only ever prepares that.
 *
 * @method static RedirectResponse run(Tenant $tenant)
 */
class ImpersonateTenantUser
{
    use AsAction;

    public function handle(Tenant $tenant): RedirectResponse
    {
        $owner = $tenant->owner();

        throw_unless($owner, TenantHasNoOwner::class, "Tenant [{$tenant->id}] has no owner to impersonate.");

        $tenantUserClass = Numerosis::model(TenantUser::class);

        $tenantUserId = $tenant->run(
            fn () => $tenantUserClass::where('global_id', $owner->global_id)->value('id')
        );

        if (! is_int($tenantUserId) && ! is_string($tenantUserId)) {
            throw new TenantHasNoOwner("Tenant [{$tenant->id}]'s owner has no matching tenant-side user row.");
        }

        $domain = $tenant->primaryDomain()?->getHost() ?? (string) $tenant->id;

        $token = ImpersonationToken::create([
            'tenant_id' => $tenant->getTenantKey(),
            'user_id' => (string) $tenantUserId,
            'redirect_url' => tenant_route($domain, RouteNames::home()),
            'auth_guard' => Context::Tenant->guard(),
        ]);

        return redirect()->to(tenant_route($domain, 'impersonate', ['token' => $token->token]));
    }
}
