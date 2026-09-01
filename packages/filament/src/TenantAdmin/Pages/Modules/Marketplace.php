<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Pages\Modules;

use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Livewire\Attributes\Computed;
use Nvade\Numerosis\Concerns\Billing\ConfirmsPayments;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Enums\Billing\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\Tenancy\TenantNotInitialized;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisFilament\Concerns\Modules\PurchasesModules;
use Override;

/**
 * The module storefront for a tenant.
 *
 * Lists the available catalogue, narrowed to modules actually installed on
 * this node, marked up with what the tenant already owns. Read-only: nothing
 * is written when the page renders.
 */
class Marketplace extends Page implements HasActions
{
    use ConfirmsPayments;
    use InteractsWithActions;
    use PurchasesModules;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected string $view = 'numerosis::filament.tenant-admin.pages.modules.marketplace';

    /**
     * This page's directory (app/Filament/TenantAdmin/Pages/Modules) is under
     * TenantAdminPanelProvider's ->discoverPages() scan, so an explicit
     * conditional entry in ->pages([...]) cannot gate it — discovery
     * registers it regardless. This override is the actual gate; see
     * Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\ModuleResource::canAccess()
     * for the same trap on the resource side.
     *
     * It gates on `available()` rather than the feature switch alone because
     * `internachi/modular` is `suggest`: getModules() below reaches the
     * registry directly, so without this the page is a fatal rather than a
     * 403 on a host that never installed it.
     */
    #[Override]
    public static function canAccess(): bool
    {
        return ModuleSystemFeature::available() && parent::canAccess();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return ModuleSystemFeature::available() && parent::shouldRegisterNavigation();
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Modules';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Marketplace';
    }

    #[Override]
    public function getTitle(): string
    {
        return 'Modules Marketplace';
    }

    #[Computed]
    public function tenant(): Tenant
    {
        $tenant = tenant();

        throw_unless($tenant instanceof Tenant, TenantNotInitialized::class, 'No tenant is currently initialized.');

        return $tenant;
    }

    /**
     * @return Collection<int, array{slug: string, name: string, description: string|null, billing_mode: ModuleBillingMode, price_label: string|null, purchased: bool}>
     */
    public function getModules(): Collection
    {
        /** @var Collection<int, string> $installedSlugs */
        $installedSlugs = Modules::modules()
            ->filter(fn (mixed $module): bool => $module instanceof ModuleConfig)
            ->map(fn (ModuleConfig $module): string => Str::lower($module->name))
            ->values();

        $moduleClass = Numerosis::model(Module::class);

        $tenantModules = $moduleClass::whereIn('name', $installedSlugs)->get()->keyBy('name');

        return resolve(ModuleCatalog::class)->available()
            ->filter(fn (ModuleOffer $offer): bool => $installedSlugs->contains($offer->slug()))
            ->map(function (ModuleOffer $offer) use ($tenantModules): array {
                /** @var Module|null $tenantModule */
                $tenantModule = $tenantModules->get($offer->slug());

                return [
                    'slug' => $offer->slug(),
                    'name' => $offer->name(),
                    'description' => $offer->description(),
                    'billing_mode' => $offer->billingMode(),
                    'price_label' => $this->priceLabel($offer),
                    'purchased' => $tenantModule?->purchased_at !== null,
                ];
            })
            ->values();
    }
}
