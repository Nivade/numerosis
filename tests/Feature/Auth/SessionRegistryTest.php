<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Contracts\Auth\SessionRegistry;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Services\Auth\DatabaseSessionRegistry;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The `sessions` table records only the ambient guard's id in `user_id` — a
 * tenant request writes a tenant primary key there — so every read has to
 * match on the login key inside the payload instead. These tests write rows
 * the way Laravel's own handler does and assert against that.
 */
class SessionRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
        Config::set('session.connection', 'central');
        Config::set('session.lifetime', 120);

        $this->connection = 'central';
    }

    public function test_it_reports_a_non_database_driver_as_unlistable(): void
    {
        Config::set('session.driver', 'file');

        $user = CentralUser::factory()->create();
        $registry = $this->registry();

        $this->insertSession('only-row', [$this->centralGuardKey() => $user->id]);

        $this->assertFalse($registry->listable());
        $this->assertSame([], $registry->forUser(Context::Central->guard(), $user->id));
        $this->assertSame(0, $registry->forgetOthers(Context::Central->guard(), $user->id, null));
    }

    public function test_it_lists_only_the_sessions_holding_this_users_login_key(): void
    {
        $user = CentralUser::factory()->create();
        $other = CentralUser::factory()->create();

        $this->insertSession('mine-laptop', [$this->centralGuardKey() => $user->id], lastActivity: 100);
        $this->insertSession('mine-phone', [$this->centralGuardKey() => $user->id], lastActivity: 200);
        $this->insertSession('theirs', [$this->centralGuardKey() => $other->id]);

        // Same numeric id, other guard: what `user_id` alone cannot tell apart.
        $this->insertSession('tenant-side', [$this->tenantGuardKey() => $user->id]);

        $sessions = $this->registry()->forUser(Context::Central->guard(), $user->id);

        $this->assertSame(['mine-phone', 'mine-laptop'], array_map(fn ($session): string => $session->id, $sessions));
    }

    public function test_it_forgets_one_session_and_refuses_another_users(): void
    {
        $user = CentralUser::factory()->create();
        $other = CentralUser::factory()->create();

        $this->insertSession('mine', [$this->centralGuardKey() => $user->id]);
        $this->insertSession('theirs', [$this->centralGuardKey() => $other->id]);

        $registry = $this->registry();

        $this->assertTrue($registry->forget(Context::Central->guard(), $user->id, 'mine'));
        $this->assertFalse($registry->forget(Context::Central->guard(), $user->id, 'theirs'));

        $this->assertSame(['theirs'], $this->sessionIds());
    }

    public function test_it_keeps_the_current_session_when_forgetting_the_others(): void
    {
        $user = CentralUser::factory()->create();

        $this->insertSession('current', [$this->centralGuardKey() => $user->id]);
        $this->insertSession('stolen', [$this->centralGuardKey() => $user->id]);
        $this->insertSession('old-laptop', [$this->centralGuardKey() => $user->id]);

        $this->assertSame(2, $this->registry()->forgetOthers(Context::Central->guard(), $user->id, 'current'));

        $this->assertSame(['current'], $this->sessionIds());
    }

    /**
     * The assertion the plan calls the one that matters: over-broad revocation
     * is a support problem, under-broad is a security one.
     */
    public function test_forgetting_one_tenant_leaves_the_central_session_and_the_other_tenant(): void
    {
        $user = CentralUser::factory()->create();

        $this->insertSession('on-acme', [
            $this->centralGuardKey() => $user->id,
            $this->tenantGuardKey() => 7,
            SessionKey::TenancySessionTenant->value => 'acme',
        ]);

        $this->insertSession('on-globex', [
            $this->centralGuardKey() => $user->id,
            $this->tenantGuardKey() => 9,
            SessionKey::TenancySessionTenant->value => 'globex',
        ]);

        $this->assertSame(1, $this->registry()->forgetTenantAccess(Context::Central->guard(), $user->id, 'acme'));

        $this->assertSame(['on-acme', 'on-globex'], $this->sessionIds());

        $stripped = $this->payloadOf('on-acme');
        $this->assertSame($user->id, $stripped[$this->centralGuardKey()]);
        $this->assertArrayNotHasKey($this->tenantGuardKey(), $stripped);
        $this->assertArrayNotHasKey(SessionKey::TenancySessionTenant->value, $stripped);

        $untouched = $this->payloadOf('on-globex');
        $this->assertSame(9, $untouched[$this->tenantGuardKey()]);
        $this->assertSame('globex', $untouched[SessionKey::TenancySessionTenant->value]);
    }

    public function test_it_ignores_rows_older_than_the_session_lifetime(): void
    {
        $user = CentralUser::factory()->create();

        $this->insertSession('expired', [$this->centralGuardKey() => $user->id], lastActivity: -7201);

        $this->assertSame([], $this->registry()->forUser(Context::Central->guard(), $user->id));
    }

    private function registry(): SessionRegistry
    {
        $registry = $this->app?->make(SessionRegistry::class);

        $this->assertInstanceOf(DatabaseSessionRegistry::class, $registry);

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  int  $lastActivity  seconds from now, so a row can be aged out
     */
    private function insertSession(string $id, array $payload, int $lastActivity = 0, ?string $globalId = null): void
    {
        DB::connection($this->connection)->table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'global_user_id' => $globalId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Firefox/130.0',
            'payload' => base64_encode(serialize($payload)),
            'last_activity' => time() + $lastActivity,
        ]);
    }

    /**
     * `global_user_id` is what keeps the read off every other live row, so a
     * row stamped for somebody else stays unread however its payload looks.
     */
    public function test_a_row_stamped_for_another_person_is_never_decoded(): void
    {
        $user = CentralUser::factory()->create();
        $other = CentralUser::factory()->create();

        $this->insertSession('mine', [$this->centralGuardKey() => $user->id], globalId: $user->global_id);
        $this->insertSession('mislaid', [$this->centralGuardKey() => $user->id], globalId: $other->global_id);

        $sessions = $this->registry()->forUser(Context::Central->guard(), $user->id);

        $this->assertSame(['mine'], array_map(fn ($session): string => $session->id, $sessions));
    }

    /** Rows written before the column landed carry no stamp and are still theirs. */
    public function test_an_unstamped_row_is_still_found(): void
    {
        $user = CentralUser::factory()->create();

        $this->insertSession('older-than-the-column', [$this->centralGuardKey() => $user->id]);

        $sessions = $this->registry()->forUser(Context::Central->guard(), $user->id);

        $this->assertSame(['older-than-the-column'], array_map(fn ($session): string => $session->id, $sessions));
    }

    /** One select, whatever the table holds: the scan is bounded by the index. */
    public function test_listing_devices_runs_one_select_over_the_table(): void
    {
        $user = CentralUser::factory()->create();

        foreach (range(1, 5) as $index) {
            $this->insertSession('mine-'.$index, [$this->centralGuardKey() => $user->id], globalId: $user->global_id);
        }

        $connection = DB::connection($this->connection);
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->registry()->forUser(Context::Central->guard(), $user->id);

        $selects = array_filter(
            $connection->getQueryLog(),
            fn (array $query): bool => str_contains((string) $query['query'], 'from `sessions`'),
        );

        $connection->disableQueryLog();

        $this->assertCount(1, $selects);
    }

    /** @return list<string> */
    private function sessionIds(): array
    {
        /** @var list<string> $ids */
        $ids = DB::connection($this->connection)->table('sessions')->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /** @return array<array-key, mixed> */
    private function payloadOf(string $id): array
    {
        $payload = DB::connection($this->connection)->table('sessions')->where('id', $id)->value('payload');

        $this->assertIsString($payload);

        $decoded = unserialize((string) base64_decode($payload, true));

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function centralGuardKey(): string
    {
        return 'login_'.Context::Central->guard().'_'.sha1(Auth::guard(Context::Central->guard())::class);
    }

    private function tenantGuardKey(): string
    {
        return 'login_'.Context::Tenant->guard().'_'.sha1(Auth::guard(Context::Tenant->guard())::class);
    }
}
