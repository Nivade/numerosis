<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\RelationManagers\DomainsRelationManager;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use UnitEnum;

class TenantResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    public static function getModel(): string
    {
        return Numerosis::model(Tenant::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextInput::make('name')
                        ->required()
                        ->unique(column: 'data->name', ignoreRecord: true)
                        ->afterStateUpdated(function (Set $set, $state) {
                            $set('id', $slug = Str::of($state)->slug('_')->toString());
                            $set('domain', Str::of($state)->slug()->toString());
                        })->columnSpanFull(),
                    TextInput::make('id')
                        ->label('Unique ID')
                        ->required()
                        ->disabled(fn ($context) => $context !== 'create')
                        ->unique(table: 'tenants', ignoreRecord: true),
                    TextInput::make('domain')
                        ->label('Sub-Domain')
                        ->required()
                        ->visible(fn ($context) => $context === 'create')
                        ->unique(table: 'domains', ignoreRecord: true)
                        ->prefix('https://')
                        ->suffix('.'.Config::string('app.domain')),
                    TextInput::make('email')->email(),
                    TextInput::make('phone')->tel(),
                    TextInput::make('mobile')->tel(),
                    ColorPicker::make('primary_color'),
                    ColorPicker::make('secondary_color'),
                ])->columns(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name'),
                // Support needs to see a stuck signup or a paused workspace
                // without a database console — see custom-checkout.md,
                // Phase 3's Filament admin surfacing.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (Tenant $record): string => match (true) {
                        $record->isSuspended() => 'Suspended',
                        $record->provisioned_at === null => 'Provisioning',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Suspended' => 'danger',
                        'Provisioning' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('suspended_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('suspended_at')
                    ->label('Suspended')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('suspended_at'),
                        false: fn ($query) => $query->whereNull('suspended_at'),
                    ),
                TernaryFilter::make('provisioned_at')
                    ->label('Provisioned')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('provisioned_at'),
                        false: fn ($query) => $query->whereNull('provisioned_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DomainsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
