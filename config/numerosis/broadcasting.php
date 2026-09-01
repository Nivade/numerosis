<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | The browser's Reverb connection details — read here, at request time,
    | rather than baked into resources/js/numerosis.js (formerly two
    | separate files, central.js/tenant.js) as import.meta.env.VITE_REVERB_*
    | at *build* time. That used to mean a
    | prebuilt copy of that file was impossible: whatever Reverb host the
    | package maintainer's machine had would be frozen into the JS forever,
    | wrong for every consumer. See resources/views/partials/script-config.blade.php,
    | which is what actually reads this.
    |
    | 'key' is public (Pusher-protocol client key, not REVERB_APP_SECRET) —
    | safe to ship to the browser, same as it always was via Vite's env
    | inlining.
    |
    | host/port/scheme default off the same REVERB_* values
    | config('broadcasting.connections.reverb') reads — correct for a simple,
    | unproxied setup where the browser reaches Reverb directly. A host
    | fronting Reverb with a reverse proxy (thin-app's `ws.<domain>`, TLS
    | terminated before the Reverb process ever sees it) needs the browser to
    | connect somewhere different from where Reverb itself binds — that's
    | what NUMEROSIS_BROADCAST_HOST/PORT/SCHEME are for; they replace the old
    | VITE_REVERB_HOST/PORT/SCHEME env vars 1:1, just read server-side now
    | instead of by Vite.
    |
    */

    'broadcasting' => [
        'reverb' => [
            'key' => env('REVERB_APP_KEY'),
            'host' => env('NUMEROSIS_BROADCAST_HOST', env('REVERB_HOST', 'localhost')),
            'port' => (int) env('NUMEROSIS_BROADCAST_PORT', env('REVERB_PORT', 8080)),
            'scheme' => env('NUMEROSIS_BROADCAST_SCHEME', env('REVERB_SCHEME', 'http')),
        ],
    ],

];
