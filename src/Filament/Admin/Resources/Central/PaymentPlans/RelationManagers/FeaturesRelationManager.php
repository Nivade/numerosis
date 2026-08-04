<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('slug')
            ->columns([
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable()
                    ->color('gray'),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(50),
                ToggleColumn::make('pivot.available')
                    ->label('Included in Plan'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('available')
                            ->label('Available')
                            ->default(true),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        Toggle::make('available')
                            ->label('Available'),
                    ]),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
