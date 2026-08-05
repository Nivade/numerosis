<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Widgets\ChartWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

class SubscriptionsByPlanChart extends ChartWidget
{
    protected static ?string $cluster = BillingCluster::class;

    protected ?string $heading = 'Subscriptions by Plan';

    protected ?string $maxHeight = '250px';

    protected function getData(): array
    {
        $subscriptionClass = Numerosis::model(Subscription::class);
        $paymentPlanClass = Numerosis::model(PaymentPlan::class);

        $data = $subscriptionClass::query()
            ->where('stripe_status', 'active')
            ->selectRaw('payment_plan_id, count(*) as total')
            ->groupBy('payment_plan_id')
            ->get()
            ->mapWithKeys(function (Subscription $item) use ($paymentPlanClass): array {
                $planName = $paymentPlanClass::find($item->payment_plan_id)->name ?? 'Unknown';

                return [$planName => $item->getAttribute('total')];
            });

        return [
            'datasets' => [
                [
                    'label' => 'Subscriptions',
                    'data' => $data->values()->toArray(),
                    'backgroundColor' => [
                        '#fbbf24', // amber-400
                        '#38bdf8', // sky-400
                        '#818cf8', // indigo-400
                        '#f472b6', // pink-400
                        '#34d399', // emerald-400
                    ],
                ],
            ],
            'labels' => $data->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
