<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route name indirection
    |--------------------------------------------------------------------------
    |
    | 'home' and 'tenants.mine' are called from ~20 sites outside their own
    | route file (see Nvade\Numerosis\Support\Routes\RouteNames). Phase 8 of the
    | opt-in-feature-classes plan gates the routes that register these two
    | names, so every caller reads the name from here instead of a literal
    | string — a disabled feature that renamed or removed the route would
    | otherwise turn each of those call sites into a RouteNotFoundException.
    | Not the full route-name indirection package-extraction.md describes —
    | scoped to only the two names this phase actually gates.
    |
    */

    'routes' => [
        'names' => [
            'home' => 'home',
            'tenants_mine' => 'tenants.mine',
            'invitation_show' => 'invitations.show',
            'invitation_accept' => 'invitations.accept',
            'checkout_subscription' => 'checkout.subscription',
        ],

        /*
         * The view the `home` route renders.
         *
         * Core registers `home` unconditionally — OAuth tenant redirects,
         * checkout error paths and the tenant panel all fall back to it — but
         * the *page* is the product's, not the framework's. Point this at your
         * own view rather than registering a second route named `home`: core's
         * route is declared first, so a host route on the same path would
         * never be matched.
         */
        'home_view' => 'numerosis::home',
    ],

];
