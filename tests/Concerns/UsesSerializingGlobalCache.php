<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Cache\GlobalCache;

/**
 * Points the global cache at a store that serializes, under an allowlist that
 * names nothing this package caches. The stock array store never serializes,
 * so a test for the `cache.serializable_classes` trap passes against code that
 * stores whole models unless it asks for this first.
 */
trait UsesSerializingGlobalCache
{
    use PinsGlobalCache;

    protected function useSerializingStore(): void
    {
        $this->pinGlobalCache();

        Config::set('cache.serializable_classes', ['DateTimeImmutable']);
        Config::set('cache.stores.numerosis_probe', ['driver' => 'array', 'serialize' => true]);
        Config::set('numerosis.cache.store', 'numerosis_probe');

        GlobalCache::flush();
    }
}
