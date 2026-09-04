<?php

declare(strict_types=1);

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;

return [

    /*
    |--------------------------------------------------------------------------
    | Model overrides
    |--------------------------------------------------------------------------
    |
    | Every package call site that touches one of these 9 models resolves it
    | through `Numerosis::model()`. Left `null` (the default a host never has
    | to touch), that method still checks for a subclass named
    | `App\Models\<suffix>` — the same location `artisan vendor:publish
    | --tag=numerosis-models` writes its stub to — and uses it automatically
    | when found, no key here required. This array stays as the *explicit*
    | override for the rare case of a subclass living somewhere other than
    | the conventional path; see `Numerosis::model()`'s own docblock for the
    | full three-step resolution order.
    |
    */

    'models' => [
        Tenant::class => null,
        Domain::class => null,
        CentralUser::class => null,
        Subscription::class => null,
        PaymentPlan::class => null,
        PendingTenantProvision::class => null,
        TenantUser::class => null,
        Invitation::class => null,
        SocialAccount::class => null,
    ],

];
