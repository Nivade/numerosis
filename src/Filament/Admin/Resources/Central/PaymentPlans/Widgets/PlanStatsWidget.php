<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Widgets;

use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlanStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Plans', PaymentPlan::count())
                ->description('Active and archived')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
            Stat::make('Popular Plan', PaymentPlan::all()->sortByDesc(fn (PaymentPlan $plan): bool => $plan->is_popular)->first()->name ?? 'N/A')
                ->description('Most subscribed plan')
                ->descriptionIcon('heroicon-m-fire')
                ->color('warning'),
            Stat::make('Active Subscriptions', Subscription::query()->active()->count())
                ->description('Across all plans')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),
        ];
    }
}
