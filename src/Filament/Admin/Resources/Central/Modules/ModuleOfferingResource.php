<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules;

use Nvade\Numerosis\Filament\Admin\Clusters\Billing\BillingCluster;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages\CreateModuleOffering;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages\EditModuleOffering;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages\ListModuleOfferings;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Schemas\ModuleForm;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Tables\ModulesTable;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ModuleOfferingResource extends Resource
{
    protected static ?string $model = ModuleOffering::class;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    public static function form(Schema $schema): Schema
    {
        return ModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuleOfferings::route('/'),
            'create' => CreateModuleOffering::route('/create'),
            'edit' => EditModuleOffering::route('/{record}/edit'),
        ];
    }
}
