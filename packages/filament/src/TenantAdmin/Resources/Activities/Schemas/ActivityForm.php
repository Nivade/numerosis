<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required(),

                TextEntry::make('created_at')
                    ->label('Created Date')
                    ->dateTime(),

                TextInput::make('batch_uuid'),

                TextInput::make('subject_id')
                    ->integer(),

                TextInput::make('causer_type'),

                TextEntry::make('updated_at')
                    ->label('Last Modified Date')
                    ->dateTime(),

                TextInput::make('subject_type'),

                TextInput::make('log_name'),

                TextInput::make('causer_id')
                    ->integer(),

                TextInput::make('properties'),

                TextInput::make('event'),
            ]);
    }
}
