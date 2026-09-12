<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Fortify resolves its password broker at request time —
 * `Password::broker(config('fortify.passwords'))` — and a broker resolves its
 * user through `auth.passwords.*`, not `auth.guards.*`. Swapping the key
 * around the `require` of Fortify's route file (the way `fortify.guard` is
 * swapped, because `guest:` middleware *is* baked at registration time) is
 * therefore a no-op that looks like a fix; the swap has to happen while the
 * request is being served, which is
 * `Services\Tenancy\PasswordBrokerBootstrapper`'s job.
 *
 * What the assertion has to look at is the **notifiable's class**, not the
 * address it was sent to. `Tenant\User` and `Central\CentralUser` are
 * `ResourceSyncing` partners, so a tenant user always has a central row at
 * the same address — the two brokers therefore mail the same mailbox, and
 * only the record that gets the reset token differs. With the central broker
 * in force the token lands on the mirror in the central database and the
 * tenant row is never updated, which is a silently broken reset rather than
 * an error.
 */
class PasswordResetBrokerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_request_resolves_the_tenant_user_not_the_central_mirror(): void
    {
        Notification::fake();

        $email = 'shared-'.uniqid().'@example.com';

        [$tenant, $domain] = $this->tenantWithDomain();

        $this->createTenantUser($tenant, ['email' => $email]);

        // Written by ResourceSyncing, not by this test — and it is exactly
        // what the central broker would hand the reset token to.
        $mirror = CentralUser::firstWhere('email', $email);

        $this->assertNotNull($mirror, 'Expected the tenant user to have synced a central row.');

        $this->post('http://'.$domain.'/forgot-password', ['email' => $email])
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($mirror, ResetPassword::class);

        $tenant->run(function () use ($email): void {
            $user = TenantUser::firstWhere('email', $email);

            $this->assertNotNull($user);

            Notification::assertSentTo($user, ResetPassword::class);
        });
    }

    public function test_a_central_request_still_resolves_the_central_user(): void
    {
        Notification::fake();

        $email = 'central-'.uniqid().'@example.com';

        $central = CentralUser::factory()->create(['email' => $email]);

        $this->post('/forgot-password', ['email' => $email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($central, ResetPassword::class);
    }

    /**
     * The bootstrapper restores the value it captured rather than a config
     * default, so a central request served after a tenant one in the same
     * process is unaffected — the failure mode the `finally` in
     * `Numerosis::loadFortifyRoutes()` guards against, at the other end of
     * the lifecycle.
     */
    public function test_the_broker_is_reverted_when_tenancy_ends(): void
    {
        $before = Config::get('fortify.passwords');

        [$tenant] = $this->tenantWithDomain();

        $tenant->run(function (): void {
            $this->assertSame(
                Config::string('numerosis.auth.password_brokers.tenant'),
                Config::get('fortify.passwords'),
            );
        });

        $this->assertSame($before, Config::get('fortify.passwords'));
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function tenantWithDomain(): array
    {
        $id = 'broker'.substr(uniqid(), -8);

        return [$this->createTenantWithDomain($id, 'Broker Tenant'), $this->tenantDomain($id)];
    }
}
