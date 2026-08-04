<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Nvade\Numerosis\Models\Central\Subscription;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class RevenueChartWidget extends ChartWidget
{
    protected ?string $heading = 'Revenue Growth';

    protected function getData(): array
    {
        $data = (new Collection(range(5, 0)))->mapWithKeys(function ($monthsAgo) {
            $date = Date::now()->subMonths($monthsAgo);
            $month = $date->format('M');

            // Mocking some data for now if no real records exist
            $count = Subscription::query()
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
