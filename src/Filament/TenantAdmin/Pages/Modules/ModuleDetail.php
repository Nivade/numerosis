<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules;

use Nvade\Numerosis\Concerns\Billing\ConfirmsPayments;
use Nvade\Numerosis\Concerns\Modules\PurchasesModules;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Exceptions\Tenancy\TenantNotInitialized;
use Nvade\Numerosis\Facades\Billing as BillingFacade;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Livewire\Attributes\Computed;

/**
 * Product page for a single module, reached by clicking a card on
 * Marketplace. Not a navigation item — see .claude/rules/module-marketplace.md
 * for why the catalog is intersected with installed-on-this-node modules;
 * the same intersection gates this page (404, not a blank purchase screen)
 * so a slug that exists in ModuleCatalog but isn't installed here can't be
 * linked to.
 */
class ModuleDetail extends Page implements HasActions
{
    use ConfirmsPayments;
    use InteractsWithActions;
    use PurchasesModules;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant-admin.pages.modules.module-detail';

    protected static ?string $slug = 'modules/{slug}';

    public string $moduleSlug;

    public function mount(string $slug): void
    {
        abort_if(! $this->offerFor($slug) instanceof ModuleOffer || ! $this->isInstalledOnThisNode($slug), 404);

        $this->moduleSlug = $slug;
    }

    public function getTitle(): string
    {
        return $this->offer()->name();
    }

    #[Computed]
    public function tenant(): Tenant
    {
        $tenant = tenant();

        throw_unless($tenant instanceof Tenant, TenantNotInitialized::class, 'No tenant is currently initialized.');

        return $tenant;
    }

    #[Computed]
    public function offer(): ModuleOffer
    {
        $offer = $this->offerFor($this->moduleSlug);

        abort_unless($offer instanceof ModuleOffer, 404);

        return $offer;
    }

    #[Computed]
    public function purchased(): bool
    {
        return Module::query()
            ->where('name', $this->moduleSlug)
            ->whereNotNull('purchased_at')
            ->exists();
    }

    /**
     * @return array{monthly: string|null, yearly: string|null}
     */
    public function recurringPrices(): array
    {
        $offer = $this->offer();

        return [
            'monthly' => $this->formattedPrice($offer, BillingCycle::Monthly),
            'yearly' => $this->formattedPrice($offer, BillingCycle::Yearly),
        ];
    }

    public function oneTimePrice(): ?string
    {
        return $this->formattedPrice($this->offer(), null);
    }

    protected function formattedPrice(ModuleOffer $offer, ?BillingCycle $cycle): ?string
    {
        $price = $offer->price($cycle);

        return $price === null ? null : BillingFacade::formatAmount($price);
    }

    protected function isInstalledOnThisNode(string $slug): bool
    {
        return Modules::modules()
            ->filter(fn (mixed $module): bool => $module instanceof ModuleConfig)
            ->map(fn (ModuleConfig $module): string => Str::lower($module->name))
            ->contains($slug);
    }
}
