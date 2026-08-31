<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Users;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Nvade\Numerosis\Models\Central\CentralUser as User;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisFilament\Admin\Resources\Users\Pages\EditUser;
use Nvade\NumerosisFilament\Admin\Resources\Users\Pages\ListUsers;
use Override;

/**
 * Every registered person — tenant owners and members, not internal staff.
 *
 * For granting admin-panel roles and reviewing who holds them. Deliberately
 * offers neither create nor delete: accounts are created by registration,
 * and removing one has to account for the tenants that person owns.
 */
class UserResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[Override]
    public static function getModel(): string
    {
        return Numerosis::model(User::class);
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Access Control';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Users';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            // Was Select::make('role_id')->relationship('role', 'name') —
            // neither 'role_id' nor a 'role' relation exist anywhere on
            // CentralUser. It uses Spatie's HasRoles trait (via the shared
            // User base), a many-to-many through model_has_roles, not a
            // single foreign key — Filament's relationship() validates the
            // named relation exists on the model and throws LogicException
            // otherwise, which made every Edit User visit crash.
            Select::make('roles')
                ->relationship(name: 'roles', titleAttribute: 'name')
                ->multiple()
                ->preload(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Admin Roles')
                    ->badge()
                    ->placeholder('No admin-panel access')
                    ->color('primary'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No users yet')
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }

    /**
     * Finding a specific customer by email is one of the most common support
     * lookups in the panel — same reasoning as TenantResource's global
     * search, added for the same reason.
     *
     * @return array<int, string>
     */
    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
