<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Privacy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\AnonymizeUser;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Auth\UserAnonymized;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Consent;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Tests\TestCase;

class AnonymizeUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_strips_the_identity_centrally_and_inside_every_tenant(): void
    {
        $user = CentralUser::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $tenant = $this->tenantWith($user);

        AnonymizeUser::run($user);

        $central = CentralUser::withTrashed()->findOrFail($user->id);

        $this->assertNotNull($central->anonymized_at);
        $this->assertNotSame('Ada Lovelace', $central->name);
        $this->assertNotSame('ada@example.test', $central->email);

        // Scalars, not the model: a tenant model read back outside tenancy
        // resolves its connection lazily, and by then there is none.
        /** @var array<string, mixed> $twin */
        $twin = $tenant->run(fn (): array => (array) TenantUser::query()
            ->where('global_id', $user->global_id)
            ->firstOrFail()
            ->only(['name', 'email', 'anonymized_at']));

        $this->assertNotNull($twin['anonymized_at']);
        $this->assertNotSame('Ada Lovelace', $twin['name']);
        $this->assertNotSame('ada@example.test', $twin['email']);
    }

    /** The tenant's own content hangs off this row, so the key has to survive. */
    public function test_the_tenant_side_row_keeps_its_primary_key(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = $this->tenantWith($user);

        $before = $this->twinId($tenant, (string) $user->global_id);

        AnonymizeUser::run($user);

        $after = $this->twinId($tenant, (string) $user->global_id);

        $this->assertSame($before, $after);
    }

    public function test_it_is_idempotent(): void
    {
        Event::fake([UserAnonymized::class]);

        $user = CentralUser::factory()->create();
        $this->tenantWith($user);

        AnonymizeUser::run($user);

        $email = CentralUser::withTrashed()->findOrFail($user->id)->email;

        AnonymizeUser::run($user->fresh() ?? $user);

        $this->assertSame($email, CentralUser::withTrashed()->findOrFail($user->id)->email);

        Event::assertDispatchedTimes(UserAnonymized::class, 1);
    }

    public function test_memberships_go_and_consent_records_stay(): void
    {
        $user = CentralUser::factory()->create();
        $this->tenantWith($user);

        Consent::query()->create([
            'global_user_id' => $user->global_id,
            'purpose' => 'terms',
            'terms_version' => '1.0',
            'granted_at' => now(),
        ]);

        AnonymizeUser::run($user);

        $this->assertSame(0, Membership::query()->where('global_user_id', $user->global_id)->count());
        $this->assertSame(1, Consent::query()->where('global_user_id', $user->global_id)->count());
    }

    /**
     * Deliberate, and named so nobody "fixes" it: financial records carry
     * their own statutory retention and are not erasable on request.
     */
    public function test_billing_rows_survive_anonymization_on_purpose(): void
    {
        $user = CentralUser::factory()->create();
        $this->tenantWith($user);

        DB::connection('central')->table('subscriptions')->insert([
            'subscribable_id' => $user->getKey(),
            'subscribable_type' => $user->getMorphClass(),
            'type' => 'default',
            'stripe_id' => 'sub_retained',
            'stripe_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AnonymizeUser::run($user);

        $this->assertSame(
            1,
            DB::connection('central')->table('subscriptions')->where('stripe_id', 'sub_retained')->count()
        );
    }

    private function twinId(Tenant $tenant, string $globalId): int
    {
        /** @var int $id */
        $id = $tenant->run(fn (): mixed => TenantUser::query()->where('global_id', $globalId)->value('id'));

        return $id;
    }

    private function tenantWith(BaseCentralUser $user): Tenant
    {
        $tenant = $this->createTenantWithDomain('erase'.substr(uniqid(), -8), 'Erasure Tenant');

        tenancy()->end();

        $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        return $tenant;
    }
}
