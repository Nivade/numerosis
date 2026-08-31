<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Nvade\Numerosis\Actions\Billing\AddVatNumber;
use Nvade\Numerosis\Actions\Billing\Checkout\StartPlanChangeCheckout;
use Nvade\Numerosis\Actions\Billing\Subscriptions\SwapSubscriptionPlan;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Exceptions\Tenancy\TenantNotInitialized;
use Nvade\Numerosis\Facades\Billing as BillingFacade;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stripe\Exception\ApiErrorException;

/**
 * @property BillingCycle $billingCycle
 * @property-read Tenant $tenant
 * @property-read Subscription|null $subscription
 * @property-read PaymentPlan|null $plan
 * @property-read Collection<int, PaymentPlan> $plans
 * @property-read PaymentPlan|null $currentPlan
 */
class Billing extends Page implements HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public BillingCycle $billingCycle = BillingCycle::Monthly;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected string $view = 'numerosis::filament.tenant-admin.pages.billing';

    protected static bool $shouldRegisterNavigation = false;

    #[Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = tenant();

        return $user !== null && $tenant instanceof Tenant && $tenant->owner()?->global_id === $user->global_id;
    }

    public function mount(): void
    {
        if ($this->subscription && $this->subscription->stripe_price && $this->currentPlan) {
            $this->billingCycle = $this->subscription->stripe_price === $this->currentPlan->getPriceId(BillingCycle::Yearly)
                ? BillingCycle::Yearly
                : BillingCycle::Monthly;
        }
    }

    #[Override]
    public function getTitle(): string
    {
        return 'Billing & Subscription';
    }

    #[Override]
    public function getSubheading(): ?string
    {
        return 'Manage your organization\'s subscription and billing details.';
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manage_billing')
                ->label('Manage in Stripe')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->action(fn () => $this->redirectToStripePortal()),
            Action::make('add_vat_number')
                ->label('Add VAT Number')
                ->icon('heroicon-m-receipt-percent')
                ->color('gray')
                ->schema([
                    TextInput::make('vat_number')
                        ->label('VAT Number')
                        ->required()
                        ->placeholder('e.g. DE123456789'),
                ])
                ->action(function (array $data): void {
                    try {
                        AddVatNumber::run($this->tenant(), (string) $data['vat_number']);
                    } catch (ShowsMessageToUser $e) {
                        Notification::make()
                            ->title('Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('VAT number added')
                        ->success()
                        ->send();
                }),
        ];
    }

    #[Computed]
    public function tenant(): Tenant
    {
        $tenant = tenant();

        throw_unless($tenant instanceof Tenant, TenantNotInitialized::class, 'No tenant is currently initialized.');

        return $tenant;
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->tenant()->subscriptions()->with('paymentPlan')->first();
    }

    #[Computed]
    public function plan(): ?PaymentPlan
    {
        if (! $this->subscription) {
            return null;
        }

        $priceId = $this->subscription->stripe_price;

        if (! $priceId) {
            return null;
        }

        return Numerosis::model(PaymentPlan::class)::query()
            ->where('monthly_id', $priceId)
            ->orWhere('yearly_id', $priceId)
            ->first();
    }

    /**
     * @return Collection<int, PaymentPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Numerosis::model(PaymentPlan::class)::query()
            ->available()
            ->with(['availableFeatures'])
            ->orderBy('monthly_price')
            ->get();
    }

    #[Computed]
    public function currentPlan(): ?PaymentPlan
    {
        return $this->subscription?->paymentPlan;
    }

    public function changePlan(string $planSlug, string $cycle): void
    {
        $plan = Numerosis::model(PaymentPlan::class)::where('slug', $planSlug)->firstOrFail();
        $billingCycle = BillingCycle::from($cycle);
        $priceId = $plan->getPriceId($billingCycle);

        if (! $priceId) {
            Notification::make()
                ->title('Invalid Plan')
                ->body('The selected billing cycle is not available for this plan.')
                ->danger()
                ->send();

            $this->dispatch('close-modal', name: "confirm-plan-change-{$planSlug}-{$cycle}");

            return;
        }

        try {
            if ($this->subscription && $this->subscription->active()) {
                $this->handlePlanSwap($plan, $priceId);

                $this->dispatch('close-modal', name: "confirm-plan-change-{$planSlug}-{$cycle}");

                return;
            }

            $this->redirectToCheckout($planSlug, $billingCycle);
        } catch (ShowsMessageToUser $e) {
            Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->dispatch('close-modal', name: "confirm-plan-change-{$planSlug}-{$cycle}");
        }
    }

    protected function handlePlanSwap(PaymentPlan $plan, string $priceId): void
    {
        $subscription = $this->subscription;

        if (! $subscription) {
            return;
        }

        SwapSubscriptionPlan::run($this->tenant(), $subscription, $this->currentPlan ?? $plan, $plan, $priceId);

        Notification::make()
            ->title('Plan Updated Successfully')
            ->body("Your subscription has been changed to {$plan->name}.")
            ->success()
            ->send();

        // Refresh computed properties
        unset($this->subscription, $this->currentPlan);
    }

    protected function redirectToCheckout(string $planSlug, BillingCycle $cycle): void
    {
        $this->redirect(StartPlanChangeCheckout::run($this->tenant(), $planSlug, $cycle));
    }

    public function redirectToStripePortal(): RedirectResponse
    {
        $tenant = $this->tenant();

        if (! $tenant->hasStripeId()) {
            $tenant->createAsStripeCustomer();
        }

        return redirect()->away($tenant->billingPortalUrl(route('filament.tenantAdmin.pages.billing', ['tenant' => $tenant->id])));
    }

    #[Computed]
    public function formatAmount(int $price): string
    {
        return BillingFacade::formatAmount($price);
    }

    public function getRenewsAt(): ?string
    {
        if (! $this->subscription || ! $this->subscription->active()) {
            return null;
        }

        try {
            $stripeSub = $this->subscription->asStripeSubscription();

            $periodEnd = $stripeSub->items->data[0]->current_period_end ?? null;

            return $periodEnd ? Date::createFromTimestamp($periodEnd)->toFormattedDateString() : 'N/A';
        } catch (ApiErrorException $e) {
            report($e);

            return 'N/A';
        }
    }

    #[Override]
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $currentTenant = tenant();

        return parent::getUrl($parameters, $isAbsolute, $panel, $currentTenant instanceof Tenant ? $currentTenant : null);
    }
}
