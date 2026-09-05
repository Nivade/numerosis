<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use App\Models\Central\Tenant;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * An invitation and the emailed link that reaches it.
 *
 * The link is signed rather than built by hand: `invitations.show` carries
 * `ValidateSignature`, so an unsigned URL 403s before the controller runs.
 */
trait BuildsInvitations
{
    protected function pendingInvitation(string $emailPrefix = 'invitee'): Invitation
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);

        return Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $emailPrefix.'-'.uniqid().'@example.com',
        ]);
    }

    protected function expiredInvitation(string $emailPrefix = 'expired'): Invitation
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);

        return Invitation::factory()->for($tenant, 'tenant')->expired()->create([
            'email' => $emailPrefix.'-'.uniqid().'@example.com',
        ]);
    }

    /**
     * The signature normally expires with the row; pass `$expiry` to sign a
     * link that outlives it and reaches the controller's own expiry check.
     */
    protected function signedShowUrl(Invitation $invitation, ?DateTimeInterface $expiry = null): string
    {
        return URL::temporarySignedRoute(
            RouteNames::invitationShow(),
            $expiry ?? $invitation->expires_at,
            ['invitation' => $invitation->getRouteKey()],
        );
    }
}
