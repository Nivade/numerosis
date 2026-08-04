<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Pages;

use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\BillingStatsWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RecentSubscriptionsTable;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RevenueChartWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\SubscriptionsByPlanChart;
use Filament\Pages\Page;

class BillingDashboard extends Page
{
    protected static ?string $cluster = BillingCluster::class;

    protected string $view = 'filament.clusters.billing.pages.billing-dashboard';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = -1;

    protected function getHeaderWidgets(): array
    {
        return [
            BillingStatsWidget::class,
            RevenueChartWidget::class,
            SubscriptionsByPlanChart::class,
            RecentSubscriptionsTable::class,
        ];
    }
}
