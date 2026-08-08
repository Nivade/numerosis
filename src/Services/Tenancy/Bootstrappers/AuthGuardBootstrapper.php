<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy\Bootstrappers;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points the default auth guard at the tenant guard for the duration of
 * tenancy, so that auth()->user(), $request->user(), @auth and the Gate all
 * resolve a Tenant\User inside a tenant and a CentralUser outside one without
 * every call site having to name the guard itself.
 */
class AuthGuardBootstrapper implements TenancyBootstrapper
{
    private ?string $guardBeforeTenancy = null;

    public function __construct(
        protected AuthManager $auth,
        protected Repository $config,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->guardBeforeTenancy = $this->auth->getDefaultDriver();

        $this->auth->shouldUse(Context::Tenant->guard());
    }

    public function revert(): void
    {
        $this->auth->shouldUse(
            $this->guardBeforeTenancy ?? Context::Central->guard(),
        );

        $this->guardBeforeTenancy = null;
    }
}
