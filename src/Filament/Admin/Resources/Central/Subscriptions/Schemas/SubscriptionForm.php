<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Schemas;

use Nvade\Numerosis\Models\Central\Tenant;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Subscription')
                    ->tabs([
                        Tab::make('Basic Information')
                            ->icon('heroicon-m-information-circle')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Section::make('Details')
                                            ->schema([
                                                Select::make('subscribable_type')
                                                    ->options([
                                                        Tenant::class => 'Tenant',
                                                    ])
                                                    ->required(),
                                                TextInput::make('subscribable_id')
                                                    ->label('Tenant ID')
                                                    ->required(),
                                                TextInput::make('type')
                                                    ->required()
                                                    ->default('default'),
                                                TextInput::make('stripe_status')
                                                    ->required(),
                                            ])->columnSpan(3)->columns(2),

                                        Section::make('Dates')
                                            ->schema([
                                                DateTimePicker::make('trial_ends_at'),
                                                DateTimePicker::make('ends_at'),
                                            ])->columnSpan(1),
                                    ]),
                            ]),

                        Tab::make('Stripe Integration')
                            ->icon('heroicon-m-credit-card')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        TextInput::make('stripe_id')
                                            ->label('Stripe Subscription ID')
                                            ->required(),
                                        TextInput::make('stripe_price')
                                            ->label('Stripe Price ID'),
                                        TextInput::make('quantity')
                                            ->numeric()
                                            ->default(1),
                                    ])->columns(3),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
