<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class RevenueChartWidget extends ChartWidget
{
    protected ?string $heading = 'Revenue Growth';

    protected ?string $description = 'Estimated MRR from subscriptions active at the end of each month.';

    /**
     * Real MRR trend, not a fabricated count-times-flat-rate figure — the
     * previous implementation multiplied active-subscription count by a
     * hardcoded 29 regardless of what any plan actually cost. Same
     * yearly-divided-by-12 normalisation as BillingStatsWidget::monthlyRevenue()
     * so a mixed-cycle book of business sums to a genuine MRR, not two
     * incompatible units added together.
     */
    #[Override]
    protected function getData(): array
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        $data = new Collection(range(5, 0))->mapWithKeys(function ($monthsAgo) use ($subscriptionClass) {
            $date = Date::now()->subMonths($monthsAgo);
            $month = $date->format('M');

            $mrr = $subscriptionClass::query()
                ->where('created_at', '<=', $date->copy()->endOfMonth())
                ->where('stripe_status', 'active')
                ->with('paymentPlan')
                ->get()
                ->sum(fn (Subscription $subscription): int => $this->monthlyRevenue($subscription));

            return [$month => $mrr / 100];
        });

        return [
            'datasets' => [
                [
                    'label' => 'MRR ('.strtoupper(Config::string('cashier.currency', 'usd')).')',
                    'data' => $data->values()->all(),
                    'fill' => 'start',
                ],
            ],
            'labels' => $data->keys()->all(),
        ];
    }

    private function monthlyRevenue(Subscription $subscription): int
    {
        $plan = $subscription->paymentPlan;

        if (! $plan instanceof PaymentPlan) {
            return 0;
        }

        return $subscription->stripe_price === $plan->yearly_id
            ? intdiv($plan->yearly_price, 12)
            : $plan->monthly_price;
    }

    protected function getType(): string
    {
        return 'line';
    }
}
