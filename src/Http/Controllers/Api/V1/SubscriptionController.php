<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Nvade\Numerosis\Actions\Queries\GetActiveSubscription;
use Nvade\Numerosis\Actions\Queries\GetTenantUsage;
use Nvade\Numerosis\Data\Api\SubscriptionData;
use Nvade\Numerosis\Data\Api\UsageData;
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
        $this->authorizeApi('viewBilling');

        $tenant = $this->apiTenant();
        $subscription = GetActiveSubscription::run($tenant);

        $usage = GetTenantUsage::run($tenant)
            ->map(fn (MeterUsage $meter): array => UsageData::fromMeter($meter)->toArray())
            ->values()
            ->all();

        return new JsonResponse([
            'data' => [
                'subscription' => $subscription instanceof Subscription
                    ? SubscriptionData::fromSubscription($subscription)->toArray()
                    : null,
                'usage' => $usage,
            ],
        ]);
    }
}
