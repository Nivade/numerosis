<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Queries\GetTenantMembersPage;
use Nvade\Numerosis\Data\Api\MemberResource;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Membership;

class MemberController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeApi('viewAny');

        $page = GetTenantMembersPage::run(
            (string) $this->apiTenant()->getTenantKey(),
            $request->integer('per_page', 50),
        );

        return new JsonResponse([
            'data' => array_values(array_map(
                fn (Membership $membership): array => MemberResource::fromMembership($membership)->toArray(),
                $page->items(),
            )),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
