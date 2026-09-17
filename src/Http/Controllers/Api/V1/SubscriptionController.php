<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetActiveSubscription;
use Nvade\Numerosis\Actions\Queries\GetTenantUsage;
use Nvade\Numerosis\Data\Api\SubscriptionResource;
use Nvade\Numerosis\Data\Billing\MeterUsage;
use Nvade\Numerosis\Http\Controllers\Api\V1\Concerns\ResolvesApiTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Subscription;

/**
 * The subscription and the current period's usage together: an integration
 * asking what it is paying for wants both, and one request is cheaper than two
 * against the same rate limit.
 */
class SubscriptionController extends Controller
{
    use ResolvesApiTenant;

    public function __invoke(): JsonResponse
    {
        $tenant = $this->apiTenant();
        $subscription = GetActiveSubscription::run($tenant);

        $usage = GetTenantUsage::run($tenant)
            ->map(fn (MeterUsage $meter): array => [
                'key' => $meter->key,
                'used' => $meter->used,
                'included' => $meter->included,
                'overage' => $meter->overage(),
                'period_start' => $meter->period->start->toIso8601String(),
                'period_end' => $meter->period->end->toIso8601String(),
            ])
            ->values()
            ->all();

        return new JsonResponse([
            'data' => [
                'subscription' => $subscription instanceof Subscription
                    ? SubscriptionResource::fromSubscription($subscription)->toArray()
                    : null,
                'usage' => $usage,
            ],
        ]);
    }
}
