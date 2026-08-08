<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages;

use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Override;

/**
 * Command-center detail page for a pricing plan: what it costs, whether it's
 * the most-subscribed tier, and — the thing the old card-grid table couldn't
 * answer at all — who is actually on it right now, one click away rather
 * than a manual filter an operator had to know to build themselves.
 *
 * Layout note: every row here is either a plain unspanned Grid (equal
 * tracks) or a single Section — never a Grid mixing an explicit
 * ->columnSpan(N) with ->columnSpan(M) on siblings. That combination
 * compiles to a broken layout in this app's build (the responsive
 * column-span utility for the *uneven* split doesn't make it into the
 * shipped CSS, so the Grid silently collapses to two equal tracks
 * regardless of the requested column count — verified via computed
 * `grid-template-columns` in devtools, not a guess). Equal-span Grids are
 * unaffected and render correctly; keep new sections here in that shape.
 */
class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    #[Override]
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    #[Override]
    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Hero: name/status/description on the left, the number
                // that actually matters most (price) large on the right —
                // the same emphasis the List page's card gives it, carried
                // through to the detail page instead of price being just
                // another field in a "Pricing" box.
                Section::make()
                    ->extraAttributes(fn (PaymentPlan $record): array => [
                        'class' => 'relative overflow-visible '.($record->popular()
                            ? 'ring-2 ring-warning-400 dark:ring-warning-500 shadow-lg shadow-warning-500/10'
                            : ''),
                    ])
                    ->schema([
                        TextEntry::make('is_popular_ribbon')
                            ->hiddenLabel()
                            ->getStateUsing(fn (PaymentPlan $record): ?string => $record->popular() ? 'Most subscribed plan' : null)
                            ->visible(fn (PaymentPlan $record): bool => $record->popular())
                            ->badge()
                            ->color('warning')
                            ->extraAttributes(['class' => 'absolute -top-3 left-4 z-10']),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('name')
                                    ->hiddenLabel()
                                    ->weight('black')
                                    ->size('2xl'),
                                TextEntry::make('monthly_price')
                                    ->hiddenLabel()
                                    ->money(divideBy: 100)
                                    ->size('2xl')
                                    ->weight('black')
                                    ->color('primary')
                                    ->alignEnd()
                                    ->suffix(' /mo'),

                                TextEntry::make('available')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                                TextEntry::make('yearly_price')
                                    ->hiddenLabel()
                                    ->money(divideBy: 100)
                                    ->color('gray')
                                    ->alignEnd()
                                    ->suffix(fn (PaymentPlan $record): string => ' / yr (save '.$record->getSavingsPercentage().'%)'),
                            ]),

                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->color('gray')
                            ->extraAttributes(['class' => 'mt-3']),
                    ]),

                // Equal three-up — same shape as the working Subscribers
                // grid below, not the broken 2:1 split this replaced.
                Grid::make(3)
                    ->schema([
                        TextEntry::make('slug')->fontFamily('mono')->color('gray')->copyable(),
                        TextEntry::make('trial_days')
                            ->label('Trial period')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} days" : 'No trial'),
                        TextEntry::make('is_popular')
                            ->label('Performance')
                            ->formatStateUsing(fn (bool $state): string => $state ? '🔥 Most subscribed plan' : 'Standard plan')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                    ]),

                Section::make('Stripe')
                    ->description('The Price IDs Stripe actually bills against. Prices are immutable in Stripe — changing the amounts above never updates these.')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('monthly_id')->label('Monthly Price ID')->fontFamily('mono')->color('gray')->copyable()->placeholder('Not set'),
                                TextEntry::make('yearly_id')->label('Yearly Price ID')->fontFamily('mono')->color('gray')->copyable()->placeholder('Not set'),
                            ]),
                    ]),

                // Full width and its own row rather than a third column
                // squeezed beside Overview/Pricing: three stat numbers were
                // being stretched to match the taller card next to them,
                // leaving a card that was mostly empty space under three
                // small numbers. The section description states the click
                // affordance once, rather than repeating it per number.
                Section::make('Subscribers')
                    ->description('Tenants currently subscribed to this plan — click a number to see them.')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('active_subscribers')
                                    ->label('Active')
                                    ->state(fn (PaymentPlan $record): int => $this->subscriptionCount($record, 'active'))
                                    ->size('xl')
                                    ->weight('black')
                                    ->color('success')
                                    ->extraAttributes(['class' => 'block rounded-lg p-3 bg-success-50 dark:bg-success-500/10'])
                                    ->url(fn (PaymentPlan $record): string => SubscriptionResource::getUrl('index', [
                                        'tableFilters' => [
                                            'payment_plan_id' => ['value' => $record->id],
                                            'stripe_status' => ['value' => 'active'],
                                        ],
                                    ])),
                                TextEntry::make('trialing_subscribers')
                                    ->label('Trialing')
                                    ->state(fn (PaymentPlan $record): int => $this->subscriptionCount($record, 'trialing'))
                                    ->size('xl')
                                    ->weight('black')
                                    ->color('info')
                                    ->extraAttributes(['class' => 'block rounded-lg p-3 bg-info-50 dark:bg-info-500/10'])
                                    ->url(fn (PaymentPlan $record): string => SubscriptionResource::getUrl('index', [
                                        'tableFilters' => [
                                            'payment_plan_id' => ['value' => $record->id],
                                            'stripe_status' => ['value' => 'trialing'],
                                        ],
                                    ])),
                                TextEntry::make('at_risk_subscribers')
                                    ->label('Past due / Unpaid')
                                    ->state(fn (PaymentPlan $record): int => $this->subscriptionCount($record, 'past_due') + $this->subscriptionCount($record, 'unpaid'))
                                    ->size('xl')
                                    ->weight('black')
                                    ->color('danger')
                                    ->extraAttributes(['class' => 'block rounded-lg p-3 bg-danger-50 dark:bg-danger-500/10'])
                                    ->url(fn (PaymentPlan $record): string => SubscriptionResource::getUrl('index', [
                                        'tableFilters' => [
                                            'payment_plan_id' => ['value' => $record->id],
                                        ],
                                    ])),
                            ]),
                    ]),
            ]);
    }

    private function subscriptionCount(PaymentPlan $record, string $status): int
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        return $subscriptionClass::query()
            ->where('payment_plan_id', $record->id)
            ->where('stripe_status', $status)
            ->count();
    }
}
