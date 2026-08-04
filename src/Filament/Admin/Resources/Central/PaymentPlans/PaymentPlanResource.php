<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans;

use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\CreatePaymentPlan;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\EditPaymentPlan;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\ListPaymentPlans;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Schemas\PaymentPlanForm;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Tables\PaymentPlansTable;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //            \Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\RelationManagers\FeaturesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlans::route('/'),
            'create' => CreatePaymentPlan::route('/create'),
            'edit' => EditPaymentPlan::route('/{record}/edit'),
        ];
    }
}
