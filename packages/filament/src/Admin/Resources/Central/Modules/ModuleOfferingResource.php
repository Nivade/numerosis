<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Modules;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\NumerosisFilament\Admin\Clusters\Billing\BillingCluster;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Pages\CreateModuleOffering;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Pages\EditModuleOffering;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Pages\ListModuleOfferings;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Schemas\ModuleForm;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Tables\ModulesTable;
use Override;

class ModuleOfferingResource extends Resource
{
    protected static ?string $model = ModuleOffering::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ModuleForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ModulesTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListModuleOfferings::route('/'),
            'create' => CreateModuleOffering::route('/create'),
            'edit' => EditModuleOffering::route('/{record}/edit'),
        ];
    }
}
