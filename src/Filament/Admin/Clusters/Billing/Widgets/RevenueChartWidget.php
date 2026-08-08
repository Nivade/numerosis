<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class RevenueChartWidget extends ChartWidget
{
    protected ?string $heading = 'Revenue Growth';

    #[Override]
    protected function getData(): array
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        $data = new Collection(range(5, 0))->mapWithKeys(function ($monthsAgo) use ($subscriptionClass) {
            $date = Date::now()->subMonths($monthsAgo);
            $month = $date->format('M');

            // Mocking some data for now if no real records exist
            $count = $subscriptionClass::query()
                ->where('created_at', '<=', $date->endOfMonth())
                ->where('stripe_status', 'active')
                ->count();

            return [$month => $count * 29];
        });

        return [
            'datasets' => [
                [
                    'label' => 'Revenue ($)',
                    'data' => $data->values()->all(),
                    'fill' => 'start',
                ],
            ],
            'labels' => $data->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
