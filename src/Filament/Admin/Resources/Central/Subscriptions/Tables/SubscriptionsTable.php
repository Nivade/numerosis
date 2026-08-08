<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Was a bare opaque id — staff had to already know which
                // tenant that id belonged to. Search/sort still target the
                // raw column (the tenant's own 'name' is virtual, stored in
                // `data`, and not practically sortable at this join depth);
                // the visible label is what changed.
                // `subscribable` is a MorphTo, so it resolves as a bare Model
                // — narrowing with instanceof rather than reaching straight
                // for ->name is what keeps this correct if a second billable
                // type is ever added (CentralUser is already Billable).
                TextColumn::make('subscribable_id')
                    ->label('Tenant')
                    ->formatStateUsing(fn (string $state, Subscription $record): string => $record->subscribable instanceof Tenant
                        ? $record->subscribable->name
                        : $state)
                    ->description(fn (string $state, Subscription $record): ?string => $record->subscribable instanceof Tenant ? $state : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->searchable()
                    ->badge(),
                // No monetary value anywhere in this table before — an
                // administrator scanning the list had no way to see what a
                // row was actually worth without opening it. Mirrors
                // BillingStatsWidget's yearly-divided-by-12 normalisation so
                // mixed-cycle rows are comparable at a glance.
                TextColumn::make('mrr')
                    ->label('MRR')
                    ->state(function (Subscription $record): ?int {
                        $plan = $record->paymentPlan;

                        if (! $plan instanceof PaymentPlan) {
                            return null;
                        }

                        return $record->stripe_price === $plan->yearly_id
                            ? intdiv($plan->yearly_price, 12)
                            : $plan->monthly_price;
                    })
                    ->money(divideBy: 100)
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('stripe_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trialing' => 'info',
                        'past_due', 'unpaid' => 'danger',
                        'canceled' => 'gray',
                        default => 'warning',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('stripe_price')
                    ->label('Price ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                // "Show me everyone past-due" was previously impossible
                // without paging through the full unfiltered list — the one
                // dimension every other billing-adjacent table in the panel
                // (Modules) already filters on.
                SelectFilter::make('stripe_status')
                    ->options([
                        'active' => 'Active',
                        'trialing' => 'Trialing',
                        'past_due' => 'Past Due',
                        'unpaid' => 'Unpaid',
                        'canceled' => 'Canceled',
                        'incomplete' => 'Incomplete',
                        'incomplete_expired' => 'Incomplete (Expired)',
                    ]),
                // Was no way to answer "who's on this plan" from here at
                // all — the Payment Plan detail page's subscriber count
                // links here with this filter preset.
                SelectFilter::make('payment_plan_id')
                    ->label('Plan')
                    ->relationship('paymentPlan', 'name'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
