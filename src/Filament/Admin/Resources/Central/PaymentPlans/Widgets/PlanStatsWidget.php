<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

class PlanStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $paymentPlanClass = Numerosis::model(PaymentPlan::class);
        $subscriptionClass = Numerosis::model(Subscription::class);

        return [
            Stat::make('Total Plans', $paymentPlanClass::count())
                ->description('Active and archived')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
            Stat::make('Popular Plan', $paymentPlanClass::all()->sortByDesc(fn (PaymentPlan $plan): bool => $plan->is_popular)->first()->name ?? 'N/A')
                ->description('Most subscribed plan')
                ->descriptionIcon('heroicon-m-fire')
                ->color('warning'),
            Stat::make('Active Subscriptions', $subscriptionClass::query()->active()->count())
                ->description('Across all plans')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),
        ];
    }
}
