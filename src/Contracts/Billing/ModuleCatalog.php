<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Collection;

interface ModuleCatalog
{
    /**
     * Resolve a *selectable* module. Implementations must exclude retired
     * modules: this is what every purchase path calls with a client-supplied
     * slug, so an unscoped lookup keeps retired modules purchasable.
     */
    public function findBySlug(string $slug): ?ModuleOffer;

    /**
     * Resolve a module whether or not it is still selectable — for admin and
     * reporting paths that have to describe a tenant still paying for a
     * retired module.
     */
    public function findAnyBySlug(string $slug): ?ModuleOffer;

    /**
     * @return Collection<int, ModuleOffer>
     */
    public function available(): Collection;
}
