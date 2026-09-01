<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Social Login
    |--------------------------------------------------------------------------
    |
    | Previously auth.social.* — see 'auth' above for why that was a mistake.
    | 'providers' is button label/icon metadata for every provider this
    | package knows how to render a button for; it is not the source of
    | truth for which are actually usable — Nvade\Numerosis\Support\Social\
    | ConfiguredProviders::all() intersects this list against config('services')
    | credentials, so a consumer with no OAuth app configured for a provider
    | simply never sees its button, no override needed here. 'routes' names
    | the two routes routes/auth.php registers for the OAuth redirect/callback
    | — the package owns those routes, so it owns the names.
    |
    */

    'social' => [
        'routes' => [
            'login' => ['name' => 'oauth.callback'],
            'redirect' => ['name' => 'oauth'],
        ],

        'providers' => [
            'google' => [
                'label' => 'Google',
                'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15',
                'icon' => 'heroicon-o-globe-alt',
            ],
            'github' => [
                'label' => 'GitHub',
                'hover' => 'hover:bg-gray-500/10 dark:hover:bg-gray-400/15',
                'icon' => 'heroicon-o-code-bracket',
            ],
            'discord' => [
                'label' => 'Discord',
                'hover' => 'hover:bg-indigo-500/10 dark:hover:bg-indigo-400/15',
                'icon' => 'heroicon-o-chat-bubble-left-right',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15',
                'icon' => 'heroicon-o-globe-alt',
            ],
            'gitlab' => [
                'label' => 'GitLab',
                'hover' => 'hover:bg-orange-500/10 dark:hover:bg-orange-400/15',
                'icon' => 'heroicon-o-code-bracket',
            ],
        ],
    ],

];
