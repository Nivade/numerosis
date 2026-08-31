<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\RelationManagers;

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

    // Distinguishes this from the "Plan Features" checkbox list further up
    // the same Edit page — same underlying relationship, different job (see
    // PaymentPlanResource::getRelations()): that one is quick include/exclude,
    // this is per-feature availability without detaching. A shared plain
    // "Features" heading on both made the page read as one section
    // rendered twice by mistake.
    protected static ?string $title = 'Feature Availability';

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
