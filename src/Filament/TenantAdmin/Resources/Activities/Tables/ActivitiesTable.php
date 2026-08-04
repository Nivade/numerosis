<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivitiesTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description'),

                TextColumn::make('batch_uuid'),

                TextColumn::make('subject_id'),

                TextColumn::make('causer_type'),

                TextColumn::make('subject_type'),

                TextColumn::make('log_name'),

                TextColumn::make('causer_id'),

                TextColumn::make('properties'),

                TextColumn::make('event'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
