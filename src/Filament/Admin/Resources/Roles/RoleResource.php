<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles;

use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\CreateRole;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\EditRole;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\ListRoles;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\RelationManagers\PermissionsRelationManager;
use Nvade\Numerosis\Models\Central\Role;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getNavigationGroup(): ?string
    {
        return 'Access Control';
    }

    public static function getNavigationLabel(): string
    {
        return 'Roles';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->unique(ignoreRecord: true),
                TextInput::make('description'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('description')->limit(60),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PermissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
