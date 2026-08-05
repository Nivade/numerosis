<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Invitations;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\Invitation;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // forceCreate: `id` is not fillable, so create() lets UUIDGenerator
        // assign a uuid instead of the id this test addresses by domain.
        $this->tenant = Tenant::forceCreate(['id' => 'test-'.uniqid()]);
        $this->tenant->domains()->create([
            'id' => $this->tenant->id,
            'domain' => $this->tenant->id.'.localhost',
        ]);
    }

    public function test_it_throws_a_typed_exception_for_an_already_accepted_invitation(): void
    {
        $user = CentralUser::factory()->create();

        $invitation = $this->invitation([
            'expires_at' => now()->addDays(1),
            'accepted_at' => now(),
        ]);

        $this->expectException(InvitationAlreadyAccepted::class);
        $this->expectExceptionMessage(__('This invitation has already been accepted.'));

        $this->tenant->run(fn () => AcceptInvitation::run($invitation, $user));
    }

    public function test_it_throws_a_typed_exception_for_an_expired_invitation(): void
    {
        $user = CentralUser::factory()->create();

        $invitation = $this->invitation([
            'expires_at' => now()->subDays(1),
            'accepted_at' => null,
        ]);

        $this->expectException(InvitationExpired::class);
        $this->expectExceptionMessage(__('This invitation has expired.'));

        $this->tenant->run(fn () => AcceptInvitation::run($invitation, $user));
    }

    public function test_it_creates_the_tenant_side_user_for_the_invited_member(): void
    {
        $user = CentralUser::factory()->create();

        $invitation = $this->invitation([
            'role' => 'member',
            'expires_at' => now()->addDays(1),
            'accepted_at' => null,
        ]);

        $this->tenant->run(fn () => AcceptInvitation::run($invitation, $user));

        /** @var TenantUser|null $tenantUser */
        $tenantUser = $this->tenant->run(
            fn (): ?TenantUser => TenantUser::where('global_id', $user->global_id)->first()
        );

        $this->assertNotNull($tenantUser);
        $this->assertSame($user->name, $tenantUser->name);
        $this->assertSame($user->email, $tenantUser->email);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function invitation(array $attributes): Invitation
    {
        /** @var Invitation $invitation */
        $invitation = $this->tenant->run(
            fn (): Invitation => Invitation::factory()->create($attributes)
        );

        return $invitation;
    }
}
