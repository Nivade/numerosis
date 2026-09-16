<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Invitations;

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Notifications\Invitations\InvitationNotification;
use Nvade\Numerosis\Tests\TestCase;

class InvitationIssuingTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_requires_the_invitations_permission(): void
    {
        Notification::fake();

        [$domain, $user] = $this->tenantDomainWithUser('plain-member-'.uniqid().'@example.com', role: null);

        $this->actingAsTenantUser($user);

        $this->post('http://'.$domain.'/team/invitations', [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ])->assertForbidden();

        $this->assertDatabaseMissing('tenant_invitations', ['email' => 'invitee@example.com'], 'central');
    }

    public function test_an_admin_can_issue_an_invitation_and_it_is_notified(): void
    {
        Notification::fake();

        [$domain, $user] = $this->tenantDomainWithUser('admin-'.uniqid().'@example.com', role: 'admin');

        $this->actingAsTenantUser($user);

        $this->from('http://'.$domain.'/team/invitations')
            ->post('http://'.$domain.'/team/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'member',
            ])
            ->assertRedirect('http://'.$domain.'/team/invitations');

        $invitation = Invitation::where('email', 'invitee@example.com')->firstOrFail();

        Notification::assertSentOnDemand(
            InvitationNotification::class,
            fn (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'invitee@example.com'
                && $notification->invitation->is($invitation),
        );
    }

    /**
     * `#[ErrorBag('inviteMember')]` puts the messages somewhere the default
     * bag never looks. `flux:input` has no `error-bag` prop, so the page's
     * first spelling of this rendered a stray HTML attribute and the user got
     * a redisplayed form with no explanation. The page now uses
     * `flux:error ... bag="inviteMember"`.
     */
    public function test_a_rejected_invitation_renders_its_error_on_the_page(): void
    {
        Notification::fake();

        [$domain, $user] = $this->tenantDomainWithUser('bagcheck-'.uniqid().'@example.com', role: 'admin');

        $this->actingAsTenantUser($user);

        // The redirect must stay on the tenant host. `TestCase` pins
        // `URL::forceRootUrl` to the central domain, so a named-route redirect
        // shows up here as a central URL; in path mode it throws outright.
        $this->from('http://'.$domain.'/team/invitations')
            ->post('http://'.$domain.'/team/invitations', [
                'email' => 'not-an-email',
                'role' => 'member',
            ])
            ->assertRedirect('http://'.$domain.'/team/invitations')
            ->assertSessionHasErrors('email', errorBag: 'inviteMember');

        // The form lives on `/team` now; `/team/invitations` redirects there.
        $this->get('http://'.$domain.'/team')->assertOk()->assertSeeHtml('The email field must be a valid email address.');
    }

    public function test_an_owner_role_cannot_be_invited(): void
    {
        Notification::fake();

        [$domain, $user] = $this->tenantDomainWithUser('noowner-'.uniqid().'@example.com', role: 'admin');

        $this->actingAsTenantUser($user);

        $this->post('http://'.$domain.'/team/invitations', [
            'email' => 'wants-owner@example.com',
            'role' => 'owner',
        ])->assertSessionHasErrors('role', errorBag: 'inviteMember');

        $this->assertDatabaseMissing('tenant_invitations', ['email' => 'wants-owner@example.com'], 'central');
    }

    /**
     * `tenant_invitations` is central, so binding `{invitation}` on a tenant
     * route resolves any ULID regardless of owner, and the seeder gives the
     * `admin` role `deleteAny invitations` in every tenant. Only
     * `InvitationPolicy::delete()`'s tenant check stands between an admin of
     * one tenant and another tenant's rows, and the invitee already holds the
     * ULID from their emailed link.
     */
    public function test_an_admin_cannot_revoke_another_tenants_invitation(): void
    {
        Notification::fake();

        [$domain, $user] = $this->tenantDomainWithUser('cross-'.uniqid().'@example.com', role: 'admin');

        $otherTenant = Tenant::forceCreate(['id' => 'victim'.substr(uniqid(), -8), 'name' => 'Victim Tenant']);
        $invitation = Invitation::factory()->for($otherTenant, 'tenant')->create([
            'email' => 'victim-invitee-'.uniqid().'@example.com',
        ]);

        $this->actingAsTenantUser($user);

        $this->delete('http://'.$domain.'/team/invitations/'.$invitation->ulid)
            ->assertForbidden();

        $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id], 'central');
    }

    public function test_an_admin_can_revoke_an_invitation_of_their_own_tenant(): void
    {
        Notification::fake();

        [$domain, $user, $tenant] = $this->tenantDomainWithUser('owner-'.uniqid().'@example.com', role: 'admin');

        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => 'own-invitee-'.uniqid().'@example.com',
        ]);

        $this->actingAsTenantUser($user);

        $this->from('http://'.$domain.'/team/invitations')
            ->delete('http://'.$domain.'/team/invitations/'.$invitation->ulid)
            ->assertRedirect('http://'.$domain.'/team/invitations');

        $this->assertDatabaseMissing('tenant_invitations', ['id' => $invitation->id], 'central');
    }

    /**
     * @return array{0: string, 1: TenantUser, 2: Tenant}
     */
    private function tenantDomainWithUser(string $email, ?string $role): array
    {
        $id = 'issuing'.substr(uniqid(), -8);
        $tenant = $this->createTenantWithDomain($id, 'Issuing Tenant');

        $user = $this->createTenantUser($tenant, [
            'name' => 'Team Member',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        if ($role !== null) {
            $tenant->run(fn () => $user->assignRole($role));
        }

        return [$this->tenantDomain($id), $user, $tenant];
    }
}
