<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions;

use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\CreateSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\EditSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\ListSubscriptions;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Schemas\SubscriptionForm;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Tables\SubscriptionsTable;
use Nvade\Numerosis\Models\Central\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
