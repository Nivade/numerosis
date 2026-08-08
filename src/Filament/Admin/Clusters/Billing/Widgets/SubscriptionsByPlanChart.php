<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Widgets\ChartWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class SubscriptionsByPlanChart extends ChartWidget
{
    protected static ?string $cluster = BillingCluster::class;

    protected ?string $heading = 'Subscriptions by Plan';

    protected ?string $maxHeight = '250px';

    #[Override]
    protected function getData(): array
    {
        $subscriptionClass = Numerosis::model(Subscription::class);
        $paymentPlanClass = Numerosis::model(PaymentPlan::class);

        $counts = $subscriptionClass::query()
            ->where('stripe_status', 'active')
            ->selectRaw('payment_plan_id, count(*) as total')
            ->groupBy('payment_plan_id')
            ->pluck('total', 'payment_plan_id');

        // Was $paymentPlanClass::find() inside the mapWithKeys() below — one
        // query per distinct plan on every dashboard load instead of one.
        $planNames = $paymentPlanClass::query()
            ->whereIn('id', $counts->keys())
            ->pluck('name', 'id');

        // Built by hand rather than with mapWithKeys(): pluck() yields
        // `mixed` values off the driver, so both the label and the count have
        // to be narrowed here anyway — doing it in a loop keeps that visible
        // instead of hiding it behind a closure signature that claims types
        // the query builder never promised.
        $data = [];

        foreach ($counts as $planId => $total) {
            if (! is_numeric($total)) {
                continue;
            }

            $name = $planNames->get($planId);

            $data[is_string($name) ? $name : 'Unknown'] = (int) $total;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Subscriptions',
                    'data' => array_values($data),
                    'backgroundColor' => [
                        '#fbbf24', // amber-400
                        '#38bdf8', // sky-400
                        '#818cf8', // indigo-400
                        '#f472b6', // pink-400
                        '#34d399', // emerald-400
                    ],
                ],
            ],
            'labels' => array_keys($data),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
