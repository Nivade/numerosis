<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetPendingInvitationsForTenant;
use Nvade\Numerosis\Data\Api\InvitationResource;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Invitation;

class InvitationController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $this->authorizeApi('viewAny');

        $invitations = GetPendingInvitationsForTenant::run((string) $this->apiTenant()->getTenantKey())
            ->map(fn (Invitation $invitation): array => InvitationResource::fromInvitation($invitation)->toArray())
            ->values()
            ->all();

        return new JsonResponse(['data' => $invitations]);
    }
}
