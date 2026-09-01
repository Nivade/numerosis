<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Prefix for every key in Nvade\Numerosis\Support\Cache\CacheKeys. Does not change
    | which keys are tenant-scoped (Cache::) vs global (global_cache()) —
    | see .claude/rules/tenant-caching.md.
    |
    */

    'cache' => [
        'prefix' => env('NUMEROSIS_CACHE_PREFIX', 'numerosis'),
    ],

];
