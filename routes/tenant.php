<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Features\Audit\ActivityLogFeature;
use Nvade\Numerosis\Features\Billing\UsageMeteringFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Http\Controllers\Admin\EndImpersonationController;
use Nvade\Numerosis\Http\Controllers\Admin\RedeemImpersonationController;
use Nvade\Numerosis\Http\Controllers\Invitations\DestroyInvitationController;
use Nvade\Numerosis\Http\Controllers\Invitations\StoreInvitationController;
use Nvade\Numerosis\Http\Controllers\Team\AcceptRetentionOfferController;
use Nvade\Numerosis\Http\Controllers\Team\CloseTenantController;
use Nvade\Numerosis\Http\Controllers\Team\DestroyMemberController;
use Nvade\Numerosis\Http\Controllers\Team\DestroyOwnershipNominationController;
use Nvade\Numerosis\Http\Controllers\Team\ExportTenantDataController;
use Nvade\Numerosis\Http\Controllers\Team\ReopenTenantController;
use Nvade\Numerosis\Http\Controllers\Team\StoreOwnershipNominationController;
use Nvade\Numerosis\Http\Controllers\Team\UpdateMemberRoleController;
use Nvade\Numerosis\Http\Controllers\Team\UpdateTwoFactorRequirementController;

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

// Outside every auth group: the staff user redeeming this has no tenant
// session yet, which is the whole point. The 128-character single-use token
// with its own TTL is the secret.
if (FeatureRegistry::enabled(ImpersonationFeature::NAME)) {
    Route::get('impersonate/{token}', RedeemImpersonationController::class)
        ->middleware('throttle:10,1')
        ->name('impersonate.redeem');

    Route::post('impersonate/exit', EndImpersonationController::class)->name('impersonate.exit');
}

// `tenancy.auth`, not `auth`: Laravel's Authenticate plus the central-to-tenant
// session promotion, so a central user who may access this tenant is signed in
// on the tenant guard on the way through.
Route::middleware(['universal', MiddlewareAlias::TenancyAuth->value.':'.Context::Tenant->guard()])->group(function () {
    // Outside the `tenancy.subscription` group below, which is why that gate
    // is nested rather than a fourth entry in the `tenant` group: it is where
    // EnsureTenantSubscriptionActive redirects to.
    Route::livewire('account-suspended', 'numerosis-pages::tenant.suspended')
        ->name('tenant.suspended');

    // Outside the gate for the same reason, and the reopen with it: the owner
    // undoing a closure has to reach it while the closure is still in force.
    Route::livewire('account-closed', 'numerosis-pages::tenant.closed')
        ->name('tenant.closed');

    Route::post('account-closed/reopen', ReopenTenantController::class)->name('tenant.reopen');

    // The subscription gate: every authenticated tenant screen that is the
    // product itself. Fortify's own screens load outside it, since a suspended
    // tenant still has to verify an address.
    Route::middleware([MiddlewareAlias::TenancySubscription->value, MiddlewareAlias::TenancyMembership->value])->group(function () {
        // Outside the enrolment gate below, and the team screen with it: an
        // owner who let the grace period lapse has to be able to reach the
        // switch that turned the requirement on.
        Route::livewire('team', 'numerosis-pages::tenant.team')->name('team.index');

        Route::patch('team/two-factor', UpdateTwoFactorRequirementController::class)
            ->name('team.two-factor.update');

        // The enrolment gate: the product itself, for a tenant whose owner
        // requires a second factor of every member.
        Route::middleware(MiddlewareAlias::TenancyTwoFactor->value)->group(function () {
            Route::patch('team/members/{membership}', UpdateMemberRoleController::class)->name('team.members.update');
            Route::delete('team/members/{membership}', DestroyMemberController::class)->name('team.members.destroy');

            Route::post('team/ownership', StoreOwnershipNominationController::class)->name('team.ownership.store');

            Route::post('team/close', CloseTenantController::class)->name('team.close');

            Route::post('team/close/retention-offer', AcceptRetentionOfferController::class)
                ->name('team.retention-offer.accept');

            Route::post('team/export', ExportTenantDataController::class)->name('team.export');

            if (FeatureRegistry::enabled(ActivityLogFeature::NAME)) {
                Route::livewire('team/activity', 'numerosis-pages::tenant.activity')->name('team.activity');
            }

            // Only under custom-domain mode: there is nothing to claim when a
            // tenant is identified by subdomain or by path.
            if (IdentificationMode::current() === IdentificationMode::CustomDomain) {
                Route::livewire('domain', 'numerosis-pages::tenant.domain')->name('domain.index');
            }

            Route::livewire('api-tokens', 'numerosis-pages::tenant.api-tokens')->name('api-tokens.index');

            if (FeatureRegistry::enabled(UsageMeteringFeature::NAME)) {
                Route::livewire('usage', 'numerosis-pages::tenant.usage')->name('usage.index');
            }

            Route::delete('team/ownership/{nomination}', DestroyOwnershipNominationController::class)
                ->name('team.ownership.destroy');

            if (FeatureRegistry::enabled(InvitationsFeature::NAME)) {
                // The invitations screen folded into `team.index`; the name
                // stays because a host may link it. `Str::beforeLast` on the
                // current URL, not `route()`, which throws in path mode.
                Route::get('team/invitations', fn (Request $request): RedirectResponse => redirect()->to(Str::beforeLast($request->url(), '/invitations')))
                    ->name('team.invitations.index');
                Route::post('team/invitations', StoreInvitationController::class)->name('team.invitations.store');
                Route::delete('team/invitations/{invitation}', DestroyInvitationController::class)->name('team.invitations.destroy');
            }
        });
    });
});
