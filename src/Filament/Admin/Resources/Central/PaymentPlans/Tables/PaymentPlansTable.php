<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Models\Central\PaymentPlan;

class PaymentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // The most-subscribed plan is the only card given an accent.
                Stack::make([
                    TextColumn::make('is_popular_ribbon')
                        ->getStateUsing(fn (PaymentPlan $record): ?string => $record->popular() ? 'Most subscribed' : null)
                        ->visible(fn (?PaymentPlan $record): bool => $record?->popular() ?? false)
                        ->badge()
                        ->color('warning')
                        ->extraAttributes(['class' => 'absolute -top-3 left-4 z-10']),

                    Split::make([
                        TextColumn::make('name')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->searchable()
                            ->sortable(),

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
                        // Counted once per row rather than per feature.
                        TextColumn::make('active_subscriptions_count')
                            ->counts(['subscriptions as active_subscriptions_count' => fn ($query) => $query->where('stripe_status', 'active')])
                            ->formatStateUsing(fn ($state) => $state.' active '.Str::plural('subscriber', (int) $state))
                            ->icon('heroicon-m-user-group')
                            ->color('gray')
                            ->size(TextSize::Small),
                    ]),

                    Stack::make([
                        TextColumn::make('monthly_price')
                            ->money(divideBy: 100)
                            ->size('2xl')
                            ->weight(FontWeight::Black)
                            ->color('primary')
                            ->sortable()
                            ->alignEnd()
                            ->suffix(' /mo'),
                        TextColumn::make('yearly_price')
                            ->money(divideBy: 100)
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->alignEnd()
                            ->suffix(fn ($record) => ' / yr (save '.$record->getSavingsPercentage().'%)'),
                    ])->alignEnd()->extraAttributes(['class' => 'mt-4 pt-3 border-t border-gray-100 dark:border-gray-800']),
                ])
                    ->space(3)
                    ->extraAttributes(fn (PaymentPlan $record): array => [
                        'class' => 'relative rounded-xl p-2 transition-shadow '.($record->popular()
                            ? 'ring-2 ring-warning-400 dark:ring-warning-500 shadow-lg shadow-warning-500/10'
                            : ''),
                    ]),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filters([
                //
            ])
            ->recordUrl(fn (PaymentPlan $record): string => PaymentPlanResource::getUrl('view', ['record' => $record]))
            ->defaultSort('monthly_price')
            ->emptyStateHeading('No payment plans yet')
            ->emptyStateDescription('Plans are what tenants subscribe to — create one to unlock checkout.')
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            // No bulk actions on a card grid. Deleting a plan is guarded
            // against active subscribers and lives on its own edit page.
            ->selectable(false)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
