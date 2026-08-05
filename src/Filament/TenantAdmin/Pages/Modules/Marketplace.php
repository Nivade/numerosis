<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules;

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
use Nvade\Numerosis\Concerns\Modules\PurchasesModules;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\Tenancy\TenantNotInitialized;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;

/**
 * No writes on render: the old SynchronizeModules::make()->handle() call on
 * every render is gone. What's shown is ModuleCatalog::available()
 * intersected with the modules installed on this node (InterNACHI\Modular's
 * Modules facade), left-joined against tenant `modules` rows. See
 * .claude/plans/module-marketplace.md, "Marketplace page".
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
     * Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\ModuleResource::canAccess()
     * for the same trap on the resource side.
     */
    public static function canAccess(): bool
    {
        return Features::enabled(ModuleSystemFeature::NAME) && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Features::enabled(ModuleSystemFeature::NAME) && parent::shouldRegisterNavigation();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Modules';
    }

    public static function getNavigationLabel(): string
    {
        return 'Marketplace';
    }

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
