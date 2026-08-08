<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Override;

/**
 * The dashboard had four widgets describing the whole book of business and
 * none answering the first question an admin actually opens Billing for:
 * "who needs me right now?" Past-due/unpaid/incomplete subscriptions were
 * visible only by opening the Subscriptions list and applying a filter by
 * hand — this surfaces exactly that set on the page an admin already lands
 * on first (BillingDashboard is the cluster's default/'Overview' route).
 */
class AtRiskSubscriptionsTable extends TableWidget
{
    protected static ?string $heading = 'Needs Attention';

    protected int|string|array $columnSpan = 'full';

    #[Override]
    public function table(Table $table): Table
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        return $table
            ->query(
                $subscriptionClass::query()
                    ->whereIn('stripe_status', ['past_due', 'unpaid', 'incomplete'])
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('subscribable_id')
                    ->label('Tenant')
                    ->formatStateUsing(fn (string $state, Subscription $record): string => $record->subscribable instanceof Tenant
                        ? $record->subscribable->name
                        : $state),
                TextColumn::make('paymentPlan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('updated_at')
                    ->label('Since')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Review')
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Nothing needs attention')
            ->emptyStateDescription('No past-due, unpaid, or incomplete subscriptions right now.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->paginated(false);
    }
}
