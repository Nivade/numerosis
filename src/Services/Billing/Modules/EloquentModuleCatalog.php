<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Modules;

use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Illuminate\Support\Collection;

class EloquentModuleCatalog implements ModuleCatalog
{
    /**
     * Scoped to available modules on purpose. Every purchase path resolves
     * its module through here from a client-supplied slug, and existence is
     * not availability — see
     * {@see \Nvade\Numerosis\Services\Billing\Plans\EloquentPaymentPlanRepository::findBySlug()}
     * and `.claude/rules/billing-checkout.md` for the exact class of bug this
     * closes. Use {@see findAnyBySlug()} for admin/reporting paths that must
     * still see retired modules.
     */
    public function findBySlug(string $slug): ?ModuleOffer
    {
        return ModuleOffering::available()->firstWhere('slug', $slug);
    }

    public function findAnyBySlug(string $slug): ?ModuleOffer
    {
        return ModuleOffering::firstWhere('slug', $slug);
    }

    /**
     * The module catalogue changes only when an operator edits it in
     * Filament (see {@see ModuleOffering::booted()}), so this is cached with
     * a bounded TTL as a backstop behind that invalidation.
     *
     * @return Collection<int, ModuleOffer>
     */
    public function available(): Collection
    {
        /** @var Collection<int, ModuleOffer> */
        return global_cache()->remember(
            CacheKeys::availableModules(),
            now()->addHour(),
            fn () => ModuleOffering::available()->orderBy('name')->get()
        );
    }
}
