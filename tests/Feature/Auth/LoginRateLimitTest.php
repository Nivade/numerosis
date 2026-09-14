<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Fortify's stock `login` limiter keys on `lower(username).'|'.$request->ip()`
 * — one bucket for every tenant. Anyone who can guess an address can then
 * spend that bucket against whichever tenant they like and lock its owner
 * out of *every* tenant they belong to.
 * `NumerosisServiceProvider::authThrottleKey()` mixes the tenant key in.
 *
 * **Two tenants is the whole point.** A single-tenant test passes whether or
 * not the key is tenant-scoped, which is why the plan
 * (`.claude/plans/archive/humming-nibbling-flame.md`, 4f) called for this shape.
 * Deleting the `$tenantKey.'|'` segment from `authThrottleKey()` turns the
 * first two tests here red and leaves the third green.
 *
 * The attempts are what carry the address, not any account: a failed login
 * spends the bucket whether or not a user by that name exists, so no second
 * account is needed — and none is possible anyway, since `ResourceSyncing`
 * makes one address one global identity across the whole install.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const int ATTEMPTS_BEFORE_LOCKOUT = 5;

    protected function tearDown(): void
    {
        RateLimiter::clear('login');

        parent::tearDown();
    }

    public function test_a_lockout_on_one_tenant_does_not_lock_the_same_address_on_another(): void
    {
        $email = 'shared-'.uniqid().'@example.com';

        $first = $this->tenantWithUser($email);
        $second = $this->tenantDomainFor('ratelimitb'.substr(uniqid(), -8));

        $this->exhaustTheLimiter($first, $email);

        $this->post('http://'.$first.'/login', ['email' => $email, 'password' => 'wrong-password'])->assertTooManyRequests();

        // Same address, different tenant: a normal rejected login, not a
        // lockout inherited from someone else's failures.
        $this->post('http://'.$second.'/login', ['email' => $email, 'password' => 'wrong-password'])->assertFound();
    }

    /**
     * `tenancy()->end()` is not decoration. Every request in this test runs
     * in one PHP process against one container, and nothing ends tenancy
     * between them — so without it `tenancy()->tenant` is still the tenant
     * the previous request identified, and `authThrottleKey()` reads *that*
     * for a request whose host is the central domain. The assertion would
     * then fail against correct code. (In production each request starts
     * clean; under Octane it would not, which is worth knowing separately.)
     */
    public function test_a_lockout_on_a_tenant_does_not_lock_the_central_domain(): void
    {
        $email = 'central-'.uniqid().'@example.com';

        $domain = $this->tenantWithUser($email);

        $this->exhaustTheLimiter($domain, $email);

        tenancy()->end();

        $this->post('/login', ['email' => $email, 'password' => 'wrong-password'])->assertFound();
    }

    public function test_the_limiter_still_locks_out_repeated_failures_on_one_tenant(): void
    {
        $email = 'locked-'.uniqid().'@example.com';

        $domain = $this->tenantWithUser($email);

        $this->exhaustTheLimiter($domain, $email);

        // Even the *correct* password is refused once the bucket is spent —
        // which is what makes this a rate limiter rather than a hint.
        $this->post('http://'.$domain.'/login', ['email' => $email, 'password' => 'password'])->assertTooManyRequests();
    }

    private function exhaustTheLimiter(string $domain, string $email): void
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS_BEFORE_LOCKOUT; $attempt++) {
            $this->post('http://'.$domain.'/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
        }
    }

    private function tenantWithUser(string $email): string
    {
        $id = 'ratelimit'.substr(uniqid(), -8);

        $this->createTenantUser($this->createTenantWithDomain($id, 'Rate Limit Tenant'), [
            'name' => 'Rate Limited User',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        return $this->tenantDomain($id);
    }

    private function tenantDomainFor(string $id): string
    {
        $this->createTenantWithDomain($id, 'Rate Limit Tenant');

        return $this->tenantDomain($id);
    }
}
