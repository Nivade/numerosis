<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    |
    | 'guards' used to live at auth.defaults.guards.context.* — a package key
    | inside a framework config file, which meant no package default was
    | possible (config/auth.php can't be merged one level deep the way
    | config/numerosis.php is) and every consumer had to hand-write both
    | entries before login worked at all. 'central' names the guard the
    | central app authenticates on; 'tenant' the guard every tenant
    | subdomain uses. See Nvade\Numerosis\Enums\Tenancy\Context::guard().
    |
    | 'verification_expire' is minutes a signed email-verification link stays
    | valid for. Previously auth.verification.expire, same reasoning.
    |
    */

    'auth' => [
        'guards' => [
            'central' => 'web',
            'tenant' => 'tenant',
        ],

        'verification_expire' => 60,
    ],

];
