<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Modules;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Support\Cache\CacheKeys;

class EloquentModuleCatalog implements ModuleCatalog
{
    /**
     * Finds an *available* module by slug. Every purchase path resolves a
     * client-supplied slug through here, so a retired module must not be
     * reachable — existence is not availability.
     *
     * Use {@see findAnyBySlug()} for admin and reporting screens that have to
     * describe retired modules too.
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
