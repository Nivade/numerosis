<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Auth;

use App\Models\Central\CentralUser;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class LoginUserTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    /**
     * Regression for a live failure: logging into the tenant guard with
     * `remember: true` also logs the same identity into the central guard
     * (loginToCentralGuardIfNecessary()). That resolver used to default to
     * ambient `tenancy()->initialized`, which is still true at that point in
     * the call — so it re-resolved a *second* `Tenant\User` instead of the
     * `CentralUser` the central guard's provider expects.
     * `EloquentUserProvider::updateRememberToken()` then saved that
     * `Tenant\User` through the central guard's machinery, and the queued
     * `SyncedResourceSaved` listener threw `ModelNotSyncMasterException`
     * (a `Tenant\User` is never a `SyncMaster`) — captured live in
     * `failed_jobs`. See .ai/rules/auth-guards.md.
     */
    public function test_logging_into_the_tenant_guard_with_remember_also_logs_the_matching_central_user_in(): void
    {
        $this->pinGlobalCache();

        $globalId = 'login-user-'.uniqid();

        $centralUser = CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Central Name',
            'email' => 'central-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $tenant = TestTenant::provisioned();
        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::factory()->create([
            'global_id' => $globalId,
        ]);

        LoginUser::run($tenantUser, remember: true);

        $this->assertAuthenticatedAs($tenantUser, 'tenant');

        $centralGuardUser = Auth::guard('web')->user();

        $this->assertInstanceOf(CentralUser::class, $centralGuardUser);
        $this->assertSame($centralUser->global_id, $centralGuardUser->global_id);
        $this->assertNotNull($centralGuardUser->remember_token);
    }
}
