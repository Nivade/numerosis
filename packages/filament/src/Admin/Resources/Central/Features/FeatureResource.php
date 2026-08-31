<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Features;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Nvade\Numerosis\Models\Central\Feature;
use Nvade\NumerosisFilament\Admin\Clusters\Billing\BillingCluster;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\Pages\CreateFeature;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\Pages\EditFeature;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\Pages\ListFeatures;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\Schemas\FeatureForm;
use Nvade\NumerosisFilament\Admin\Resources\Central\Features\Tables\FeaturesTable;
use Override;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    public static function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return FeatureForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return FeaturesTable::configure($table);
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
            'index' => ListFeatures::route('/'),
            'create' => CreateFeature::route('/create'),
            'edit' => EditFeature::route('/{record}/edit'),
        ];
    }
}
