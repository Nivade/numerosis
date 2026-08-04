<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use Nvade\Numerosis\Http\Middleware\UpdateUserLastSeenMiddleware;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

class UpdateUserLastSeenMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * User::isOnline() only needs 5-minute granularity, so writing
     * `last_seen_at` on every single request is pure waste — this asserts the
     * throttle actually skips the write inside the window and still updates
     * once the window has passed.
     */
    public function test_it_throttles_the_last_seen_write(): void
    {
        $tenant = Tenant::create(['id' => 'last-seen-'.uniqid()]);

        $tenant->run(function () {
            $user = TenantUser::forceCreate([
                'name' => 'Test User',
                'email' => 'test-'.uniqid().'@example.com',
                'global_id' => 'global-'.uniqid(),
            ]);

            $this->actingAs($user, 'tenant');

            $middleware = new UpdateUserLastSeenMiddleware;
            $next = fn ($request) => new Response('ok');

            $t0 = Carbon::parse('2026-01-01 00:00:00');
            Carbon::setTestNow($t0);
            $middleware->handle(Request::create('/'), $next);
            $afterFirstRequest = $this->lastSeenAt($user->id);
            $this->assertSame($t0->toDateTimeString(), $afterFirstRequest);

            // Still inside the 60s throttle window: no write should happen.
            Carbon::setTestNow($t0->copy()->addSeconds(30));
            $middleware->handle(Request::create('/'), $next);
            $this->assertSame(
                $afterFirstRequest,
                $this->lastSeenAt($user->id),
                'A request inside the throttle window should not have written last_seen_at again.'
            );

            // Past the window: the next request should write again.
            $t1 = $t0->copy()->addSeconds(61);
            Carbon::setTestNow($t1);
            $middleware->handle(Request::create('/'), $next);
            $this->assertSame(
                $t1->toDateTimeString(),
                $this->lastSeenAt($user->id),
                'A request after the throttle window should have written a fresh last_seen_at.'
            );
        });

        Carbon::setTestNow();
    }

    /**
     * The default guard is not reliably the tenant one inside tenancy — a
     * second initialize() for the same tenant short-circuits, so anything
     * calling Auth::shouldUse() in between leaves it on the central guard.
     * Resolving a CentralUser here used to write `last_seen_at` onto the
     * central users table, which has no such column:
     * "Unknown column 'last_seen_at' in 'field list'".
     */
    public function test_it_never_writes_last_seen_onto_a_central_user(): void
    {
        $tenant = Tenant::create(['id' => 'last-seen-central-'.uniqid()]);
        $centralUser = CentralUser::factory()->create();

        $tenant->run(function () use ($centralUser) {
            $this->actingAs($centralUser, Config::string('auth.defaults.guards.context.central'));

            $middleware = new UpdateUserLastSeenMiddleware;

            $middleware->handle(Request::create('/'), fn ($request) => new Response('ok'));

            $this->assertNull(
                CentralUser::findOrFail($centralUser->id)->getAttributes()['last_seen_at'] ?? null,
                'The middleware must not touch a central user.'
            );
        });
    }

    private function lastSeenAt(int $userId): ?string
    {
        // Tenant\User::casts() does not merge in the base User cast for
        // last_seen_at, so this reads back as a raw DATETIME string here
        // rather than a Carbon instance.
        $value = TenantUser::findOrFail($userId)->getRawOriginal('last_seen_at');

        return is_string($value) ? $value : null;
    }
}
