<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetTenantDomains;
use Nvade\Numerosis\Data\Api\DomainData;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Domain;

class DomainController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $this->authorizeApi('viewAny');

        $domains = GetTenantDomains::run((string) $this->apiTenant()->getTenantKey())
            ->map(fn (Domain $domain): array => DomainData::fromDomain($domain)->toArray())
            ->values()
            ->all();

        return new JsonResponse(['data' => $domains]);
    }
}
