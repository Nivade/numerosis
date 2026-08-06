<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\CreatePaymentPlan;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\EditPaymentPlan;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\ListPaymentPlans;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\RelationManagers\FeaturesRelationManager;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Schemas\PaymentPlanForm;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Tables\PaymentPlansTable;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Support\Numerosis;

class PaymentPlanResource extends Resource
{
    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModel(): string
    {
        return Numerosis::model(PaymentPlan::class);
    }

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

    /**
     * Was commented out with no explanation. It's real and tested-shaped —
     * PaymentPlan::features() already carries the 'available' pivot this
     * manager edits, matching PaymentPlanFeature's own cast. The main form's
     * CheckboxList (PaymentPlanForm) covers "which features does this plan
     * include" at a glance; this manager is the only place to flip a single
     * feature to unavailable without detaching it, or to see per-feature
     * availability in a real table. Different job, same relation — not
     * redundant with the checkbox list.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            FeaturesRelationManager::class,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
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
