<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Invoice;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Nvade\NumerosisFilament\Admin\Resources\Tenants\TenantResource;
use Override;
use RuntimeException;
use Throwable;

/**
 * Detail page for one subscription: what the tenant pays, whether Stripe and
 * the local row agree, and the actions to cancel, resume or swap it.
 *
 * Stripe calls here are best-effort — an outage degrades the live panels
 * rather than failing the page.
 */
class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    #[Override]
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * {@see ViewRecord::getRecord()} returns the
     * base `Model` type; every caller here needs Cashier's Subscription
     * methods (cancel/resume/active/…), so this narrows once instead of
     * asserting the type away at every call site.
     */
    private function record(): Subscription
    {
        $record = $this->getRecord();

        if (! $record instanceof Subscription) {
            throw new RuntimeException('Expected a Subscription record.');
        }

        return $record;
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        $record = $this->record();

        return [
            EditAction::make(),

            Action::make('syncFromStripe')
                ->label('Sync from Stripe')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () use ($record): void {
                    $this->syncFromStripe($record);
                }),

            Action::make('cancel')
                ->label('Cancel at period end')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $record->active() && ! $record->canceled())
                ->requiresConfirmation()
                ->modalDescription('The tenant keeps access until the current billing period ends, then the subscription stops renewing. This calls Stripe directly.')
                ->action(function () use ($record): void {
                    $record->cancel();
                    Notification::make()->title('Subscription will cancel at period end')->success()->send();
                }),

            Action::make('cancelNow')
                ->label('Cancel immediately')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => $record->active() || $record->onGracePeriod())
                ->requiresConfirmation()
                ->modalDescription('The tenant loses access immediately. Any unbilled usage for the current period is not invoiced. This cannot be undone from here.')
                ->action(function () use ($record): void {
                    $record->cancelNow();
                    Notification::make()->title('Subscription canceled immediately')->success()->send();
                }),

            Action::make('resume')
                ->label('Resume')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (): bool => $record->onGracePeriod())
                ->requiresConfirmation()
                ->modalDescription('Resumes billing on the plan the tenant was already on before cancellation.')
                ->action(function () use ($record): void {
                    $record->resume();
                    Notification::make()->title('Subscription resumed')->success()->send();
                }),
        ];
    }

    #[Override]
    public function infolist(Schema $schema): Schema
    {
        $record = $this->record();

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        Section::make('Overview')
                            ->columnSpan(2)
                            ->columns(3)
                            ->schema([
                                TextEntry::make('subscribable.name')
                                    ->label('Tenant')
                                    ->weight('bold')
                                    ->url(fn (Subscription $record): ?string => $record->subscribable instanceof Tenant
                                        ? TenantResource::getUrl('edit', ['record' => $record->subscribable])
                                        : null)
                                    ->placeholder('—'),
                                TextEntry::make('paymentPlan.name')
                                    ->label('Plan')
                                    ->badge()
                                    ->url(fn (Subscription $record): ?string => $record->paymentPlan instanceof PaymentPlan
                                        ? PaymentPlanResource::getUrl('edit', ['record' => $record->paymentPlan])
                                        : null),
                                TextEntry::make('stripe_status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'active' => 'success',
                                        'trialing' => 'info',
                                        'past_due', 'unpaid' => 'danger',
                                        'canceled' => 'gray',
                                        default => 'warning',
                                    }),
                                TextEntry::make('type')->badge()->color('gray'),
                                TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime()->placeholder('No trial'),
                                TextEntry::make('ends_at')->label('Ends at')->dateTime()->placeholder('—')
                                    ->color(fn (?string $state): ?string => $state !== null ? 'danger' : null),
                                TextEntry::make('created_at')->label('Started')->dateTime(),
                                TextEntry::make('quantity')->placeholder('1'),
                                TextEntry::make('stripe_id')->label('Stripe Subscription ID')->copyable()->fontFamily('mono')->color('gray'),
                            ]),

                        Section::make('Financials')
                            ->columnSpan(1)
                            ->schema([
                                TextEntry::make('cycle')
                                    ->label('Billing cycle')
                                    ->state(fn (Subscription $record): string => $this->cycle($record)->label())
                                    ->badge(),
                                TextEntry::make('price')
                                    ->label('Price')
                                    ->state(fn (Subscription $record): string => $this->formattedPrice($record))
                                    ->size('xl')
                                    ->weight('black')
                                    ->color('primary'),
                                TextEntry::make('mrr')
                                    ->label('MRR contribution')
                                    ->state(fn (Subscription $record): string => $this->formattedMonthlyRevenue($record))
                                    ->color('gray'),
                            ]),
                    ]),

                Section::make('Recent Invoices')
                    ->description('Live from Stripe — not stored locally.')
                    ->schema([
                        RepeatableEntry::make('invoices')
                            ->label('')
                            ->state(fn (): array => $this->stripeInvoices($record))
                            ->schema([
                                TextEntry::make('date')->label('Date'),
                                TextEntry::make('total')->label('Amount')->weight('bold'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'paid' => 'success',
                                        'open' => 'warning',
                                        'void', 'uncollectible' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('url')
                                    ->label('')
                                    ->formatStateUsing(fn (?string $state): ?string => $state !== null ? 'View on Stripe →' : null)
                                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                                    ->color('primary'),
                            ])
                            ->columns(4)
                            ->contained(false),
                    ])
                    ->visible(fn (): bool => $record->subscribable instanceof Tenant || $record->subscribable instanceof CentralUser),
            ]);
    }

    private function cycle(Subscription $record): BillingCycle
    {
        $plan = $record->paymentPlan;

        return $plan instanceof PaymentPlan && $record->stripe_price === $plan->yearly_id
            ? BillingCycle::Yearly
            : BillingCycle::Monthly;
    }

    private function formattedPrice(Subscription $record): string
    {
        $plan = $record->paymentPlan;

        if (! $plan instanceof PaymentPlan) {
            return '—';
        }

        $price = $plan->price($this->cycle($record));

        return $price === null ? '—' : $plan->formatCurrency($price);
    }

    private function formattedMonthlyRevenue(Subscription $record): string
    {
        $plan = $record->paymentPlan;

        if (! $plan instanceof PaymentPlan) {
            return '—';
        }

        $monthly = $this->cycle($record) === BillingCycle::Yearly
            ? intdiv($plan->yearly_price, 12)
            : $plan->monthly_price;

        return $plan->formatCurrency($monthly);
    }

    /**
     * @return array<int, array{date: string, total: string, status: ?string, url: ?string}>
     */
    private function stripeInvoices(Subscription $record): array
    {
        $billable = $record->subscribable;

        if (! $billable instanceof Tenant && ! $billable instanceof CentralUser) {
            return [];
        }

        if (! $billable->hasStripeId()) {
            return [];
        }

        try {
            /** @var Collection<int, Invoice> $invoices */
            $invoices = $billable->invoices(true, ['limit' => 10]);
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not load invoices from Stripe')
                ->body('Stripe may be unreachable right now. Try again shortly.')
                ->warning()
                ->send();

            return [];
        }

        return $invoices
            ->map(function (Invoice $invoice): array {
                $stripeInvoice = $invoice->asStripeInvoice();

                return [
                    'date' => $invoice->date()->toFormattedDateString(),
                    'total' => $invoice->total(),
                    'status' => is_string($stripeInvoice->status) ? $stripeInvoice->status : null,
                    'url' => is_string($stripeInvoice->hosted_invoice_url) ? $stripeInvoice->hosted_invoice_url : null,
                ];
            })
            ->all();
    }

    /**
     * Overwrites the local status, price and end date with Stripe's own.
     *
     * Webhooks keep these in sync in the ordinary case; this is the manual
     * repair for when they have drifted.
     */
    private function syncFromStripe(Subscription $record): void
    {
        try {
            $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($record->stripe_id);
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not reach Stripe')
                ->warning()
                ->send();

            return;
        }

        $firstItem = $stripeSubscription->items->first();

        $record->forceFill([
            'stripe_status' => $stripeSubscription->status,
            'stripe_price' => $firstItem?->price->id ?? $record->stripe_price,
            'quantity' => $firstItem->quantity ?? $record->quantity,
        ])->save();

        Notification::make()->title('Synced from Stripe')->success()->send();
    }
}
