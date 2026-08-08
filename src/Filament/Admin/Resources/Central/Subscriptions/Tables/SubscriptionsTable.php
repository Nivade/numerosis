<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
