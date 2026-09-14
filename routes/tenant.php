<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Http\Controllers\Invitations\DestroyInvitationController;
use Nvade\Numerosis\Http\Controllers\Invitations\StoreInvitationController;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

// The tenant domain's landing page, and what `tenant_route($domain,
// RouteNames::home())` resolves to. Deliberately unauthenticated: this is
// where an unauthenticated visitor to a tenant lands.
Route::get('/', fn () => view(Config::string('numerosis.routes.home_view')))
    ->name('tenant.home');

// `tenancy.auth`, not `auth`: Laravel's Authenticate plus the central-to-tenant
// session promotion, so a central user who may access this tenant is signed in
// on the tenant guard on the way through.
Route::middleware(['universal', MiddlewareAlias::TenancyAuth->value.':'.Context::Tenant->guard()])->group(function () {
    // Outside the `tenancy.subscription` group below, which is why that gate
    // is nested rather than a fourth entry in the `tenant` group: it is where
    // EnsureTenantSubscriptionActive redirects to.
    Route::livewire('account-suspended', 'numerosis-pages::tenant.suspended')
        ->name('tenant.suspended');

    // The subscription gate: every authenticated tenant screen that is the
    // product itself. Fortify's own screens load outside it, since a suspended
    // tenant still has to verify an address.
    Route::middleware(MiddlewareAlias::TenancySubscription->value)->group(function () {
        if (FeatureRegistry::enabled(InvitationsFeature::NAME)) {
            Route::livewire('team/invitations', 'numerosis-pages::tenant.invitations')->name('team.invitations.index');
            Route::post('team/invitations', StoreInvitationController::class)->name('team.invitations.store');
            Route::delete('team/invitations/{invitation}', DestroyInvitationController::class)->name('team.invitations.destroy');
        }
    });
});
