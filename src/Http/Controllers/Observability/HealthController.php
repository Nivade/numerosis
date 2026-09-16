<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Observability;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetSystemHealth;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * Unauthenticated, because a document a monitor cannot reach answers nobody.
 * Everything it publishes is a count, a boolean or an age.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $report = GetSystemHealth::run();

        return new JsonResponse(
            $report->toArray(),
            $report->healthy() ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
