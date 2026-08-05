<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Listeners\Invitations;

use App\Models\Tenant\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Listeners\Invitations\SendInvitationNotification;
use Nvade\Numerosis\Notifications\InvitationSent;
use Nvade\Numerosis\Tests\TestCase;

class SendInvitationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emails_the_invitee(): void
    {
        Notification::fake();

        $invitation = new Invitation(['email' => 'invitee@example.com', 'role' => 'member']);

        (new SendInvitationNotification)->handle(new InvitationIssued($invitation));

        Notification::assertSentOnDemand(
            InvitationSent::class,
            fn (InvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'invitee@example.com',
        );
    }
}
