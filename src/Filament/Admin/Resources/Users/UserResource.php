<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Users;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Nvade\Numerosis\Filament\Admin\Resources\Users\Pages\EditUser;
use Nvade\Numerosis\Filament\Admin\Resources\Users\Pages\ListUsers;
use Nvade\Numerosis\Models\Central\CentralUser as User;
use Nvade\Numerosis\Support\Numerosis;

class UserResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModel(): string
    {
        return Numerosis::model(User::class);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Access Control';
    }

    public static function getNavigationLabel(): string
    {
        return 'Users';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Select::make('role_id')
                ->relationship(name: 'role', titleAttribute: 'name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            TextColumn::make('email')
                ->searchable()
                ->sortable(),
            TextColumn::make('role.name'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
