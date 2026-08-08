<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Pages;

use Filament\Pages\Page;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\AtRiskSubscriptionsTable;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\BillingStatsWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RecentSubscriptionsTable;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RevenueChartWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\SubscriptionsByPlanChart;
use Override;

class BillingDashboard extends Page
{
    protected static ?string $cluster = BillingCluster::class;

    protected string $view = 'numerosis::filament.clusters.billing.pages.billing-dashboard';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = -1;

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            BillingStatsWidget::class,
            AtRiskSubscriptionsTable::class,
            RevenueChartWidget::class,
            SubscriptionsByPlanChart::class,
            RecentSubscriptionsTable::class,
        ];
    }
}
