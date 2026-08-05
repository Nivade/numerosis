<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

class BillingStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        $activeSubscriptions = $subscriptionClass::query()
            ->where('stripe_status', 'active')
            ->with('paymentPlan')
            ->get();

        $mrr = $activeSubscriptions->sum(fn (Subscription $subscription): int => $this->monthlyRevenue($subscription));

        return [
            Stat::make('Active Subscriptions', $activeSubscriptions->count())
                ->description('Total active paying customers')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),
            Stat::make('Estimated MRR', Cashier::formatAmount($mrr, Config::string('cashier.currency', 'usd')))
                ->description('Monthly Recurring Revenue')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('primary'),
            Stat::make('Total Subscriptions', $subscriptionClass::query()->count())
                ->description('Total subscription records')
                ->descriptionIcon('heroicon-m-list-bullet'),
        ];
    }

    /**
     * The subscribed price's own monthly-equivalent amount — yearly plans are
     * divided down to a monthly figure so mixed-cycle subscriptions sum into a
     * genuine MRR instead of a fabricated flat rate per subscription.
     */
    private function monthlyRevenue(Subscription $subscription): int
    {
        $plan = $subscription->paymentPlan;

        if ($plan === null) {
            return 0;
        }

        return $subscription->stripe_price === $plan->yearly_id
            ? intdiv($plan->yearly_price, 12)
            : $plan->monthly_price;
    }
}
