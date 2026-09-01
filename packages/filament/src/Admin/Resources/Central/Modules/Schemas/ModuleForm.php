<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\Billing\ModuleBillingMode;

class ModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                                TextInput::make('slug')
                                    ->required()
                                    ->unique(ignoreRecord: true),
                                Textarea::make('description')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                Toggle::make('available')
                                    ->label('Available for purchase')
                                    ->default(true),
                            ]),
                    ]),

                Section::make('Billing')
                    ->schema([
                        Select::make('billing_mode')
                            ->options(ModuleBillingMode::class)
                            ->required()
                            ->live(),

                        Grid::make(2)
                            ->visible(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::Recurring->value)
                            ->schema([
                                TextInput::make('monthly_price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::Recurring->value)
                                    ->formatStateUsing(fn (?int $state): ?float => $state !== null ? $state / 100 : null)
                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                                TextInput::make('yearly_price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::Recurring->value)
                                    ->formatStateUsing(fn (?int $state): ?float => $state !== null ? $state / 100 : null)
                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                                // Stripe rejects a subscription whose items don't share a
                                // billing interval, so a recurring module must be
                                // purchasable on both cycles — both ids are required
                                // rather than guarded at purchase time.
                                TextInput::make('monthly_id')
                                    ->label('Stripe Monthly Price ID')
                                    ->placeholder('price_...')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::Recurring->value),
                                TextInput::make('yearly_id')
                                    ->label('Stripe Yearly Price ID')
                                    ->placeholder('price_...')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::Recurring->value),
                            ]),

                        Grid::make(2)
                            ->visible(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::OneTime->value)
                            ->schema([
                                TextInput::make('one_time_price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::OneTime->value)
                                    ->formatStateUsing(fn (?int $state): ?float => $state !== null ? $state / 100 : null)
                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                                TextInput::make('one_time_id')
                                    ->label('Stripe Price ID')
                                    ->placeholder('price_...')
                                    ->required(fn (Get $get): bool => $get('billing_mode') === ModuleBillingMode::OneTime->value),
                            ]),
                    ]),
            ]);
    }
}
