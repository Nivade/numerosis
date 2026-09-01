<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PlanFeatureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->columnSpan(1),

                        Textarea::make('description')
                            ->required()
                            ->rows(1)
                            ->columnSpan(3),
                    ]),
            ]);
    }
}
