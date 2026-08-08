<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\CreateRole;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\EditRole;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages\ListRoles;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\RelationManagers\PermissionsRelationManager;
use Nvade\Numerosis\Models\Central\Role;
use Override;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Access Control';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Roles';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->unique(ignoreRecord: true),
                TextInput::make('description'),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->limit(60),
                // PermissionResource's table already shows 'roles_count' —
                // this was the missing other half of that same "how much of
                // the system does this row touch" question.
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No roles yet')
            ->emptyStateDescription('Roles bundle permissions together so you can assign several at once instead of one at a time.')
            ->emptyStateIcon('heroicon-o-key');
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            PermissionsRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
