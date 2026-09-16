<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Http\Controllers\Invitations\DestroyInvitationController;
use Nvade\Numerosis\Http\Controllers\Invitations\StoreInvitationController;
use Nvade\Numerosis\Http\Controllers\Team\DestroyMemberController;
use Nvade\Numerosis\Http\Controllers\Team\DestroyOwnershipNominationController;
use Nvade\Numerosis\Http\Controllers\Team\StoreOwnershipNominationController;
use Nvade\Numerosis\Http\Controllers\Team\UpdateMemberRoleController;

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
    Route::middleware([MiddlewareAlias::TenancySubscription->value, MiddlewareAlias::TenancyMembership->value])->group(function () {
        Route::livewire('team', 'numerosis-pages::tenant.team')->name('team.index');
        Route::patch('team/members/{membership}', UpdateMemberRoleController::class)->name('team.members.update');
        Route::delete('team/members/{membership}', DestroyMemberController::class)->name('team.members.destroy');

        Route::post('team/ownership', StoreOwnershipNominationController::class)->name('team.ownership.store');

        Route::delete('team/ownership/{nomination}', DestroyOwnershipNominationController::class)
            ->name('team.ownership.destroy');

        if (FeatureRegistry::enabled(InvitationsFeature::NAME)) {
            // The invitations screen folded into `team.index`; the name stays
            // because a host may link it. `Str::beforeLast` on the current
            // URL, not `route()`, which throws in path mode.
            Route::get('team/invitations', fn (Request $request): RedirectResponse => redirect()->to(Str::beforeLast($request->url(), '/invitations')))
                ->name('team.invitations.index');
            Route::post('team/invitations', StoreInvitationController::class)->name('team.invitations.store');
            Route::delete('team/invitations/{invitation}', DestroyInvitationController::class)->name('team.invitations.destroy');
        }
    });
});
