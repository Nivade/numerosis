<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The default Dashboard's previous content was AccountWidget alone — no
 * numbers, nothing a staff member would open the panel to see. Billing's own
 * revenue/subscription stats deliberately stay on the Billing cluster's
 * Overview page (see NumerosisAdminPlugin's widget registration docblock);
 * this widget is the tenant-lifecycle counterpart that belongs on the
 * landing page instead — provisioning health, not money.
 */
class TenantOverviewWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $tenantClass = Numerosis::model(Tenant::class);

        $stuckSince = now()->subMinutes(10);

        $provisioning = $tenantClass::query()
            ->whereNull('provisioned_at')
            ->whereNull('suspended_at')
            ->count();

        $stuck = $tenantClass::query()
            ->whereNull('provisioned_at')
            ->whereNull('suspended_at')
            ->where('created_at', '<', $stuckSince)
            ->count();

        $suspended = $tenantClass::query()
            ->whereNotNull('suspended_at')
            ->count();

        $newThisWeek = $tenantClass::query()
            ->where('created_at', '>=', now()->subWeek())
            ->count();

        return [
            Stat::make('Provisioning', $provisioning)
                ->description($stuck > 0 ? "{$stuck} stuck over 10 min" : 'All moving normally')
                ->descriptionIcon($stuck > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
                ->color($stuck > 0 ? 'danger' : 'warning'),
            Stat::make('Suspended', $suspended)
                ->description('Locked out of their workspace')
                ->descriptionIcon('heroicon-m-pause-circle')
                ->color($suspended > 0 ? 'danger' : 'success'),
            Stat::make('New This Week', $newThisWeek)
                ->description('Tenants created in the last 7 days')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),
        ];
    }
}
