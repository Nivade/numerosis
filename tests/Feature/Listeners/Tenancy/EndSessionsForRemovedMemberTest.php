<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Tenancy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Removing a member must end their access to that tenant and nothing else.
 * Over-broad revocation is a support problem; under-broad is the security one
 * `team-members-management.md` recorded as open until this listener.
 */
class EndSessionsForRemovedMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
        Config::set('session.connection', 'central');
        Config::set('session.lifetime', 120);
    }

    public function test_detaching_a_member_strips_only_that_tenants_session_state(): void
    {
        $tenant = TestTenant::provisioned();
        $user = CentralUser::factory()->create();

        $user->tenants()->attach($tenant, ['role' => 'member']);

        $this->insertSession('browser', [
            $this->guardKey(Context::Central) => $user->id,
            $this->guardKey(Context::Tenant) => 4,
            SessionKey::TenancySessionTenant->value => $tenant->getTenantKey(),
        ]);

        $this->insertSession('elsewhere', [
            $this->guardKey(Context::Central) => $user->id,
            $this->guardKey(Context::Tenant) => 5,
            SessionKey::TenancySessionTenant->value => 'another-tenant',
        ]);

        $user->tenants()->detach($tenant);

        $stripped = $this->payloadOf('browser');
        $this->assertSame($user->id, $stripped[$this->guardKey(Context::Central)]);
        $this->assertArrayNotHasKey($this->guardKey(Context::Tenant), $stripped);

        $untouched = $this->payloadOf('elsewhere');
        $this->assertSame(5, $untouched[$this->guardKey(Context::Tenant)]);
        $this->assertSame('another-tenant', $untouched[SessionKey::TenancySessionTenant->value]);
    }

    public function test_another_members_removal_leaves_this_users_sessions_alone(): void
    {
        $tenant = TestTenant::provisioned();
        $user = CentralUser::factory()->create();
        $colleague = CentralUser::factory()->create();

        $user->tenants()->attach($tenant, ['role' => 'member']);
        $colleague->tenants()->attach($tenant, ['role' => 'member']);

        $this->insertSession('mine', [
            $this->guardKey(Context::Central) => $user->id,
            $this->guardKey(Context::Tenant) => 4,
            SessionKey::TenancySessionTenant->value => $tenant->getTenantKey(),
        ]);

        $colleague->tenants()->detach($tenant);

        $this->assertSame(4, $this->payloadOf('mine')[$this->guardKey(Context::Tenant)]);
    }

    /** @param  array<string, mixed>  $payload */
    private function insertSession(string $id, array $payload): void
    {
        DB::connection('central')->table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 Firefox/130.0',
            'payload' => base64_encode(serialize($payload)),
            'last_activity' => time(),
        ]);
    }

    /** @return array<array-key, mixed> */
    private function payloadOf(string $id): array
    {
        $payload = DB::connection('central')->table('sessions')->where('id', $id)->value('payload');

        $this->assertIsString($payload);

        $decoded = unserialize((string) base64_decode($payload, true));

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function guardKey(Context $context): string
    {
        return 'login_'.$context->guard().'_'.sha1(Auth::guard($context->guard())::class);
    }
}
