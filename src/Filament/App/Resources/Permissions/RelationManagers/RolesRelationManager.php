<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\App\Resources\Permissions\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

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
                        ->label('Role Name')
                        ->placeholder('e.g., Manager, Editor, Viewer')
                        ->columnSpan([
                            'default' => 12,
                            'md' => 8,
                        ]),

                    Select::make('guard_name')
                        ->options([
                            'web' => 'Web',
                            'tenant' => 'Tenant',
                        ])
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
                    ->label('Role')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(Heroicon::OutlinedKey)
                    ->iconColor('primary')
                    ->description(fn ($record): string => $record->guard_name === 'tenant' ? 'Tenant Role' : 'Web Role'),

                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'gray',
                        $state < 5 => 'warning',
                        default => 'success',
                    })
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPlus),
            ])
            ->recordActions([
                DetachAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->groupedBulkActions([
                DetachBulkAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedKey)
            ->emptyStateHeading('No roles assigned')
            ->emptyStateDescription('Attach this permission to roles to grant access.')
            ->emptyStateActions([
                AttachAction::make()
                    ->label('Attach Role')
                    ->icon(Heroicon::OutlinedPlus),
            ]);
    }
}
