<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Widgets\PlanStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListPaymentPlans extends ListRecords
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-m-plus-circle'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PlanStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Plans')
                ->icon(Heroicon::OutlinedRectangleStack),
            'available' => Tab::make('Available')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('available', true)),
            'archived' => Tab::make('Archived')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('available', false)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'available';
    }
}
