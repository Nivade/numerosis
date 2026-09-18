<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1\Concerns;

use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

/**
 * Every endpoint reads the tenant from tenancy, never from the request. A token
 * is issued inside one workspace and its identification middleware has already
 * decided which; accepting an id from the caller is how tenant A reads tenant
 * B.
 */
trait ResolvesApiTenant
{
    protected function apiTenant(): Tenant
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    /**
     * The token's abilities say what it was minted to do; this says what the
     * person holding it may still do. A role narrowed after the token was
     * issued has to narrow the token with it, which an ability list frozen at
     * issue time cannot express.
     */
    protected function authorizeApi(string $ability): void
    {
        $user = request()->user();

        abort_unless(
            $user instanceof User && Gate::forUser($user)->allows($ability, Membership::class),
            403,
        );
    }
}
