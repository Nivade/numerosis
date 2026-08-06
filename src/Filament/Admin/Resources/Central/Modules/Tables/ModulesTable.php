<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Nvade\Numerosis\Enums\ModuleBillingMode;

class ModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('billing_mode')
                    ->badge(),
                TextColumn::make('monthly_price')
                    ->label('Monthly')
                    ->money(divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('yearly_price')
                    ->label('Yearly')
                    ->money(divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('one_time_price')
                    ->label('One-time')
                    ->money(divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('available')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Available' : 'Retired'),
            ])
            ->filters([
                SelectFilter::make('billing_mode')
                    ->options(ModuleBillingMode::class),
                TernaryFilter::make('available'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No modules yet')
            ->emptyStateDescription('Modules are optional add-ons tenants can purchase — this is the catalog of what exists to sell, not which tenants have bought what.')
            ->emptyStateIcon('heroicon-o-puzzle-piece')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
