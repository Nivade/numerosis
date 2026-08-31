<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('description'),

                TextEntry::make('created_at')
                    ->label('Created Date')
                    ->dateTime(),

                TextEntry::make('batch_uuid'),

                TextEntry::make('id'),

                TextEntry::make('subject_id'),

                TextEntry::make('causer_type'),

                TextEntry::make('updated_at')
                    ->label('Last Modified Date')
                    ->dateTime(),

                TextEntry::make('subject_type'),

                TextEntry::make('log_name'),

                TextEntry::make('causer_id'),

                TextEntry::make('properties'),

                TextEntry::make('event'),
            ]);
    }
}
