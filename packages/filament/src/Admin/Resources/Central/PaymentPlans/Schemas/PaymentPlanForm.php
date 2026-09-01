<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PaymentPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Payment Plan')
                    ->tabs([
                        Tab::make('General Information')
                            ->icon('heroicon-m-information-circle')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Section::make()
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
                                                    ->required()
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2)
                                            ->columnSpan(3),

                                        Grid::make(1)
                                            ->schema([
                                                Section::make('Status')
                                                    ->schema([
                                                        Toggle::make('available')
                                                            ->label('Available for new customers')
                                                            ->default(true),
                                                        TextEntry::make('is_popular_display')
                                                            ->label('Performance Status')
                                                            ->state(fn ($record) => ($record?->popular() ?? false) ? '🔥 Marked as Popular' : 'Standard Plan'),
                                                    ]),

                                                Section::make('Configuration')
                                                    ->schema([
                                                        TextInput::make('trial_days')
                                                            ->label('Trial Period')
                                                            ->numeric()
                                                            ->default(0)
                                                            ->suffix('days')
                                                            ->required(),
                                                    ]),
                                            ])
                                            ->columnSpan(1),

                                        Section::make('Plan Features')
                                            ->icon('heroicon-m-check-badge')
                                            ->description('Which features this plan includes. Once the plan is saved, the Features table further down the edit page gives finer control — flipping a single feature off without removing it.')
                                            ->schema([
                                                CheckboxList::make('features')
                                                    ->relationship('features', 'slug')
                                                    ->bulkToggleable()
                                                    ->columns(4)
                                                    ->gridDirection('vertical'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Pricing & Stripe')
                            ->icon('heroicon-m-credit-card')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Grid::make(4)
                                            ->schema([
                                                TextInput::make('monthly_price')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->required()
                                                    ->helperText('Amount billed every month')
                                                    // monthly_price/yearly_price are stored in cents
                                                    // (minor currency units); this field is the only
                                                    // place they are edited in dollars.
                                                    ->formatStateUsing(fn (?int $state): ?float => $state !== null ? $state / 100 : null)
                                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                                                TextInput::make('yearly_price')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->required()
                                                    ->helperText('Amount billed every year')
                                                    ->formatStateUsing(fn (?int $state): ?float => $state !== null ? $state / 100 : null)
                                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                                                TextInput::make('monthly_id')
                                                    ->label('Stripe Monthly Price ID')
                                                    ->placeholder('price_...')
                                                    ->hintAction(
                                                        Action::make('copy_yearly')
                                                            ->icon('heroicon-m-clipboard')
                                                            ->tooltip('Copy to yearly')
                                                            ->action(fn ($set, $state) => $set('yearly_id', $state))
                                                    ),
                                                TextInput::make('yearly_id')
                                                    ->label('Stripe Yearly Price ID')
                                                    ->placeholder('price_...'),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Advanced Metadata')
                            ->icon('heroicon-m-code-bracket')
                            ->schema([
                                KeyValue::make('metadata')
                                    ->addActionLabel('Add Parameter'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
