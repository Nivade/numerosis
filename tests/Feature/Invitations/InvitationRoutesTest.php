<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Invitations;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Nvade\Numerosis\Tests\TestCase;

class InvitationRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unsigned_url_is_refused(): void
    {
        $invitation = $this->pendingInvitation();

        $this->get("/invitations/{$invitation->ulid}")->assertForbidden();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $invitation = $this->pendingInvitation();

        $url = $this->signedShowUrl($invitation).'&tampered=1';

        $this->get($url)->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login_and_returns_after_authenticating(): void
    {
        $invitation = $this->pendingInvitation();

        $response = $this->get($this->signedShowUrl($invitation));

        $response->assertRedirect(route('login'));
        $this->assertSame($invitation->ulid, session('pending_invitation.ulid'));
        $this->assertSame($invitation->email, session('pending_invitation.email'));
    }

    public function test_an_authenticated_visitor_sees_the_invitation(): void
    {
        $invitation = $this->pendingInvitation();
        $user = CentralUser::factory()->create(['email' => $invitation->email]);

        $response = $this->actingAs($user, Config::string('numerosis.auth.guards.central'))
            ->get($this->signedShowUrl($invitation));

        $response->assertOk();
        $response->assertSeeText($invitation->tenant->name);
    }

    public function test_accepting_as_a_different_authenticated_email_is_refused(): void
    {
        $invitation = $this->pendingInvitation();
        $user = CentralUser::factory()->create(['email' => 'not-'.$invitation->email]);

        Queue::fake();

        // Not `login`: that route carries Fortify's `guest` middleware, which
        // would bounce this already-authenticated user and drop the flash.
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'))
            ->post($this->signedShowUrl($invitation))
            ->assertRedirect(route(RouteNames::tenantsMine()))
            ->assertSessionHas('status', 'This invitation was sent to a different email address.');

        $this->assertNull($invitation->fresh()?->accepted_at);
    }

    /**
     * The signature normally expires with the row, so a stale link 403s at
     * `ValidateSignature` and never reaches the controller. The controller's
     * own expiry check covers the gap where the two disagree: a row whose
     * `expires_at` was shortened after the link was minted, or a clock skew
     * between the queue worker that signed it and the web node reading it.
     * Signed with a future expiry here so the request gets that far.
     *
     * The message then has to survive the redirect. Sending an authenticated
     * visitor to `login` loses it, since that route carries Fortify's `guest`
     * middleware and bounces them onward to `home`.
     */
    public function test_an_expired_invitation_shows_its_message_to_an_authenticated_visitor(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        $invitation = Invitation::factory()->for($tenant, 'tenant')->expired()->create([
            'email' => 'expired-'.uniqid().'@example.com',
        ]);

        $user = CentralUser::factory()->create(['email' => $invitation->email]);

        $this->actingAs($user, Config::string('numerosis.auth.guards.central'))
            ->get($this->signedShowUrl($invitation, now()->addHour()))
            ->assertRedirect(route(RouteNames::tenantsMine()))
            ->assertSessionHas('status', 'This invitation has expired.');
    }

    public function test_an_expired_link_is_refused_by_its_signature_before_the_controller(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        $invitation = Invitation::factory()->for($tenant, 'tenant')->expired()->create([
            'email' => 'stale-'.uniqid().'@example.com',
        ]);

        $this->get($this->signedShowUrl($invitation))->assertForbidden();
    }

    public function test_the_role_is_an_enum_the_owner_case_cannot_reach(): void
    {
        $invitation = $this->pendingInvitation();

        $this->assertInstanceOf(MembershipRole::class, $invitation->role);
        $this->assertNotContains(MembershipRole::Owner, MembershipRole::assignable());
    }

    private function pendingInvitation(): Invitation
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);

        return Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => 'invitee-'.uniqid().'@example.com',
        ]);
    }

    private function signedShowUrl(Invitation $invitation, ?DateTimeInterface $expiry = null): string
    {
        return URL::temporarySignedRoute(
            RouteNames::invitationShow(),
            $expiry ?? $invitation->expires_at,
            ['invitation' => $invitation->getRouteKey()],
        );
    }
}
