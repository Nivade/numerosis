<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Invitations;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Actions\Invitations\SendInvitation;
use Nvade\Numerosis\Data\Invitations\InvitationData;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Tests\TestCase;

class SendInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `accepted_at`/`accepted_by_user_id` are outside the model's
     * `#[Fillable]`, so an `updateOrCreate` passing them among its values
     * dropped both without a word. The re-issued link then failed on
     * `InvitationAlreadyAccepted` the first time it was opened.
     */
    public function test_re_inviting_an_accepted_address_clears_the_acceptance(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $inviter = CentralUser::factory()->create();
        $email = 'reinvited-'.uniqid().'@example.com';

        $invitation = Invitation::factory()->for($tenant, 'tenant')->accepted()->create([
            'email' => $email,
        ]);

        $this->assertNotNull($invitation->accepted_at);

        $reissued = SendInvitation::run(
            $tenant,
            new InvitationData($email, MembershipRole::Member),
            $inviter,
        );

        $this->assertTrue($reissued->is($invitation));
        $this->assertNull($reissued->fresh()?->accepted_at);
        $this->assertNull($reissued->fresh()?->accepted_by_user_id);
        $this->assertFalse($reissued->fresh()?->isAccepted());
    }

    public function test_re_inviting_reuses_the_row_rather_than_growing_duplicates(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $inviter = CentralUser::factory()->create();
        $email = 'once-'.uniqid().'@example.com';

        $data = new InvitationData($email, MembershipRole::Viewer);

        SendInvitation::run($tenant, $data, $inviter);
        SendInvitation::run($tenant, $data, $inviter);

        $this->assertSame(1, Invitation::where('tenant_id', $tenant->getKey())->where('email', $email)->count());
    }
}
