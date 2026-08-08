<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

class RecentSubscriptionsTable extends TableWidget
{
    protected static ?string $cluster = BillingCluster::class;

    protected static ?string $heading = 'Recent Subscriptions';

    protected int|string|array $columnSpan = 'full';

    #[Override]
    public function table(Table $table): Table
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        return $table
            ->query(
                $subscriptionClass::query()->latest()->limit(10)
            )
            ->columns([
                TextColumn::make('subscribable_id')
                    ->label('Tenant')
                    ->searchable(),
                TextColumn::make('paymentPlan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trialing' => 'info',
                        'past_due', 'unpaid' => 'danger',
                        'canceled' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label('Started At')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
