<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetPendingInvitationsForTenant;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Invitation;

class InvitationController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $invitations = GetPendingInvitationsForTenant::run((string) $this->apiTenant()->getTenantKey())
            ->map(fn (Invitation $invitation): array => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return new JsonResponse(['data' => $invitations]);
    }
}
