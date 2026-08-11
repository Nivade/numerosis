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
                // Shows the tenant's name but searches and sorts on the id
                // column, since the name is not a real column. Narrowed by
                // type, because central users are billable here too.
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
                // Normalized to a monthly figure, so mixed-cycle rows compare
                // directly. Matches how the billing widgets report revenue.
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
