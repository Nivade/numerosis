<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Permissions;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages\CreatePermission;
use Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages\EditPermission;
use Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages\ListPermissions;
use Nvade\Numerosis\Models\Central\Permission;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function getNavigationGroup(): ?string
    {
        return 'Access Control';
    }

    public static function getNavigationLabel(): string
    {
        return 'Permissions';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->unique(ignoreRecord: true),
            TextInput::make('description'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->limit(60),
                TextColumn::make('roles_count')->counts('roles')->label('Roles')->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No permissions yet')
            ->emptyStateDescription('Permissions are usually seeded by RoleAndPermissionSeeder — check that it has run before adding one by hand.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
