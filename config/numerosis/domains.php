<?php

declare(strict_types=1);

use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | Domain configuration
    |--------------------------------------------------------------------------
    |
    | These four keys used to live in the host's own config/app.php, as
    | `app.domain`, `app.host` and `app.central.{domain,default,subdomain}`.
    | That was a mistake with a specific cost: config/app.php is a framework
    | file, so this package could not `mergeConfigFrom()` a default into it —
    | which meant every consumer had to hand-edit Laravel's own config, and
    | numerosis:install had to *check* for the keys and explain the failure
    | signature rather than simply supplying a value.
    |
    | It also let two keys drift. `app.domain` derived from env('DOMAIN')
    | while `app.host` derived from env('DOMAIN_NAME').'.'.env('DOMAIN_EXTENSION'),
    | and Domain::getUrl() read the second while CreateTenantDomain and
    | DefaultTenantDomainPolicy read the first — so a tenant's stored domain
    | and its generated URL could name different hosts, with nothing
    | comparing them.
    |
    | Every value below defaults off APP_URL, so a host that sets nothing at
    | all still boots. `apex` is the registrable domain tenant subdomains hang
    | off; `central` is the hostname the central app itself answers on.
    |
    */

    'domains' => [
        // The bare domain tenant subdomains are created under, e.g.
        // "example.com" for "acme.example.com". Defaults to APP_URL's host
        // with its leading label stripped *only* when a central subdomain is
        // configured — otherwise APP_URL's host already is the apex.
        'apex' => env('NUMEROSIS_APEX_DOMAIN') ?: Nvade\Numerosis\Support\Domains::apexFromAppUrl(),

        // The hostname the central app answers on — what routes/auth.php's
        // OAuth routes are bound to. Distinct from `apex`: a deployment may
        // serve the central app from a subdomain (app.example.com) while
        // tenants live directly under the apex.
        'central' => env('NUMEROSIS_CENTRAL_DOMAIN') ?: Nvade\Numerosis\Support\Domains::hostFromAppUrl(),

        // '{tenant}.'.apex, not APP_URL's host — APP_URL may carry the
        // central subdomain too (e.g. app.example.com), which would put every
        // tenant one level too deep. Matches config('tenancy.tenant_domain').
        'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN')
            ?: '{tenant}.'.(env('NUMEROSIS_APEX_DOMAIN') ?: Nvade\Numerosis\Support\Domains::apexFromAppUrl()),
    ],

];
