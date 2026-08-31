<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

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
                                                // Was a plain TextInput for the tenant's
                                                // raw id — staff had to already know it
                                                // before they could open this form, since
                                                // nothing else in the panel surfaces it.
                                                // 'name' is virtual (VirtualColumn stores it
                                                // in `data`, no real column), so the search
                                                // matches against `data->name` the same way
                                                // TenantResource's own uniqueness check does.
                                                Select::make('subscribable_id')
                                                    ->label('Tenant')
                                                    ->searchable()
                                                    ->getSearchResultsUsing(function (string $search): array {
                                                        $tenants = Numerosis::model(Tenant::class)::query()
                                                            ->where('id', 'like', "%{$search}%")
                                                            ->orWhere('data->name', 'like', "%{$search}%")
                                                            ->limit(20)
                                                            ->get();

                                                        $options = [];

                                                        foreach ($tenants as $tenant) {
                                                            if ($tenant instanceof Tenant) {
                                                                $options[$tenant->id] = "{$tenant->name} ({$tenant->id})";
                                                            }
                                                        }

                                                        return $options;
                                                    })
                                                    ->getOptionLabelUsing(function (string $value): ?string {
                                                        $tenant = Numerosis::model(Tenant::class)::find($value);

                                                        return $tenant instanceof Tenant
                                                            ? "{$tenant->name} ({$tenant->id})"
                                                            : null;
                                                    })
                                                    ->required(),
                                                TextInput::make('type')
                                                    ->required()
                                                    ->default('default'),
                                                // Freeform text here let a typo silently
                                                // desync suspension/billing logic that
                                                // matches against these exact strings
                                                // (SubscriptionsTable's badge, tenant
                                                // suspension per billing-checkout.md).
                                                Select::make('stripe_status')
                                                    ->options([
                                                        'active' => 'Active',
                                                        'trialing' => 'Trialing',
                                                        'past_due' => 'Past Due',
                                                        'unpaid' => 'Unpaid',
                                                        'canceled' => 'Canceled',
                                                        'incomplete' => 'Incomplete',
                                                        'incomplete_expired' => 'Incomplete (Expired)',
                                                    ])
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
                                    ->description('Stripe is the source of truth for these fields. Webhooks keep them in sync automatically — edit here only to correct drift you have already confirmed against the Stripe dashboard.')
                                    ->schema([
                                        TextInput::make('stripe_id')
                                            ->label('Stripe Subscription ID')
                                            ->helperText('Changing this repoints the local record at a different Stripe subscription entirely.')
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
