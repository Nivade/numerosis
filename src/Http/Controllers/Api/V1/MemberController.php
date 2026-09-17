<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetTenantMembers;
use Nvade\Numerosis\Data\Api\MemberResource;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Membership;

class MemberController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $members = GetTenantMembers::run((string) $this->apiTenant()->getTenantKey())
            ->map(fn (Membership $membership): array => MemberResource::fromMembership($membership)->toArray())
            ->values()
            ->all();

        return new JsonResponse(['data' => $members]);
    }
}
