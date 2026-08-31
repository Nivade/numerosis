<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\App\Resources\Roles\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvade\NumerosisFilament\App\Resources\Permissions\PermissionResource;
use Override;

class PermissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'permissions';

    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->label('Permission Name')
                        ->placeholder('e.g., view_posts, edit_users')
                        ->columnSpan([
                            'default' => 12,
                            'md' => 8,
                        ]),

                    Select::make('guard_name')
                        ->options(new Collection(Config::array('auth.guards'))->keys())
                        ->default('tenant')
                        ->required()
                        ->label('Guard')
                        ->columnSpan([
                            'default' => 12,
                            'md' => 4,
                        ]),
                ])
                ->columns(12),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Permission')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->iconColor('primary')
                    ->copyable(),

                TextColumn::make('ability')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'delete') => 'danger',
                        str_contains($state, 'create') || str_contains($state, 'update') => 'warning',
                        str_contains($state, 'view') => 'success',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => Str::title(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('context')
                    ->label('Resource')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => Str::title($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('context')
                    ->label('Resource')
                    ->options(fn (): array => PermissionResource::contextFilterOptions()),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPlus),
            ])
            ->actions([
                DetachAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No permissions assigned')
            ->emptyStateDescription('Attach permissions to this role to control access.')
            ->emptyStateActions([
                AttachAction::make()
                    ->label('Attach Permission')
                    ->icon(Heroicon::OutlinedPlus),
            ]);
    }
}
