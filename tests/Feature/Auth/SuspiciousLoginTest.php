<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Nvade\Numerosis\Events\Auth\SuspiciousLoginDetected;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Core ships no listener for this event, so the dispatch is the whole
 * contract. Once per lockout window, not once per blocked attempt: a host
 * wiring it to an alerting channel would otherwise page on every retry.
 */
class SuspiciousLoginTest extends TestCase
{
    use RefreshDatabase;

    private const int ATTEMPTS_BEFORE_LOCKOUT = 5;

    protected function tearDown(): void
    {
        RateLimiter::clear('login');

        parent::tearDown();
    }

    public function test_it_fires_once_per_lockout_rather_than_per_blocked_attempt(): void
    {
        $email = 'anomaly-'.uniqid().'@example.com';
        $domain = $this->tenantWithUser($email);

        Event::fake([SuspiciousLoginDetected::class]);

        $this->attemptLogin($domain, $email, self::ATTEMPTS_BEFORE_LOCKOUT);

        Event::assertNotDispatched(SuspiciousLoginDetected::class);

        $this->attemptLogin($domain, $email, 3);

        Event::assertDispatchedTimes(SuspiciousLoginDetected::class, 1);
    }

    public function test_it_carries_the_tenant_the_lockout_happened_on(): void
    {
        $email = 'anomaly-'.uniqid().'@example.com';
        $id = 'anomaly'.substr(uniqid(), -8);

        $this->createTenantUser($this->createTenantWithDomain($id, 'Anomaly Tenant'), [
            'name' => 'Anomaly User',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        Event::fake([SuspiciousLoginDetected::class]);

        $this->attemptLogin($this->tenantDomain($id), $email, self::ATTEMPTS_BEFORE_LOCKOUT + 1);

        Event::assertDispatched(fn (SuspiciousLoginDetected $event): bool => $event->email === $email && $event->tenantKey === $id);
    }

    private function attemptLogin(string $domain, string $email, int $times): void
    {
        for ($attempt = 0; $attempt < $times; $attempt++) {
            $this->post('http://'.$domain.'/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
        }
    }

    private function tenantWithUser(string $email): string
    {
        $id = 'anomaly'.substr(uniqid(), -8);

        $this->createTenantUser($this->createTenantWithDomain($id, 'Anomaly Tenant'), [
            'name' => 'Anomaly User',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        return $this->tenantDomain($id);
    }
}
