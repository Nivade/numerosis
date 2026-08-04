<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Modules;

use Nvade\Numerosis\Actions\Modules\PurchaseModule;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedTenantUser;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Facades\Billing as BillingFacade;
use Nvade\Numerosis\Filament\Concerns\NotifiesUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Livewire\Attributes\Computed;

/**
 * Shared by Marketplace (the catalog grid) and ModuleDetail (a single
 * offer's product page) — both mount the same confirmation modal and hit
 * the same PurchaseModule action, so the action/modal/notification wiring
 * lives here once rather than being copied per page.
 */
trait PurchasesModules
{
    use NotifiesUser;

    abstract protected function tenant(): Tenant;

    protected function offerFor(string $slug): ?ModuleOffer
    {
        return resolve(ModuleCatalog::class)->findBySlug($slug);
    }

    /**
     * Whether the signed-in user may spend this tenant's money at all.
     *
     * Computed so a marketplace grid evaluates it once per request rather than
     * once per card — the owner lookup behind ModulePolicy is a central-database
     * query. UX only: PurchaseModule re-checks the same policy server-side,
     * which is the enforcement that counts.
     */
    #[Computed]
    public function canPurchaseModules(): bool
    {
        $actor = GetAuthenticatedTenantUser::run();

        return $actor !== null && Gate::forUser($actor)->allows('purchase', Module::class);
    }

    public function purchaseAction(): Action
    {
        return Action::make('purchase')
            ->visible(fn (): bool => $this->canPurchaseModules())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Purchase '.($this->offerFor((string) $arguments['slug'])?->name() ?? (string) $arguments['slug']).'?')
            ->modalDescription(function (array $arguments): string {
                $offer = $this->offerFor((string) $arguments['slug']);

                return $offer ? $this->purchaseDescription($offer) : '';
            })
            ->modalSubmitActionLabel('Purchase')
            ->action(function (array $arguments): void {
                $this->purchase((string) $arguments['slug']);
            });
    }

    protected function purchaseDescription(ModuleOffer $offer): string
    {
        if ($offer->billingMode() === ModuleBillingMode::OneTime) {
            $price = $offer->price(null);

            return $price === null ? 'A one-time charge to your card on file.' : 'A one-time charge of '.BillingFacade::formatAmount($price).' to your card on file.';
        }

        $monthly = $offer->price(BillingCycle::Monthly);
        $yearly = $offer->price(BillingCycle::Yearly);

        $price = $monthly !== null && $yearly !== null
            ? BillingFacade::formatAmount($monthly).'/mo or '.BillingFacade::formatAmount($yearly).'/yr'
            : '';

        return trim("This will be prorated onto your existing subscription. {$price}");
    }

    protected function priceLabel(ModuleOffer $offer): ?string
    {
        if ($offer->billingMode() === ModuleBillingMode::OneTime) {
            $price = $offer->price(null);

            return $price === null ? null : BillingFacade::formatAmount($price).' one-time';
        }

        $monthly = $offer->price(BillingCycle::Monthly);

        return $monthly === null ? null : BillingFacade::formatAmount($monthly).'/mo';
    }

    protected function purchase(string $slug): void
    {
        $actor = GetAuthenticatedTenantUser::run();

        if ($actor === null) {
            $this->notifyError(__('billing.modules.purchase_unavailable'));

            return;
        }

        try {
            PurchaseModule::run($this->tenant(), $actor, $slug);
        } catch (IncompletePayment $e) {
            $this->handleIncompletePayment($e);

            return;
        } catch (ShowsMessageToUser $e) {
            $this->notifyDomainError($e);

            return;
        }

        $this->notifySuccess('Module purchased');
    }

    /**
     * Called by stripe-confirm.js once a 3DS challenge on a module purchase
     * resolves. The purchase itself already committed inside PurchaseModule
     * before IncompletePayment was thrown — the module row and Stripe
     * invoice are correct either way; this is only feedback for the user.
     */
    public function confirmed(): void
    {
        $this->notifySuccess('Module purchased');
    }
}
