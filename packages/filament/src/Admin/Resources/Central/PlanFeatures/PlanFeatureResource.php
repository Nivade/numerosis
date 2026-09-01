<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\NumerosisFilament\Admin\Clusters\Billing\BillingCluster;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Pages\CreatePlanFeature;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Pages\EditPlanFeature;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Pages\ListPlanFeatures;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Schemas\PlanFeatureForm;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Tables\PlanFeaturesTable;
use Override;

class PlanFeatureResource extends Resource
{
    protected static ?string $model = PlanFeature::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    public static function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return PlanFeatureForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return PlanFeaturesTable::configure($table);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPlanFeatures::route('/'),
            'create' => CreatePlanFeature::route('/create'),
            'edit' => EditPlanFeature::route('/{record}/edit'),
        ];
    }
}
