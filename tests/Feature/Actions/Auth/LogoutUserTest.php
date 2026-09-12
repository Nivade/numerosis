<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Auth;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Nvade\Numerosis\Actions\Auth\LogoutUser;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Events\SyncedResourceSaved;

class LogoutUserTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    /**
     * Regression for a live failure, captured in thin-app's `failed_jobs`:
     * the tenant guard's `logout()` resolved a user on the *central* domain,
     * where the tenant provider's model has no tenant database to read from.
     * It therefore hydrated central `users` row 1 as an `App\Models\Tenant\User`
     * — a soft-deleted row, which the tenant model has no `SoftDeletes` to
     * exclude — and cycled a fresh remember token onto it, writing to a
     * stranger's central record. The `saved` event that write fired carried
     * no tenant, so the queued `UpdateSyncedResource` listener threw
     * `ModelNotSyncMasterException` on all twenty tries.
     *
     * Same exception, same cause (a tenant model reached through the wrong
     * context) as `LoginUserTest`'s own regression, on the opposite half of
     * the session. See .ai/rules/auth-guards.md.
     */
    public function test_it_never_resolves_a_tenant_user_when_logging_out_on_the_central_domain(): void
    {
        // Written straight through the default connection rather than as a
        // `CentralUser`: that model rides the separate `central` connection,
        // which `RefreshDatabase` does not transact, so a row created there
        // may be invisible to the default connection's snapshot — and the
        // default connection is exactly where the bug reads and writes. Both
        // ends of this test therefore stay on one connection.
        $userId = DB::table('users')->insertGetId([
            'global_id' => 'logout-user-'.uniqid(),
            'name' => 'Central Name',
            'email' => 'central-'.uniqid().'@example.com',
            'password' => 'password-hash',
            // Non-empty is the precondition for `SessionGuard::logout()`
            // cycling the token at all.
            'remember_token' => 'original-remember-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guard = Auth::guard('tenant');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        // What the shared, apex-scoped session looks like after logging in on
        // a tenant subdomain: a bare primary key under the tenant guard's own
        // session name, still readable on the central domain.
        Session::put($guard->getName(), $userId);

        $this->assertFalse(tenancy()->initialized);

        // Faked only now: the row insert above is setup, and the tenant guard
        // is what must stay silent. Anything dispatched from here on is the
        // bug.
        Event::fake([SyncedResourceSaved::class]);

        LogoutUser::run();

        $this->assertSame(
            'original-remember-token',
            DB::table('users')->where('id', $userId)->value('remember_token'),
            'Logging out on the central domain cycled a remember token onto a central row through the tenant guard.',
        );

        Event::assertNotDispatched(SyncedResourceSaved::class);
    }

    /** On a tenant domain it stays an ordinary logout. */
    public function test_it_logs_the_tenant_guard_out_inside_tenant_context(): void
    {
        $this->pinGlobalCache();

        $globalId = 'logout-user-'.uniqid();

        CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Central Name',
            'email' => 'central-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $tenant = TestTenant::provisioned();
        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::factory()->create(['global_id' => $globalId]);

        Auth::guard('tenant')->login($tenantUser);

        $this->assertTrue(Auth::guard('tenant')->check());

        LogoutUser::run();

        $this->assertFalse(Auth::guard('tenant')->check());
    }
}
