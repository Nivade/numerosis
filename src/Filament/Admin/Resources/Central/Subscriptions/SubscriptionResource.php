<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\CreateSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\EditSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\ListSubscriptions;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Schemas\SubscriptionForm;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Tables\SubscriptionsTable;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

class SubscriptionResource extends Resource
{
    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModel(): string
    {
        return Numerosis::model(Subscription::class);
    }

    public static function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public static function form(Schema $schema): Schema
    {
        return SubscriptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['subscribable_id', 'stripe_id'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
