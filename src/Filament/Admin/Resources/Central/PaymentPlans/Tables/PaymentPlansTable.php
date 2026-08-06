<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        // text-9xl (128px) used to sit here alongside
                        // TextSize::Large — the two disagree, and 9xl wins,
                        // so every card's title overflowed its own card in
                        // the contentGrid below. Almost certainly a
                        // copy-paste leftover; TextSize::Large is the actual
                        // intended size.
                        TextColumn::make('name')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->searchable()
                            ->sortable()
                            ->icon('heroicon-m-sparkles')
                            ->iconColor('warning'),

                        TextColumn::make('available')
                            ->badge()
                            ->color(fn ($state): string => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Inactive')
                            ->alignEnd(),
                    ])->extraAttributes(['class' => 'pb-3 mb-3 border-b border-gray-100 dark:border-gray-800']),

                    Stack::make([
                        TextColumn::make('description')
                            ->color('gray')
                            ->wrap()
                            ->lineClamp(2)
                            ->size(TextSize::Small)
                            ->extraAttributes(['class' => 'mb-4']),

                        Split::make([
                            Stack::make([
                                TextColumn::make('features_count')
                                    ->counts('features')
                                    ->formatStateUsing(fn ($state) => $state.' Features')
                                    ->icon('heroicon-m-check-badge')
                                    ->color('primary')
                                    ->size(TextSize::Small),
                                TextColumn::make('trial_days')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? $state.' day trial' : 'No trial period')
                                    ->icon('heroicon-m-clock')
                                    ->color('gray')
                                    ->size(TextSize::Small),
                            ]),
                            TextColumn::make('is_popular_badge')
                                ->getStateUsing(fn ($record) => $record->popular() ? 'MOST POPULAR' : null)
                                ->badge()
                                ->color('warning')
                                ->visible(fn ($record) => $record?->popular())
                                ->alignEnd(),
                        ]),
                    ]),

                    Stack::make([
                        TextColumn::make('monthly_price')
                            ->money(divideBy: 100)
                            ->size('xl')
                            ->weight(FontWeight::Black)
                            ->color('primary')
                            ->sortable()
                            ->alignEnd(),
                        TextColumn::make('yearly_price')
                            ->money(divideBy: 100)
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->alignEnd()
                            ->suffix(fn ($record) => ' / yr (Save '.$record->getSavingsPercentage().'%)'),
                    ])->alignEnd()->extraAttributes(['class' => 'mt-4 pt-3 border-t border-gray-100 dark:border-gray-800']),
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filters([
                //
            ])
            ->defaultSort('monthly_price')
            ->emptyStateHeading('No payment plans yet')
            ->emptyStateDescription('Plans are what tenants subscribe to — create one to unlock checkout.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
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
