<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Http\Controllers\Invitations\DestroyInvitationController;
use Nvade\Numerosis\Http\Controllers\Invitations\StoreInvitationController;
use Nvade\Numerosis\Support\Features;

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

// The tenant domain's landing page.
//
// Registered here since the Filament tenant panel was deleted: it owned `/`
// on every tenant domain, so without this a tenant subdomain (or path prefix,
// or custom domain) 404s at its own root, and `tenant_route($domain,
// RouteNames::home())` — which Socialite\Login and CompleteRedirectCheckout
// both generate — points at nothing.
//
// Same view and the same `numerosis.routes.home_view` seam as the central
// `home` route, and deliberately unauthenticated: this is the page an
// unauthenticated visitor to a tenant lands on, and it links to `login`.
Route::get('/', fn () => view(Config::string('numerosis.routes.home_view')))
    ->name('tenant.home');

// `verification.notice` and `password.confirm` used to be contributed by
// nvade/numerosis-auth-ui through Numerosis::addTenantRoutes(). That package
// folded into core in Phase 3 of `.claude/plans/archive/humming-nibbling-flame.md`
// and its Livewire screens were deleted rather than moved; both are Laravel
// Fortify's since Phase 4, loaded by `Support\Numerosis::routes()` inside
// this same tenant group (after this file, not inside it — see
// `loadFortifyRoutes()`), not declared here.
//
// `tenancy.auth` rather than `auth`: it is Laravel's Authenticate plus the
// central→tenant session promotion, so a central user who may access this
// tenant is signed in on the tenant guard on the way through instead of being
// bounced to a login screen they do not need.
Route::middleware(['universal', 'tenancy.auth:'.Config::string('numerosis.auth.guards.tenant')])->group(function () {
    // Deliberately outside the `tenancy.subscription` group below, and the
    // reason that gate is a nested group rather than a fourth entry in the
    // `tenant` middleware group: this is where EnsureTenantSubscriptionActive
    // redirects to, so a suspended tenant reaching it must not be re-gated
    // into an infinite redirect.
    Route::livewire('account-suspended', 'pages::tenant.suspended')
        ->name('tenant.suspended');

    // The subscription gate: every authenticated tenant screen that is the
    // product itself, as opposed to the auth plumbing needed to reach it.
    //
    // Empty today, deliberately. `packages/filament`'s tenant panel was the
    // whole authenticated tenant surface and it was deleted in Phase 1 of
    // `.claude/plans/archive/humming-nibbling-flame.md`; Phase 3 moves real screens
    // in here. The group exists now so the gate is wired and tested rather
    // than rediscovered later — the panel owned the only registration of
    // EnsureTenantSubscriptionActive, so deleting it silently switched
    // suspension enforcement off with every unit test still green.
    //
    // `verification.notice` and `password.confirm`, loaded by
    // `loadFortifyRoutes()` after this group closes, are outside this gate
    // for the same reason: a suspended tenant's user still has to be able to
    // verify an address and confirm a password.
    Route::middleware('tenancy.subscription')->group(function () {
        if (Features::enabled(InvitationsFeature::NAME)) {
            Route::livewire('team/invitations', 'pages::tenant.invitations')->name('team.invitations.index');
            Route::post('team/invitations', StoreInvitationController::class)->name('team.invitations.store');
            Route::delete('team/invitations/{invitation}', DestroyInvitationController::class)->name('team.invitations.destroy');
        }
    });
});
