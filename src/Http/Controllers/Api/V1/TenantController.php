<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Data\Api\TenantData;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;

/** The tenant the token belongs to, and no other. */
class TenantController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $this->authorizeApi('viewAny');

        return new JsonResponse(['data' => TenantData::fromTenant($this->apiTenant())->toArray()]);
    }
}
