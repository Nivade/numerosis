<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PermissionsRelationManager extends RelationManager
{
    public static function getRecordTitleAttribute(): ?string
    {
        return 'name';
    }

    protected static string $relationship = 'permissions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->unique(ignoreRecord: true),
            TextInput::make('description'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('description')->limit(60),
        ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->headerActions([
                AttachAction::make('attach')
                    ->preloadRecordSelect(),
            ]);
    }
}
