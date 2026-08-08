<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class PlanStatsWidget extends BaseWidget
{
    #[Override]
    protected function getStats(): array
    {
        $paymentPlanClass = Numerosis::model(PaymentPlan::class);
        $subscriptionClass = Numerosis::model(Subscription::class);

        // sortByDesc() over an all-false `is_popular` column is a stable
        // sort — it silently returns the collection's first plan (whatever
        // order all() happened to load in) and this stat then called that
        // plan "Most subscribed" with zero evidence for it. Find the actual
        // popular one explicitly instead, and say plainly when there isn't
        // one yet rather than guessing.
        $popularPlan = $paymentPlanClass::all()->first(fn (PaymentPlan $plan): bool => $plan->is_popular);
        $popularPlanName = $popularPlan instanceof PaymentPlan ? $popularPlan->name : '—';

        return [
            Stat::make('Total Plans', $paymentPlanClass::count())
                ->description('Active and archived')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
            Stat::make('Popular Plan', $popularPlanName)
                ->description($popularPlan instanceof PaymentPlan ? 'Most subscribed plan' : 'No subscriptions yet')
                ->descriptionIcon('heroicon-m-fire')
                ->color($popularPlan instanceof PaymentPlan ? 'warning' : 'gray'),
            Stat::make('Active Subscriptions', $subscriptionClass::query()->active()->count())
                ->description('Across all plans')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),
        ];
    }
}
