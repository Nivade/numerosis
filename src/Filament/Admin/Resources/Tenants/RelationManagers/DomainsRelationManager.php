<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Override;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Subdomain')
                    ->prefix('https://')
                    ->suffix('.'.Config::string('numerosis.domains.apex'))
                    ->columnSpanFull()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('domain')
            ->columns([
                TextColumn::make('subdomain')
                    ->label('Subdomain')
                    ->getStateUsing(
                        fn ($record) => Str::of($record->domain)->before('.')
                    ),
                TextColumn::make('full-domain')
                    ->label('Full Domain')
                    ->getStateUsing(
                        fn ($record) => Str::of(
                            $record->domain
                        )
                    ),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
