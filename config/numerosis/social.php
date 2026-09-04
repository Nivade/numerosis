<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Social Login
    |--------------------------------------------------------------------------
    |
    | Route names only — provider metadata (label, icon, whether it's
    | configured) is code now, on Nvade\Numerosis\Enums\Auth\SocialProvider.
    | Add a provider there, not here.
    |
    */

    'social' => [
        'routes' => [
            'redirect' => ['name' => 'social.redirect'],
            'callback' => ['name' => 'social.callback'],
        ],
    ],

];
