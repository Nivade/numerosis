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
    | 'password_brokers.tenant' names the auth.passwords.* entry Fortify's
    | password-reset controllers use while tenancy is initialized — see
    | Services\Tenancy\Bootstrappers\PasswordBrokerBootstrapper. There is no
    | 'central' counterpart on purpose: outside tenancy the broker is
    | whatever config('fortify.passwords') says, which is Fortify's own key
    | and a host's to set.
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

        'password_brokers' => [
            'tenant' => 'tenant',
        ],

        'verification_expire' => 60,
    ],

];
