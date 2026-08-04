<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * Keeps {@see \Nvade\Numerosis\Services\Billing\Modules\EloquentModuleCatalog::available()}'s
 * cached list from outliving a Filament edit.
 */
class ModuleOfferingObserver
{
    use ForgetsCacheKey;

    public function saved(ModuleOffering $offering): void
    {
        $this->forgetCache(CacheKeys::availableModules());
    }

    public function deleted(ModuleOffering $offering): void
    {
        $this->forgetCache(CacheKeys::availableModules());
    }
}
